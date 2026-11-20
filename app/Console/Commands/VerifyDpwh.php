<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class VerifyDpwh extends Command
{
    protected $signature = 'factbook:verify';
    protected $description = 'Compare every imported source field and reconcile snapshot totals';
    public function handle(): int
    {
        $path = storage_path('app/imports/city-dashboard.sqlite');
        $source = new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro');
        $hash = hash_file('sha256', $path);
        $stages = DB::table('dpwh_import_progress')->get();
        if ($stages->count() !== 5 || $stages->contains(fn ($r) => !$r->complete || $r->source_hash !== $hash)) {
            throw new RuntimeException('Import incomplete or source fingerprint changed.');
        }
        foreach (['dpwh_contracts' => 'id', 'dpwh_details' => 'id', 'dpwh_points' => 'point_id', 'dpwh_contract_cities' => 'sequence'] as $table => $key) {
            $columns = array_column($source->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            $select = $key === 'sequence' ? 'rowid AS sequence, *' : '*';
            $order = $key === 'sequence' ? 'rowid' : $key;
            $statement = $source->query("SELECT $select FROM $table ORDER BY $order");
            $count = 0;
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $batch = [$row];
                while (count($batch) < 100 && ($next = $statement->fetch(PDO::FETCH_ASSOC))) $batch[] = $next;
                $target = DB::table($table)->whereIn($key, array_column($batch, $key))->get()->keyBy($key);
                foreach ($batch as $expected) {
                    $actual = $target->get($expected[$key]);
                    if (!$actual) throw new RuntimeException("Missing $table row ".$expected[$key]);
                    foreach ($columns as $column) {
                        $same = is_float($expected[$column])
                            ? (float) $actual->$column === $expected[$column]
                            : ($expected[$column] === null ? $actual->$column === null : (string) $actual->$column === (string) $expected[$column]);
                        if (!$same) throw new RuntimeException("Mismatch $table / ".$expected[$key]." / $column");
                    }
                }
                $count += count($batch);
            }
            if (DB::table($table)->count() !== $count) throw new RuntimeException("Extra rows in $table");
            $this->info("$table: $count rows, all source fields identical");
        }
        $totals = DB::table('dpwh_contracts')->selectRaw('COUNT(*) AS contracts, SUM(mapped) AS mapped, SUM(budget_cents) AS budget')->first();
        if ((int) $totals->contracts !== 264864 || (int) $totals->mapped !== 215728 || (int) $totals->budget !== 651168509291278) throw new RuntimeException('Promoted totals differ.');
        $source->exec("CREATE VIRTUAL TABLE temp.vocabulary USING fts5vocab(main, dpwh_search, row)");
        if (DB::table('dpwh_search_terms')->count() !== (int) $source->query('SELECT COUNT(*) FROM temp.vocabulary')->fetchColumn()) throw new RuntimeException('Search vocabulary count differs.');
        $size = DB::selectOne("SELECT SUM(data_length) AS data_bytes, SUM(index_length) AS index_bytes FROM information_schema.tables WHERE table_schema=DATABASE()");
        $this->info(json_encode(['source_sha256' => $hash, 'totals' => $totals, 'mysql_size' => $size], JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}

