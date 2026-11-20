<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$metadata = fn ($name) => json_decode(file_get_contents(storage_path("app/factbook/metadata/dpwh-$name.json")), true);
$service = new App\Services\DpwhQueries($metadata('dashboard'), $metadata('map-options'), $metadata('city-options'));
$db = Illuminate\Support\Facades\DB::connection();
$report = [];
foreach ([['contracts', ['q' => 'flood control']], ['contracts', ['q' => 'a']], ['map', ['zoom' => '8', 'province' => 'CAVITE']]] as [$endpoint, $params]) {
    $db->enableQueryLog();
    $started = microtime(true);
    $service->execute($endpoint, $params);
    $elapsed = microtime(true) - $started;
    $queries = $db->getQueryLog();
    $db->disableQueryLog();
    $db->flushQueryLog();
    $plans = [];
    foreach ($queries as $query) $plans[] = $db->select('EXPLAIN '.$query['query'], $query['bindings']);
    $report[] = ['endpoint' => $endpoint, 'params' => $params, 'seconds' => $elapsed, 'plans' => $plans];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

