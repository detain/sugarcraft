<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Paragraph;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Word-wrapped paragraph
$component = Paragraph::new("This is a long paragraph that should wrap nicely within the allocated width.");
$component->setSize(60, 15);
echo $component->render();
