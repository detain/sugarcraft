<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Layout\Grid\StackedGrid;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;
use SugarCraft\Dashboard\Components\Card\Text;

// Multi-column grid layout - a grid without items renders nothing, so the
// demo teaches addItem() with column placement (finding sugar-dashboard-115)
$component = new StackedGrid(new \SugarCraft\Dashboard\Layout\Grid\Options(true));
$component->addItem(Text::new('Panel A — left column'), new ItemOptions(column: 0));
$component->addItem(Text::new('Panel B — right column'), new ItemOptions(column: 1));
$component = $component->setSize(60, 15);
echo $component->render();
