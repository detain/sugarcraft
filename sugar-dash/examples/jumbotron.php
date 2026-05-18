<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Jumbotron;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Jumbotron hero
$component = Jumbotron::new("Welcome!", "To our awesome application");
$component->setSize(60, 15);
echo $component->render();
