<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\Frame;

// Bordered frame container
$component = Frame::new(Text::new("Framed Content"))->setSize(60, 15);
echo $component->render();
