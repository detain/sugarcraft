<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Nav\Menu;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Menu navigation
$component = Menu::new([["label" => "File"], ["label" => "Edit"], ["label" => "View"]]);
$component->setSize(60, 15);
echo $component->render();
