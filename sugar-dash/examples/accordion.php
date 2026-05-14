<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Accordion;

// Accordion component
$component = Accordion::new([["title" => "Section 1", "content" => "Content 1"], ["title" => "Section 2", "content" => "Content 2"]])->setSize(60, 15);
echo $component->render();
