<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\WordCloud;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Word cloud
$component = WordCloud::new([
    ['word' => 'PHP', 'weight' => 10],
    ['word' => 'JavaScript', 'weight' => 8],
    ['word' => 'Python', 'weight' => 6],
    ['word' => 'Go', 'weight' => 5],
    ['word' => 'Rust', 'weight' => 4],
]);
$component->setSize(60, 15);
echo $component->render();
