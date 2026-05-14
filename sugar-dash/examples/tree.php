<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Tree\Tree;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Tree view
$component = Tree::new("Root", [["label" => "Child 1"], ["label" => "Child 2"]]);
$component->setSize(60, 15);
echo $component->render();
