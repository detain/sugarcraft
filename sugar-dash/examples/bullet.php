<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Bullet;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Bullet chart
$component = Bullet::new(75.0, 100.0);
$component->setSize(60, 15);
echo $component->render();
