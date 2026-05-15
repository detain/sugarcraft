<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Emoji;

// Emoji display - thumbs up
$component = Emoji::thumbsUp();
$component->setSize(60, 15);
echo $component->render();
