<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Testimonial;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Testimonial
$component = Testimonial::new("Excellent product, highly recommended!", "Jane Smith", "CEO at Company");
$component->setSize(60, 15);
echo $component->render();
