<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\AvatarGroup;

// Avatar group with multiple users
$component = AvatarGroup::fromNames(['Alice', 'Bob', 'Charlie']);
$component->setSize(60, 15);
echo $component->render();
