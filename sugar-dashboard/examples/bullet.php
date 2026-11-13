<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\Bullet;
use SugarCraft\Dashboard\Plot\Chart\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Bullet chart
$component = Bullet::new(75.0, 100.0);
$component = $component->setSize(60, 15);
echo $component->render();
