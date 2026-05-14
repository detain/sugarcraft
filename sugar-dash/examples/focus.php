<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Focus;
use SugarCraft\Dash\Components\Card\Text;

// Focus highlight
$component = Focus::new([new Text("Focused Element")]);
$component->setSize(60, 15);
echo $component->render();
