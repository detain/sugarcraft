<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Footer;

// Footer
$component = Footer::copyright("SugarCraft Inc.")->setSize(60, 15);
echo $component->render();
