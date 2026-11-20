<?php
namespace App\Http\Controllers;

use App\Services\DpwhQueries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DpwhController
{
    public function __invoke(Request $request, string $endpoint)
    {
        $respond = static fn ($value, $status = 200) => response()->json($value, $status, [
            'Cache-Control' => $status < 400 ? 'private, max-age=300' : 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $key = config('factbook.api_key');
        if (!app()->environment('local', 'testing') || $key) {
            if (!$key || !hash_equals($key, (string)$request->header('X-Factbook-Key'))) {
                return $respond(['error' => 'Access denied.'], 403);
            }
        }
        if (!$request->isMethod('GET')) return $respond(['error' => 'Only GET is supported.'], 405);
        $params = [];
        foreach (explode('&', $request->server('QUERY_STRING', '')) as $part) {
            [$keyName, $value] = array_pad(explode('=', $part, 2), 2, '');
            $keyName = urldecode($keyName);
            if (!array_key_exists($keyName, $params)) $params[$keyName] = urldecode($value);
        }
        $metadata = static fn ($name) => json_decode(file_get_contents(storage_path("app/factbook/metadata/dpwh-$name.json")), true, flags: JSON_THROW_ON_ERROR);
        try {
            $snapshot = $metadata('dashboard');
            if ($endpoint === 'summary') {
                $alias = static fn ($r) => $r === 'Region IV-B' ? 'MIMAROPA Region' : $r;
                $snapshot['regions'] = array_values(array_unique(array_map($alias, $snapshot['regions'])));
                foreach ($snapshot['cells'] as &$cell) $cell[1] = $alias($cell[1]);
                return $respond($snapshot);
            }
            $options = $metadata('map-options');
            if ($endpoint === 'map-options') return $respond($options);
            $cities = $metadata('city-options');
            $region = $params['region'] ?? null;
            $province = $params['province'] ?? null;
            if ($endpoint === 'city-options') {
                $rows = array_filter($cities, static fn ($city) =>
                    ($city['region'] === $region || ($region === 'Region IV-B' && $city['region'] === 'MIMAROPA Region'))
                    && (!$province || $province === 'All' || in_array($city['kind'], ['HUC','ICC'], true) || $city['province'] === $province));
                return $respond(['cities' => array_values($rows)]);
            }
            try { DB::connection('mysql')->getPdo(); }
            catch (\Throwable $error) { report($error); return $respond(['error' => 'The project database is not available yet.'], 503); }
            $stages = DB::table('dpwh_import_progress')->get();
            if ($stages->count() !== 5 || $stages->contains(fn ($stage) => !$stage->complete)) {
                return $respond(['error' => 'The project database is not available yet.'], 503);
            }
            $queries = new DpwhQueries($snapshot, $options, $cities);
            return $respond($queries->execute($endpoint, $params));
        } catch (\InvalidArgumentException $error) {
            return $respond(['error' => $error->getMessage()], 400);
        } catch (\OutOfBoundsException $error) {
            return $respond(['error' => $error->getMessage()], 404);
        } catch (\Throwable $error) {
            report($error);
            return $respond(['error' => 'Project data could not be loaded. Please try again.'], 503);
        }
    }
}
