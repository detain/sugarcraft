<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\ActivityFeed;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Activity feed
$component = ActivityFeed::new([["label" => "Alice posted a comment", "time" => "2m ago"], ["label" => "Bob updated status", "time" => "5m ago"]]);
$component->setSize(60, 15);
echo $component->render();
