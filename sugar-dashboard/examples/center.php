<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Position\Center;

// Center is a static positioning helper, not a renderable widget:
// it pads raw text into a boxed area.
$padded = Center::center("Centered Text", 24, 3);

foreach (explode("\n", $padded) as $line) {
    echo "[", $line, "]\n";
}
