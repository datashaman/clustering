<?php

namespace Datashaman\Supercluster;

use ArrayObject;
use Ds\Map;
use Ds\Vector;
use Exception;
use Opis\Closure\SerializableClosure;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SebastianBergmann\Timer\Timer;

class Index implements LoggerAwareInterface
{
    protected ArrayObject $options;
    protected LoggerInterface $logger;
    protected Vector $points;

    protected Map $trees;
    protected Map $timers;

    public function __construct(array $options = [])
    {
        $this->logger = new NullLogger();

        $defaultOptions = [
            'extent' => 512,  // tile extent (radius is calculated relative to it)
            'generateId' => false, // whether to generate numeric ids for input features (in vector tiles)
            'log' => false,   // whether to log timing info
            'map' => fn ($props) => $props, // callable for properties to use for individual points when running the reduce
            'maxZoom' => 16,  // max zoom level to cluster the points on
            'minPoints' => 2, // minimum points to form a cluster
            'minZoom' => 0,   // min zoom to generate clusters on
            'nodeSize' => 64, // size of the KD-tree leaf node, affects performance
            'radius' => 40,   // cluster radius in pixels
            'reduce' => null, // a reduce SerializableClosure for calculating custom cluster properties
        ];

        $this->options = new ArrayObject(array_merge(
            $defaultOptions,
            $options
        ), ArrayObject::ARRAY_AS_PROPS);

        $this->options['map'] = new SerializableClosure($this->options['map']);

        if ($this->options['reduce']) {
            $this->options['reduce'] = new SerializableClosure($this->options['reduce']);
        }

        $this->trees = new Map();
        $this->timers = new Map();
    }

    public function load(array $points): self
    {
        if ($this->options->log) {
            $this->startTimer('total time');
        }

        $countPoints = count($points);
        $timerId = "prepare {$countPoints} points";

        if ($this->options->log) {
            $this->startTimer($timerId);
        }

        $this->points = new Vector(array_map(
            fn ($point) => new Map($point),
            $points
        ));

        $clusters = new Vector();

        foreach ($this->points as $index => $point) {
            $geometry = $point['geometry'] ?? null;
            if (! $geometry) {
                continue;
            }
            $clusters->push($this->createPointCluster($point, $index));
        }

        $getX = new SerializableClosure(fn ($p) => $p['x']);
        $getY = new SerializableClosure(fn ($p) => $p['y']);

        $tree = $this->trees[$this->options->maxZoom + 1] = new KDBush(
            $clusters,
            $getX,
            $getY,
            $this->options->nodeSize
        );

        if ($this->options->log) {
            $this->endTimer($timerId);
        }

        for ($z = $this->options->maxZoom; $z >= $this->options->minZoom; $z--) {
            $this->startTimer("z{$z}");

            $clusters = $this->clusterPoints($clusters, $z);

            $this->trees[$z] = new KDBush(
                $clusters,
                $getX,
                $getY,
                $this->options->nodeSize
            );

            if ($this->options->log) {
                $countClusters = count($clusters);

                $this->endTimer(
                    "z{$z}",
                    "{$countClusters} clusters"
                );
            }
        }

        if ($this->options->log) {
            $this->endTimer('total time');
        }

        return $this;
    }

    public function getChildren(int $clusterId)
    {
        $originId = $this->getOriginId($clusterId);
        $originZoom = $this->getOriginZoom($clusterId);

        $index = $this->getTree($originZoom, false);
        if (! $index) {
            throw new Exception("Index not found for {$originZoom}");
        }

        $origin = $index->points[$originId];
        if (! $origin) {
            throw new Exception("No cluster with the specificed ID {$originId}");
        }

        $r = $this->options->radius / ($this->options->extent * pow(2, $originZoom - 1));
        $ids = $index->within($origin['x'], $origin['y'], $r);

        $children = [];
        foreach ($ids as $id) {
            $c = $index->points[$id];
            if ($c['parentId'] === $clusterId) {
                $children[] = ((bool) $c->get('numPoints', false))
                    ? $this->getClusterJSON($c)
                    : $this->points[$c['index']];
            }
        }

        if (! $children) {
            throw new Exception("Cluster has no children");
        }

        return $children;
    }

    public function getClusters(int $zoom, array $boundingBox): array
    {
        $minLng = fmod(fmod($boundingBox[0] + 180, 360) + 360, 360) - 180;
        $minLat = max(-90, min(90, $boundingBox[1]));
        $maxLng = $boundingBox[2] === 180 ? 180 : fmod(fmod($boundingBox[2] + 180, 360) + 360, 360) - 180;
        $maxLat = max(-90, min(90, $boundingBox[3]));

        if ($boundingBox[2] - $boundingBox[0] >= 360) {
            $minLng = -180;
            $maxLng = 180;
        } else if ($minLng > $maxLng) {
            $easternHem = $this->getClusters([$minLng, $minLat, 180, $maxLat], $zoom);
            $westernHem = $this->getClusters([-180, $minLat, $maxLng, $maxLat], $zoom);

            return array_merge(
                $easternHem,
                $westernHem
            );
        }

        $tree = $this->trees->get($zoom);

        $ids = $tree->range(
            $this->lngX($minLng),
            $this->latY($maxLat),
            $this->lngX($maxLng),
            $this->latY($minLat)
        );

        $clusters = [];

        foreach ($ids as $id) {
            $c = $tree->points[$id];
            $clusters[] = ($c['numPoints'] ?? false) ? $this->getClusterJSON($c) : $this->points[$c['index']];
        }

        return $clusters;
    }

    public function getClusterExpansionZoom(int $clusterId): int
    {
        $expansionZoom = $this->getOriginZoom($clusterId) - 1;

        while ($expansionZoom <= $this->options->maxZoom) {
            $children = $this->getChildren($clusterId);
            $expansionZoom++;

            if (count($children) !== 1) {
                break;
            }

            $clusterId = $children[0]['properties']['cluster_id'];
        }

        return $expansionZoom;
    }

    public function getLeaves(int $clusterId, int $limit = 10, int $offset = 0)
    {
        $leaves = new Vector();
        $this->appendLeaves($leaves, $clusterId, $limit, $offset, 0);

        return $leaves;
    }

    public function getTile(int $z, int $x, int $y): array|null
    {
        $tree = $this->getTree($z);
        $z2 = pow(2, $z);
        $p = $this->options->radius / $this->options->extent;
        $top = ($y - $p) / $z2;
        $bottom = ($y + 1 + $p) / $z2;

        $tile = [
            'features' => [],
        ];

        $this->addTileFeatures(
            $tree->range(($x - $p) / $z2, $top, ($x + 1 + $p) / $z2, $bottom),
            $tree->points,
            $x,
            $y, 
            $z2, 
            $tile
        );

        if ($x === 0) {
            $this->addTileFeatures(
                $tree->range(1 - $p / $z2, $top, 1, $bottom),
                $tree->points,
                $z2,
                $y, 
                $z2, 
                $tile
            );
        }
        if ($x === $z2 - 1) {
            $this->addTileFeatures(
                $tree->range(0, $top, $p / $z2, $bottom),
                $tree->points,
                -1, 
                $y, 
                $z2, 
                $tile
            );
        }

        return $tile['features'] ? $tile : null;
    }

    public function getTree(int $zoom, bool $limit = true): KDBush
    {
        if ($limit) {
            $zoom = max(
                $this->options->minZoom,
                min(
                    (int) floor($zoom),
                    $this->options->maxZoom + 1
                )
            );
        }

        return $this->trees->get($zoom);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function appendLeaves(
        Vector $result,
        int $clusterId, 
        int $limit, 
        int $offset, 
        int $skipped
    ) {
        $children = $this->getChildren($clusterId);

        foreach ($children as $child) {
            $props = $child['properties'];

            if ($props && $props['cluster']) {
                if ($skipped + $props['point_count'] <= $offset) {
                    // skip the whole cluster
                    $skipped += $props['point_count'];
                } else {
                    // enter the cluster
                    $skipped = $this->appendLeaves(
                        $result,
                        $props['cluster_id'],
                        $limit,
                        $offset, 
                        $skipped
                    );
                    // exit the cluster
                }
            } else if ($skipped < $offset) {
                // skip a single point
                $skipped++;
            } else {
                // add a single point
                $result[] = $child;
            }

            if (count($result) === $limit) {
                break;
            }
        }

        return $skipped;
    }

    protected function getOriginId(int $clusterId)
    {
        return ($clusterId - count($this->points)) >> 5;
    }

    protected function getOriginZoom(int $clusterId)
    {
        return ($clusterId - count($this->points)) % 32;
    }

    protected function startTimer(string $id): void
    {
        $timer = $this->timers[$id] = new Timer();
        $timer->start();
    }

    protected function endTimer(string $id, string $message = ''): void
    {
        $duration = $this->timers[$id]->stop();
        $ms = $duration->asMilliseconds();

        if ($message) {
            $message = "Timer: {$id} {$message} took {$ms}ms";
        } else {
            $message = "Timer: {$id} took {$ms}ms";
        }

        $this->logger->debug($message);

        unset($this->timers[$id]);
    }

    protected function lngX($lng): float
    {
        return $lng / 360 + 0.5;
    }

    protected function latY($lat): float
    {
        $sin = sin($lat * M_PI / 180);
        $y = 0.5 - 0.25 * log((1 + $sin) / (1 - $sin)) / M_PI;

        return $y < 0 ? 0
            : ($y > 1 ? 1 : $y);
    }

    protected function xLng(float $x)
    {
        return ($x - 0.5) * 360;
    }

    protected function yLat(float $y) {
        $y2 = (180 - $y * 360) * M_PI / 180;

        return 360 * atan(exp($y2)) / M_PI - 90;
    }

    protected function addTileFeatures(
        Vector $ids,
        Vector $points,
        int $x,
        int $y,
        int $z2, 
        array &$tile
    ) {
        foreach ($ids as $i) {
            $c = $points->get($i);

            $isCluster = (bool) $c->get('numPoints', false);

            if ($isCluster) {
                $tags = $this->getClusterProperties($c);
                $px = $c['x'];
                $py = $c['y'];
            } else {
                $p = $this->points[$c['index']];
                $tags = $p['properties'];
                $px = $this->lngX($p['geometry']['coordinates'][0]);
                $py = $this->latY($p['geometry']['coordinates'][1]);
            }

            $f = [
                'type' => 1,
                'geometry' => [[
                    (int) round($this->options->extent * ($px * $z2 - $x)),
                    (int) round($this->options->extent * ($py * $z2 - $y)),
                ]],
                'tags' => $tags,
            ];

            $id = null;

            if ($isCluster) {
                $id = $c['id'];
            } elseif ($this->options['generateId']) {
                $id = $c['index'];
            } elseif ($this->points[$c['index']]->hasKey('id')) {
                $id = $this->points[$c['index']]['id'];
            }

            if (! is_null($id)) {
                $f['id'] = $id;
            }

            $tile['features'][] = $f;
        }
    }

    protected function createPointCluster(
        Map $point,
        int $index
    ): Map {
        [$x, $y] = $point['geometry']['coordinates'];

        return new Map([
            'x' => $this->lngX($x),
            'y' => $this->latY($y),
            'zoom' => INF, // the last zoom the point was processed at
            'index' => $index, // index of the source feature in the original input array,
            'parentId' => -1, // parent cluster id
        ]);
    }

    protected function getClusterProperties(Map $cluster)
    {
        $count = $cluster['numPoints'];

        if ($count >= 10000) {
            $abbrev = round($count / 1000) . 'k';
        } elseif ($count >= 1000) {
            $abbrev = round($count / 100) * 10 . 'k';
        } else {
            $abbrev = $count;
        }

        return array_merge(
            $cluster['properties'],
            [
                'cluster' => true,
                'cluster_id' => $cluster['id'],
                'point_count' => $count,
                'point_count_abbreviated' => $abbrev,
            ]
        );
    }
    
    protected function clusterPoints(Vector $points, int $zoom): Vector
    {
        $clusters = new Vector();

        $r = $this->options->radius / ($this->options->extent * pow(2, $zoom));

        for ($i = 0; $i < count($points); $i++) {
            $p = $points[$i];

            if ($p['zoom'] <= $zoom) {
                continue;
            }

            $p['zoom'] = $zoom;

            $tree = $this->getTree($zoom + 1, false);
            $neighborIds = $tree->within($p['x'], $p['y'], $r);

            $numPointsOrigin = $p['numPoints'] ?? 1;
            $numPoints = $numPointsOrigin;

            foreach ($neighborIds as $neighborId) {
                $b = $tree->points[$neighborId];

                if ($b['zoom'] > $zoom) {
                    $numPoints += $b['numPoints'] ?? 1;
                }
            }

            if ($numPoints > $numPointsOrigin && $numPoints >= $this->options->minPoints) {
                $wx = $p['x'] * $numPointsOrigin;
                $wy = $p['y'] * $numPointsOrigin;

                $clusterProperties = ($this->options['reduce'] && $numPointsOrigin > 1)
                    ? $this->map($p, true)
                    : [];

                $id = ($i << 5) + ($zoom + 1) + count($this->points);

                foreach ($neighborIds as $neighborId) {
                    $b = $tree->points[$neighborId];

                    if ($b['zoom'] <= $zoom) {
                        continue;
                    }

                    $b['zoom'] = $zoom;

                    $numPoints2 = $b['numPoints'] ?? 1;
                    $wx += $b['x'] * $numPoints2;
                    $wy += $b['y'] * $numPoints2;

                    $b['parentId'] = $id;

                    if ($this->options['reduce']) {
                        if (! $clusterProperties) {
                            $clusterProperties = $this->map($p, true);
                        }
                        $this->options['reduce']($clusterProperties, $this->map($b));
                    }
                }

                $p['parentId'] = $id;

                $clusters[] = $this->createCluster(
                    $wx / $numPoints,
                    $wy / $numPoints,
                    $id,
                    $numPoints, 
                    $clusterProperties
                );
            } else {
                $clusters[] = $p;

                if ($numPoints > 1) {
                    foreach ($neighborIds as $neighborId) {
                        $b = $tree->points[$neighborId];
                        if ($b['zoom'] <= $zoom) {
                            continue;
                        }
                        $b['zoom'] = $zoom;
                        $clusters[] = $b;
                    }
                }
            }
        }

        return $clusters;
    }

    protected function createCluster(
        float $x,
        float $y,
        int $id,
        int $numPoints,
        array $properties
    ): Map {
        return new Map([
            'x' => $x,
            'y' => $y,
            'zoom' => INF,
            'id' => $id,
            'parentId' => -1,
            'numPoints' => $numPoints,
            'properties' => $properties,
        ]);
    }

    protected function getClusterJSON(Map $cluster): Map
    {
        return new Map([
            'type' => 'Feature',
            'id' => $cluster['id'],
            'properties' => $this->getClusterProperties($cluster),
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [
                    $this->xLng($cluster['x']),
                    $this->yLat($cluster['y']),
                ],
            ],
        ]);
    }

    protected function map(
        Map $point,
        bool $clone = false
    ): array {
        if ($point->get('numPoints', false)) {
            return $point['properties'];
        }

        $original = $this->points[$point['index']]['properties'];

        return array_map(
            $this->options['map'],
            $original
        );
    }

}
