<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\Split;

// Split pane layout
$component = Split::horizontal([Text::new("Left Panel"), Text::new("Right Panel")])->setSize(60, 15);
echo $component->render();
