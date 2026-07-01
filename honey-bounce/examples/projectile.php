<?php

declare(strict_types=1);

/**
 * Trace a 2D projectile arc — initial velocity up-and-to-the-right,
 * gravity pulling Y back down. ASCII-plot the trajectory.
 *
 *   php examples/projectile.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\Bounce\Point;
use SugarCraft\Bounce\Projectile;
use SugarCraft\Bounce\Spring;
use SugarCraft\Bounce\Vector;

$p = Projectile::new(
    deltaTime:    Spring::fps(60),
    position:     Point::zero(),
    // Y-up convention: positive Y goes upward.
    velocity:     new Vector(20.0, 25.0),
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
