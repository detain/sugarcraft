<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\Profile;

// User profile
$component = Profile::new("Demo", "Demo", "Demo", null);
$component = $component->setSize(60, 15);
echo $component->render();
