<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\GridLayout;

// Grid layout
$component = GridLayout::columns(3, [
    Text::new("Cell 1"),
    Text::new("Cell 2"),
    Text::new("Cell 3"),
    Text::new("Cell 4"),
    Text::new("Cell 5"),
    Text::new("Cell 6"),
])->setSize(60, 15);
echo $component->render();
