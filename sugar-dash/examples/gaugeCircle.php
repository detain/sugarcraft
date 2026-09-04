<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\GaugeCircle;

// Circular gauge — setSize() allocates the dial geometry (v5 D2).
$component = GaugeCircle::new(0.8);
$component = $component->setSize(60, 15);
echo $component->render();
