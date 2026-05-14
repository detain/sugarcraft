<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Spacer;

// Spacer element
$component = Spacer::new(60, 15);
echo $component->render();
