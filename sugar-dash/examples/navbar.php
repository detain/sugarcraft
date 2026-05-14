<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Nav\Navbar;

// Navigation bar
$component = Navbar::brand("Dashboard", [
    ["label" => "Home", "isActive" => true],
    ["label" => "About"],
    ["label" => "Contact"],
])->setSize(60, 15);
echo $component->render();
