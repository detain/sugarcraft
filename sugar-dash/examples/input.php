<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Form\Input;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Labeled input
$component = Input::labeled("john@example.com", "Email");
$component->setSize(60, 15);
echo $component->render();
