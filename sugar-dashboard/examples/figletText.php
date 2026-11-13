<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Media\FigletText;

// ASCII art text
$component = FigletText::new("Demo");
$component = $component->setSize(60, 15);
echo $component->render();
