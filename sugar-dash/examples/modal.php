<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Components\Modal\Modal;

// Modal dialog
$component = Modal::new(Text::new("Modal Dialog Content"))->setSize(60, 15);
echo $component->render();
