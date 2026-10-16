<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\ChipGroup;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Chip group
$component = ChipGroup::fromLabels(["PHP", "JavaScript", "Python"]);
$component->setSize(60, 15);
echo $component->render();
