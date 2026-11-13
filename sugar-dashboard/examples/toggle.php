<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Form\Toggle;

// Toggle switch
$component = Toggle::new(true);
$component = $component->setSize(60, 15);
echo $component->render();
