<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\Paragraph;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Word-wrapped paragraph
$component = Paragraph::new("This is a long paragraph that should wrap nicely within the allocated width.");
$component->setSize(60, 15);
echo $component->render();
