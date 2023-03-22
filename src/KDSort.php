<?php

namespace Datashaman\Supercluster;

use Ds\Vector;

class KDSort
{
    public static function sort(
        Vector $ids,
        Vector $coords,
        int $nodeSize,
        int $left,
        int $right,
        int $axis
    ): void {
        if ($right - $left <= $nodeSize) {
            return;
        }

        $m = ($left + $right) >> 1;

        static::select(
            $ids,
            $coords,
            $m,
            $left,
            $right,
            $axis
        );

        static::sort(
            $ids,
            $coords,
            $nodeSize,
            $left,
            $m - 1,
            1 - $axis
        );

        static::sort(
            $ids,
            $coords,
            $nodeSize,
            $m + 1,
            $right,
            1 - $axis
        );
    }

    public static function select(
        Vector $ids,
        Vector $coords,
        int $k,
        int $left,
        int $right,
        int $axis
    ): void {
        while ($right > $left) {
            if ($right - $left > 600) {
                $n = $right - $left + 1;
                $m = $k - $left + 1;
                $z = log($n);
                $s = 0.5 * exp(2 * $z / 3);
                $sd = 0.5 * sqrt($z * $s * ($n - $s) / $n) * ($m - $n / 2 < 0 ? -1 : 1);
                $newLeft = max($left, floor($k - $m * $s / $n + $sd));
                $newRight = min($right, floor($k + ($n - $m) * $s / $n + $sd));

                static::select(
                    $ids,
                    $coords,
                    $k,
                    $newLeft,
                    $newRight,
                    $axis
                );
            }

            $t = $coords[2 * $k + $axis];
            $i = $left;
            $j = $right;

            static::swapItem(
                $ids,
                $coords,
                $left,
                $k
            );

            if ($coords[2 * $right + $axis] > $t) {
                static::swapItem(
                    $ids,
                    $coords,
                    $left,
                    $right
                );
            }

            while ($i < $j) {
                static::swapItem(
                    $ids,
                    $coords,
                    $i,
                    $j
                );

                $i++;
                $j--;

                while ($coords[2 * $i + $axis] < $t) {
                    $i++;
                }

                while($coords[2 * $j + $axis] > $t) {
                    $j--;
                }
            }

            if ($coords[2 * $left + $axis] === $t) {
                static::swapItem(
                    $ids,
                    $coords,
                    $left,
                    $j
                );
            } else {
                $j++;

                static::swapItem(
                    $ids,
                    $coords,
                    $j,
                    $right
                );
            }

            if ($j <= $k) {
                $left = $j + 1;
            }

            if ($k <= $j) {
                $right = $j - 1;
            }
        }
    }

    public static function swapItem(
        Vector $ids,
        Vector $coords,
        int $i,
        int $j
    ): void {
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        [$coords[2 * $i], $coords[2 * $j]] = [$coords[2 * $j], $coords[2 * $i]];
        [$coords[2 * $i + 1], $coords[2 * $j + 1]] = [$coords[2 * $j + 1], $coords[2 * $i + 1]];
    }
}
