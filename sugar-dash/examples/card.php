<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Card;
use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Card with optional title
$component = Card::new(new Text("Card Content"));
$component->setSize(60, 15);
echo $component->render();
