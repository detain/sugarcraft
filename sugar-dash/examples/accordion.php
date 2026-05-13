<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Accordion;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Accordion component
$component = Accordion::new([["label" => "Section 1", "content" => "Content 1"], ["label" => "Section 2", "content" => "Content 2"]]);
$component->setSize(60, 15);
echo $component->render();
