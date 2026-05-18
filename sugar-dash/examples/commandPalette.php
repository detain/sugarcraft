<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Form\CommandPalette;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Command palette
$component = CommandPalette::new([["label" => "New File"], ["label" => "Open Folder"], ["label" => "Save"]]);
$component->setSize(60, 15);
echo $component->render();
