<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Media\Emoji;

// Emoji display
$component = new Emoji("Demo", null, 3, null, true);
$component = $component->setSize(60, 15);
echo $component->render();
