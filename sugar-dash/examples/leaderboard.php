<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Leaderboard;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Leaderboard
$component = Leaderboard::new([["rank" => 1, "label" => "Alice", "value" => "1500"], ["rank" => 2, "label" => "Bob", "value" => "1200"], ["rank" => 3, "label" => "Charlie", "value" => "1100"]]);
$component->setSize(60, 15);
echo $component->render();
