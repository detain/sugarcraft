<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Select\DatePicker;

// Date picker
$component = DatePicker::new(2024, 1, 15)->setSize(60, 15);
echo $component->render();
