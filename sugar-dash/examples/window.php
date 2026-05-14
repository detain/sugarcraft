<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\Window;

// Window frame
$component = Window::new(Text::new("Window Content"), "My Window")->setSize(60, 15);
echo $component->render();
