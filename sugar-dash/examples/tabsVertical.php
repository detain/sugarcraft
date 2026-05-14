<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Components\Tabs\TabsVertical;

// Vertical tabs
$component = TabsVertical::new([
    ["label" => "Overview", "content" => Text::new("Overview content")],
    ["label" => "Settings", "content" => Text::new("Settings content")],
    ["label" => "Help", "content" => Text::new("Help content")],
])->setSize(60, 15);
echo $component->render();
