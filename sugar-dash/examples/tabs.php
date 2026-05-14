<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Components\Tabs\Tabs;

// Tab navigation
$component = Tabs::new([
    ["label" => "Tab 1", "content" => Text::new("Content 1")],
    ["label" => "Tab 2", "content" => Text::new("Content 2")],
    ["label" => "Tab 3", "content" => Text::new("Content 3")],
])->setSize(60, 15);
echo $component->render();
