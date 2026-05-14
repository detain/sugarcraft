<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;

// Basic text content
$component = Text::new("Hello, SugarDash!")->setSize(60, 15);
echo $component->render();
