<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Image;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Image display
$component = Image::fromUrl("https://placehold.co/300x200.png", "Demo Image");
$component->setSize(60, 15);
echo $component->render();
