<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Nav\Menu;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Menu navigation
$component = Menu::new([["label" => "File"], ["label" => "Edit"], ["label" => "View"]]);
$component->setSize(60, 15);
echo $component->render();
