<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Nav\Menu;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Menu navigation
$component = Menu::new([["label" => "File"], ["label" => "Edit"], ["label" => "View"]]);
$component->setSize(60, 15);
echo $component->render();
