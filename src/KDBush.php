<?php

namespace Datashaman\Supercluster;

use Ds\Stack;
use Ds\Vector;
use Opis\Closure\SerializableClosure;

class KDBush
{
    public Vector $ids;
    public Vector $coords;

    public function __construct(
        public Vector $points,
        protected SerializableClosure|null $getX,
        protected SerializableClosure|null $getY,
        protected int $nodeSize = 64
    ) {
        $getX ??= new SerializableClosure(fn ($p) => $p[0]);
        $getY ??= new SerializableClosure(fn ($p) => $p[1]);

        $countPoints = count($points);

        $this->ids = new Vector(array_fill(0, count($points), 0));
        $this->coords = new Vector(array_fill(0, 2 * count($points), 0));

        for ($i = 0; $i < $countPoints; $i++) {
            $this->ids->set($i, $i);
            $this->coords->set(2 * $i, $getX($points[$i]));
            $this->coords->set(2 * $i + 1, $getY($points[$i]));
        }

        KDSort::sort(
            $this->ids,
            $this->coords,
            $this->nodeSize,
            0,
            count($this->ids) - 1,
            0
        );
    }

    public function range(
        float $minX,
        float $minY,
        float $maxX,
        float $maxY
    ): Vector {
        $stack = new Stack([0, count($this->ids) - 1, 0]);
        $result = new Vector();

        while (!$stack->isEmpty()) {
            $axis = $stack->pop();
            $right = $stack->pop();
            $left = $stack->pop();

            if ($right - $left <= $this->nodeSize) {
                for ($i = $left; $i <= $right; $i++) {
                    $x = $this->coords->get(2 * $i);
                    $y = $this->coords->get(2 * $i + 1);
                    if ($x >= $minX && $x <= $maxX && $y >= $minY && $y <= $maxY) {
                        $result->push($this->ids->get($i));
                    }
                }

                continue;
            }

            $m = ($left + $right) >> 1;

            $x = $this->coords->get(2 * $m);
            $y = $this->coords->get(2 * $m + 1);

            if ($x >= $minX && $x <= $maxX && $y >= $minY && $y <= $maxY) {
                $result->push($this->ids->get($m));
            }

            if ($axis === 0 ? $minX <= $x : $minY <= $y) {
                $stack->push($left, $m - 1, 1 - $axis);
            }

            if ($axis === 0 ? $maxX >= $x : $maxY >= $y) {
                $stack->push($m + 1, $right, 1 - $axis);
            }
        }

        return $result;
    }

    public function within(
        float $qx,
        float $qy,
        float $r
    ): Vector {
        $stack = new Stack([0, count($this->ids) - 1, 0]);
        $result = new Vector();
        $r2 = $r * $r;

        while (!$stack->isEmpty()) {
            $axis = $stack->pop();
            $right = $stack->pop();
            $left = $stack->pop();

            if ($right - $left <= $this->nodeSize) {
                for ($i = $left; $i <= $right; $i++) {
                    if ($this->sqDist($this->coords[2 * $i], $this->coords[2 * $i + 1], $qx, $qy) <= $r2) {
                        $result->push($this->ids[$i]);
                    }
                }

                continue;
            }

            $m = ($left + $right) >> 1;

            $x = $this->coords->get(2 * $m);
            $y = $this->coords->get(2 * $m + 1);

            $distance = $this->sqDist($x, $y, $qx, $qy);

            if ($distance <= $r2) {
                $result->push($this->ids->get($m));
            }

            if ($axis === 0 ? ($qx - $r <= $x) : ($qy - $r <= $y)) {
                $stack->push($left, $m - 1, 1 - $axis);
            }

            if ($axis === 0 ? ($qx + $r >= $x) : ($qy + $r >= $y)) {
                $stack->push($m + 1, $right, 1 - $axis);
            }
        }

        return $result;
    }

    protected function sqDist(
        float $ax,
        float $ay,
        float $bx,
        float $by
    ): float {
        $dx = $ax - $bx;
        $dy = $ay - $by;

        $distance = $dx * $dx + $dy * $dy;

        return $distance;
    }
}
