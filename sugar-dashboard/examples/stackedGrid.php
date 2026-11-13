<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Layout\Grid\StackedGrid;

// Multi-column grid layout
$component = new StackedGrid(new \SugarCraft\Dashboard\Layout\Grid\Options(true));
$component = $component->setSize(60, 15);
echo $component->render();
