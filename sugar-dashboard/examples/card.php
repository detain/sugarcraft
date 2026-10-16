<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\Card;
use SugarCraft\Dashboard\Components\Card\Text;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Card with optional title
$component = Card::new(new Text("Card Content"));
$component->setSize(60, 15);
echo $component->render();
