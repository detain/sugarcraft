<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Plot\Chart\Sparkline;

// Sparkline chart
$component = Sparkline::new(40);
$component = $component->setSize(60, 15);
echo $component->render();
