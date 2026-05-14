<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Header;

// Header
$component = Header::new("Dashboard")->setSize(60, 15);
echo $component->render();
