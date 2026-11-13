<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Layout\Spacer;

// Spacer element
$component = Spacer::new(40, 12);
$component = $component->setSize(60, 15);
echo $component->render();
