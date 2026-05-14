<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Spacer;

// Spacer element - dotted line separator
$component = Spacer::dotted(60);
echo $component->render();
