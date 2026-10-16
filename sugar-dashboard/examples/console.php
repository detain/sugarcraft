<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\System\Console;
use SugarCraft\Dashboard\Components\System\ConsoleEntry;
use SugarCraft\Dashboard\Components\System\ConsoleStream;

// Console output
$component = Console::new()
    ->withEntry(ConsoleEntry::info("Application started"))
    ->withEntry(ConsoleEntry::raw("Processing data..."))
    ->withEntry(ConsoleEntry::error("Error: file not found"))
    ->setSize(60, 15);
echo $component->render();
