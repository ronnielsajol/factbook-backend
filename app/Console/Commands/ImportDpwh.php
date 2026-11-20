<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class ImportDpwh extends Command
{
    protected $signature = 'factbook:import {--batch=250 : Rows per transaction}';
    protected $description = 'Import or resume the frozen SQLite snapshot into a dedicated MySQL database';
    private PDO $source;
    private string $hash;

    public function handle(): int
    {
        if (PHP_INT_SIZE !== 8) throw new RuntimeException('A 64-bit PHP runtime is required.');
        $path = storage_path('app/imports/city-dashboard.sqlite');
        $this->hash = hash_file('sha256', $path);
        $this->source = new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = DB::connection('mysql');
        if (!$db->selectOne("SELECT GET_LOCK('factbook_import', 0) AS acquired")->acquired) {
            $this->error('Another import is running.');
            return self::FAILURE;
        }
        try {
            $batch = max(1, min(1000, (int) $this->option('batch')));
            foreach (['dpwh_contracts', 'dpwh_details', 'dpwh_points', 'dpwh_contract_cities'] as $table) {
                $this->importTable($table, $batch);
            }
            $this->importSearch();
            $this->info('Import stages complete. Run factbook:verify before using the snapshot.');
            return self::SUCCESS;
        } finally {
            $db->select("SELECT RELEASE_LOCK('factbook_import')");
        }
    }

    private function progress(string $stage, array $tables): object
    {
        $progress = DB::table('dpwh_import_progress')->where('stage', $stage)->first();
        if ($progress) {
            if ($progress->source_hash !== $this->hash) throw new RuntimeException('Source fingerprint differs from the checkpoint.');
            return $progress;
        }
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) throw new RuntimeException("Refusing to overwrite populated $table without its source checkpoint.");
        }
        DB::table('dpwh_import_progress')->insert(['stage' => $stage, 'source_hash' => $this->hash, 'cursor' => 0]);
        return (object) ['cursor' => 0, 'complete' => false];
    }

    private function importTable(string $table, int $batch): void
    {
        $tables = $table === 'dpwh_contracts' ? [$table, 'dpwh_contract_provinces'] : [$table];
        $progress = $this->progress($table, $tables);
        if ($progress->complete) return;
        $cursor = (int) $progress->cursor;
        $statement = $this->source->prepare("SELECT rowid AS import_rowid, * FROM $table WHERE rowid > ? ORDER BY rowid LIMIT ?");
        while (true) {
            $statement->bindValue(1, $cursor, PDO::PARAM_INT);
            $statement->bindValue(2, $batch, PDO::PARAM_INT);
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;
            DB::transaction(function () use ($rows, $table, &$cursor) {
                $records = [];
                $provinces = [];
                foreach ($rows as $row) {
                    $cursor = (int) $row['import_rowid'];
                    unset($row['import_rowid']);
                    if ($table === 'dpwh_contract_cities') $row['sequence'] = $cursor;
                    if ($table === 'dpwh_contracts') {
                        foreach (array_unique(json_decode($row['provinces'], true, flags: JSON_THROW_ON_ERROR)) as $province) {
                            $provinces[] = ['contract_id' => $row['id'], 'province' => $province];
                        }
                    }
                    $records[] = $row;
                }
                DB::table($table)->insert($records);
                if ($provinces) DB::table('dpwh_contract_provinces')->insert($provinces);
                DB::table('dpwh_import_progress')->where('stage', $table)->update(['cursor' => $cursor]);
            });
        }
        DB::table('dpwh_import_progress')->where('stage', $table)->update(['complete' => true]);
        $this->info("$table complete at source row $cursor");
    }

    private function importSearch(): void
    {
        $stage = 'search';
        $progress = $this->progress($stage, ['dpwh_search_terms', 'dpwh_search_memberships']);
        if ($progress->complete) return;
        $this->source->exec("CREATE VIRTUAL TABLE temp.terms USING fts5vocab(main, dpwh_search, row)");
        $this->source->exec("CREATE VIRTUAL TABLE temp.instances USING fts5vocab(main, dpwh_search, instance)");
        $cursor = (int) $progress->cursor;
        $last = DB::table('dpwh_search_terms')->where('term_id', $cursor)->value('term') ?? '';
        $words = $this->source->prepare('SELECT term FROM temp.terms WHERE term > ? ORDER BY term');
        $words->execute([$last]);
        $members = $this->source->prepare('SELECT DISTINCT s.c0 AS contract_id FROM temp.instances v JOIN dpwh_search_content s ON s.id=v.doc WHERE v.term=?');
        while (true) {
            $terms = [];
            while (count($terms) < 100 && ($term = $words->fetchColumn()) !== false) $terms[] = $term;
            if (!$terms) break;
            DB::transaction(function () use ($terms, &$cursor, $members, $stage) {
                foreach ($terms as $term) {
                    if (strlen($term) > 768) throw new RuntimeException('Search term exceeds schema capacity.');
                    $cursor++;
                    DB::table('dpwh_search_terms')->insert(['term_id' => $cursor, 'term' => $term]);
                    $members->execute([$term]);
                    $batch = [];
                    while (($id = $members->fetchColumn()) !== false) {
                        $batch[] = ['term_id' => $cursor, 'contract_id' => $id];
                        if (count($batch) === 500) {
                            DB::table('dpwh_search_memberships')->insert($batch);
                            $batch = [];
                        }
                    }
                    if ($batch) DB::table('dpwh_search_memberships')->insert($batch);
                }
                DB::table('dpwh_import_progress')->where('stage', $stage)->update(['cursor' => $cursor]);
            });
            if ($cursor % 5000 < 100) $this->info("Search terms imported: $cursor");
        }
        DB::table('dpwh_import_progress')->where('stage', $stage)->update(['complete' => true]);
        $this->info("Search complete: $cursor terms");
    }
}

