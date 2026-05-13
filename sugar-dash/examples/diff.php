<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Diff;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Diff view
$component = Diff::new("Line 1\nLine 2\nLine 3", "Line 1\nModified Line 2\nLine 3");
$component->setSize(60, 15);
echo $component->render();
