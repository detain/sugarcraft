<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Stats;

// Stats display
$component = Stats::new([
    ["label" => "Users", "value" => "1.2K"],
    ["label" => "Revenue", "value" => "$45K"],
    ["label" => "Orders", "value" => "89"],
])->setSize(60, 15);
echo $component->render();
