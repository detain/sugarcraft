<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\System\Log;
use SugarCraft\Dash\Components\System\LogEntry;
use SugarCraft\Dash\Components\System\LogLevel;

// Log text
$component = Log::new()
    ->withEntry(new LogEntry(date('Y-m-d H:i:s'), LogLevel::Info, "Log entry 1"))
    ->withEntry(new LogEntry(date('Y-m-d H:i:s'), LogLevel::Warn, "Log entry 2"))
    ->withEntry(new LogEntry(date('Y-m-d H:i:s'), LogLevel::Error, "Log entry 3"))
    ->setSize(60, 15);
echo $component->render();
