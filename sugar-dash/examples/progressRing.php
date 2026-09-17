<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\ProgressRing;

// Circular progress
$component = ProgressRing::new(0.65);
$component->setSize(60, 15);
echo $component->render();
