<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Metric;

// Metric display
$component = Metric::new(12345.0, "Revenue")->setSize(60, 15);
echo $component->render();
