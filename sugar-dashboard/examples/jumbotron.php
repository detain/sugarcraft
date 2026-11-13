<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\Jumbotron;

// Jumbotron hero
$component = Jumbotron::new("Demo", "Demo");
$component = $component->setSize(60, 15);
echo $component->render();
