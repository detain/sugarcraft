<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Breadcrumb;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Breadcrumb navigation
$component = Breadcrumb::new([["label" => "Home"], ["label" => "Products"], ["label" => "Details"]]);
$component->setSize(60, 15);
echo $component->render();
