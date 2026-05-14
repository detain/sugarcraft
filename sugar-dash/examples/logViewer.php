<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\System\LogViewer;

// Log viewer
$component = LogViewer::fromMessages(["Application started", "Processing request...", "Request completed"])->setSize(60, 15);
echo $component->render();
