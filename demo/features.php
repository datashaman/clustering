<?php

require_once '../vendor/autoload.php';

use Datashaman\Supercluster\Index;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

function getIndex(): Index {
    $params = [
        'extent' => (int) ($_GET['extent'] ?? 256),
        'log' => ($_GET['log'] ?? false) === 'true',
        'maxZoom' => (int) ($_GET['maxZoom'] ?? 17),
        'radius' => (int) ($_GET['radius'] ?? 60),
    ];

    $index = new Index($params);

    $logger = getLogger();
    $index->setLogger($logger);

    $geojson = json_decode(file_get_contents('../tests/fixtures/places.json'), true);
    $index->load($geojson['features']);

    $logger->debug('Index initialized');

    return $index;
}

function getLogger(): Logger {
    $handler = new StreamHandler('demo.log', Level::Debug);
    $handler->setFormatter(new JsonFormatter());

    $logger = new Logger('demo');
    $logger->pushHandler($handler);

    return $logger;
}

$zoom = (int) $_GET['zoom'];
$bbox = explode(',', $_GET['bbox']);

$index = getIndex();
$clusters = $index->getClusters($zoom, $bbox);

header('Content-Type', 'application/json');

echo json_encode([
    'type' => 'FeatureCollection',
    'features' => $clusters,
]);
