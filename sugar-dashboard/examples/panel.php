<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\Text;
use SugarCraft\Dashboard\Layout\Panel;

// Panel container
$component = Panel::new(Text::new("Panel Content"))->setSize(60, 15);
echo $component->render();
