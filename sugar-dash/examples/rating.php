<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Form\Rating;

// Star rating
$component = Rating::of(4.5)->setSize(60, 15);
echo $component->render();
