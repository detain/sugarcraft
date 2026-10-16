<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\Code;

// Inline code display
$component = Code::new("echo \"Hello\";")->setSize(60, 15);
echo $component->render();
