<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Sidebar;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Sidebar menu
$component = Sidebar::new([["label" => "Home"], ["label" => "Profile"], ["label" => "Settings"]]);
$component->setSize(60, 15);
echo $component->render();
