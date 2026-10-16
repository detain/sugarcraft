<?php

declare(strict_types=1);

/**
 * Particle demo — port of harmonica's `particle` example. Spawns N
 * projectiles from the origin, each with a randomised initial velocity
 * vector + small random initial position offset, runs the simulation
 * for K frames under Y-up gravity, then ASCII-plots every particle's
 * final position into a single grid.
 *
 *   php examples/particle.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Spring\Point;
use SugarCraft\Spring\Projectile;
use SugarCraft\Spring\Spring;
use SugarCraft\Spring\Vector;

$particleCount = 80;
$frames        = 60;        // ~1 simulated second at 60 FPS
$dt            = Spring::fps(60);

mt_srand(42);
/** @var list<Projectile> $particles */
$particles = [];
for ($i = 0; $i < $particleCount; $i++) {
    // Fan a swarm out from the origin: random angle in [-15°, +15°]
    // off the vertical, magnitude in [15, 25].
    $angle = (mt_rand(-150, 150) / 1000.0);
    $speed = 15.0 + (mt_rand(0, 1000) / 100.0);
    $vx    = sin($angle) * $speed;
    $vy    = cos($angle) * $speed;        // upward = positive Y
    $particles[] = Projectile::new(
        deltaTime:    $dt,
        position:     Point::zero(),
        velocity:     new Vector($vx, $vy),
        acceleration: Projectile::gravity(),
    );
}

// Step every particle the same number of frames.
$traces = [];
for ($i = 0; $i < count($particles); $i++) {
    $p = $particles[$i];
    $trace = [];
    for ($f = 0; $f < $frames; $f++) {
        $trace[] = $p->position;
        $p = $p->update();
    }
    $traces[] = $trace;
}

// Plot every recorded position into a single ASCII grid.
$w = 60;
$h = 18;
$grid = array_fill(0, $h, str_repeat(' ', $w));

$allX = [];
$allY = [];
foreach ($traces as $trace) {
    foreach ($trace as $pt) {
        $allX[] = $pt->x;
        $allY[] = $pt->y;
    }
}
$xMin = min($allX);
$xMax = max($allX);
$yMin = min($allY);
$yMax = max($allY);

foreach ($traces as $idx => $trace) {
    foreach ($trace as $pt) {
        $tx  = ($pt->x - $xMin) / max(1e-9, $xMax - $xMin);
        $ty  = ($pt->y - $yMin) / max(1e-9, $yMax - $yMin);
        $col = (int) round($tx * ($w - 1));
        // Y-up screen coords flip: high Y → top row.
        $row = (int) round((1.0 - $ty) * ($h - 1));
        $grid[$row][$col] = '*';
    }
}

echo "Harmonica particle demo — {$particleCount} projectiles, {$frames} frames @ 60 FPS, Y-up gravity\n\n";
echo implode("\n", $grid) . "\n";
