<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Avatar;

// User avatar showing initials
$component = Avatar::fromName("JD");
$component->setSize(60, 15);
echo $component->render();
