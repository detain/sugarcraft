<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\System\Timer;

// Timer display
$component = Timer::fromMinutes(5)->setSize(60, 15);
echo $component->render();
