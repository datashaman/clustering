<?php

namespace Datashaman\Supercluster\Tests;

use Datashaman\Supercluster\Index;

class IndexTest extends TestCase
{
    public function testGetClusters()
    {
        $index = $this->getIndexWithFeatures();
        $tile = $index->getTile(0, 0, 0);
        $this->assertEquals($this->getPlacesTile()['features'], $tile['features']);
    }

    public function testSupportsMinPointsOption()
    {
        $index = $this->getIndexWithFeatures(['minPoints' => 5]);
        $tile = $index->getTile(0, 0, 0);
        $this->assertEquals($this->getPlacesTileMin5()['features'], $tile['features']);
    }

    public function testReturnsChildrenOfACluster()
    {
        $index = $this->getIndexWithFeatures();
        $childCounts = array_map(
            fn ($p) => $p['properties']['point_count'] ?? 1,
            $index->getChildren(164)
        );
        $this->assertEquals([6, 7, 2, 1], $childCounts);
    }

    public function testReturnsLeavesOfACluster()
    {
        $index = $this->getIndexWithFeatures();
        $leafNames = $index
            ->getLeaves(164, 10, 5)
            ->map(fn ($p) => $p['properties']['name'])
            ->toArray();

        $this->assertEquals(
            [
                'Niagara Falls',
                'Cape San Blas',
                'Cape Sable',
                'Cape Canaveral',
                'San  Salvador',
                'Cabo Gracias a Dios',
                'I. de Cozumel',
                'Grand Cayman',
                'Miquelon',
                'Cape Bauld'
            ],
            $leafNames
        );
    }

    public function testGeneratesUniqueIDswithGenerateIdOption()
    {
        $index = $this->getIndexWithFeatures(['generateId' => true]);
        $features = $index->getTile(0, 0, 0)['features'];
        $ids = array_map(
            fn ($f) => $f['id'],
            array_filter(
                $features,
                fn ($f) => !$f['tags']['cluster']
            )
        );

        $this->assertEquals([12, 20, 21, 22, 24, 28, 30, 62, 81, 118, 119, 125, 81, 118], $ids);
    }

    public function testGetLeavesHandlesNullProperties()
    {
        $index = $this
            ->getIndex()
            ->load(
                array_merge(
                    $this->getPlaces()['features'],
                    [[
                        'type' => 'Feature',
                        'properties' => null,
                        'geometry' => [
                            'type' => 'Point',
                            'coordinates' => [-79.04411780507252, 43.08771393436908],
                        ],
                    ]],
                )
            );

        $leaves = $index->getLeaves(165, 1, 6);
        $this->assertNull($leaves[0]['properties']);
    }

    protected function getIndex($options = [])
    {
        $index = (new Index(['log' => true]));
        $index->setLogger($this->getLogger());

        return $index;
    }

    protected function getIndexWithFeatures($options = [])
    {
        $index = (new Index(['log' => true]));
        $index->setLogger($this->getLogger());
        $index->load($this->getPlaces()['features']);

        return $index;
    }

    protected function getPlaces(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/places.json'), true);
    }

    protected function getPlacesTile(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/places-z0-0-0.json'), true);
    }

    protected function getPlacesTileMin5(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/places-z0-0-0-min5.json'), true);
    }
}
