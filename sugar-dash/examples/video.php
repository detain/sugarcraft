<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Video;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Video player
$component = Video::new("video.mp4");
$component->setSize(60, 15);
echo $component->render();
