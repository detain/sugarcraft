<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\ChipGroup;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Chip group
$component = ChipGroup::fromLabels(["PHP", "JavaScript", "Python"]);
$component->setSize(60, 15);
echo $component->render();
