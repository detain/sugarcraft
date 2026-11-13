<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Plot\Chart\ProgressRing;

// Circular progress
$component = ProgressRing::new(42.5);
$component = $component->setSize(60, 15);
echo $component->render();
