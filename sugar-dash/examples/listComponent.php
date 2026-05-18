<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Calendar\ListComponent;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// List component
$component = ListComponent::new([["label" => "Item 1"], ["label" => "Item 2"], ["label" => "Item 3"]]);
$component->setSize(60, 15);
echo $component->render();
