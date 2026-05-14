<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Kbd;

// Keyboard key display
$component = Kbd::combo("Ctrl", "C")->setSize(60, 15);
echo $component->render();
