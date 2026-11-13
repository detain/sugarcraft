<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Plot\Chart\Sparkline;

// Sparkline chart - the plot-family Sparkline is push-based: width first,
// data via pushAll() (a list<float> never enters its ctor; see the class docblock)
$component = Sparkline::new(40);
$component = $component->pushAll([3.0, 5.0, 2.0, 8.0, 6.0, 4.0, 7.0, 5.0, 9.0, 6.0, 8.0, 7.0]);
$component = $component->setSize(60, 15);
echo $component->render();
