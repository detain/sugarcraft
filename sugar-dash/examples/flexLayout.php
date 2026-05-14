<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\FlexLayout;

// Flex layout
$component = FlexLayout::row([Text::new("Flex 1"), Text::new("Flex 2")])->setSize(60, 15);
echo $component->render();
