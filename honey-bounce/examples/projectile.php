<?php

declare(strict_types=1);

/**
 * Trace a 2D projectile arc — initial velocity up-and-to-the-right,
 * gravity pulling Y back down. ASCII-plot the trajectory.
 *
 *   php examples/projectile.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bounce\Point;
use CandyCore\Bounce\Projectile;
use CandyCore\Bounce\Spring;
use CandyCore\Bounce\Vector;

$p = Projectile::new(
    deltaTime:    Spring::fps(60),
    position:     Point::zero(),
    velocity:     new Vector(20.0, -25.0),
    acceleration: Projectile::gravity(),
);

// Collect 90 frames worth of positions, then plot them.
$points = [];
for ($i = 0; $i < 90; $i++) {
    $points[] = $p->position;
    $p = $p->update();
}

$w = 60;
$h = 18;
$grid = array_fill(0, $h, str_repeat(' ', $w));

$xMin = min(array_map(static fn(Point $p) => $p->x, $points));
$xMax = max(array_map(static fn(Point $p) => $p->x, $points));
$yMin = min(array_map(static fn(Point $p) => $p->y, $points));
$yMax = max(array_map(static fn(Point $p) => $p->y, $points));

foreach ($points as $pt) {
    $tx = ($pt->x - $xMin) / max(1e-9, $xMax - $xMin);
    $ty = ($pt->y - $yMin) / max(1e-9, $yMax - $yMin);
    $col = (int) round($tx * ($w - 1));
    $row = (int) round((1 - $ty) * ($h - 1));
    $grid[$row][$col] = '*';
}
echo implode("\n", $grid) . "\n";
