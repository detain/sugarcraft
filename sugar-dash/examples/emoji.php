<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Emoji;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Emoji display
$component = Emoji::new("🚀");
$component->setSize(60, 15);
echo $component->render();
