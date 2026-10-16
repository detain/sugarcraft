<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\Text;

// Basic text content
$component = Text::new("Hello, SugarDashboard!")->setSize(60, 15);
echo $component->render();
