<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\FlexLayout;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Flex layout
$component = FlexLayout::new([Text::new("Flex 1"), Text::new("Flex 2")]);
$component->setSize(60, 15);
echo $component->render();
