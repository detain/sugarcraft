<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\HStack;

// Horizontal stack of items
$component = HStack::new(Text::new("Left"), Text::new("Right"))->setSize(60, 15);
echo $component->render();
