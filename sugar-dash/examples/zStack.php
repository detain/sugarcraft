<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\ZStack;

// Layered stack
$component = ZStack::new(Text::new("Back"), Text::new("Front"))->setSize(60, 15);
echo $component->render();
