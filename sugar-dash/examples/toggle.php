<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Form\Toggle;

// Toggle switch
$component = Toggle::on()->setSize(60, 15);
echo $component->render();
