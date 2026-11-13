<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Plot\Chart\DotMatrix;

// Dot matrix display
$component = DotMatrix::new("Demo");
$component = $component->setSize(60, 15);
echo $component->render();
