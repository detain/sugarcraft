<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Form\Textarea;

// Multi-line text input
$component = Textarea::new("Demo", 3);
$component = $component->setSize(60, 15);
echo $component->render();
