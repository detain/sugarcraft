<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\MetricsGrid;

// Metrics grid
$component = MetricsGrid::sample()->setSize(60, 15);
echo $component->render();
