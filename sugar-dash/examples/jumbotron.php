<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Jumbotron;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Jumbotron hero
$component = Jumbotron::new("Welcome!", "To our awesome application");
$component->setSize(60, 15);
echo $component->render();
