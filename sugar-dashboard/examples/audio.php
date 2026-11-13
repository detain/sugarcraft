<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Media\Audio;

// Audio player
$component = Audio::new();
$component = $component->setSize(60, 15);
echo $component->render();
