<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\Text;
use SugarCraft\Dashboard\Layout\Window;

// Window frame
$component = Window::new(Text::new("Window Content"), "My Window")->setSize(60, 15);
echo $component->render();
