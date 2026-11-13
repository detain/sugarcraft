<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Foundation\Segment;

// Segment display
$component = Segment::new("Demo");
$component = $component->setSize(60, 15);
echo $component->render();
