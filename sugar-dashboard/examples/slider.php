<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Form\Slider;

// Slider control
$component = Slider::new(42.5, 42.5, 100.0);
$component = $component->setSize(60, 15);
echo $component->render();
