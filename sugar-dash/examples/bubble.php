<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\Bubble;

// Bubble chart with sample data
$component = Bubble::sample(6);
$component->setSize(60, 15);
echo $component->render();
