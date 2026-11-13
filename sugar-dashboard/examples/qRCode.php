<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Media\QRCode;

// QR code
$component = QRCode::new("Demo");
$component = $component->setSize(60, 15);
echo $component->render();
