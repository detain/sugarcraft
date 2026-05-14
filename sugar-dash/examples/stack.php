<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\Stack;

// Generic stack
$component = Stack::new(Text::new("Item 1"), Text::new("Item 2"))->setSize(60, 15);
echo $component->render();
