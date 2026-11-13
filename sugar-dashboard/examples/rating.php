<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Form\Rating;

// Star rating
$component = Rating::new(5, 42.5);
$component = $component->setSize(60, 15);
echo $component->render();
