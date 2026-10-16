<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Card\Testimonial;

// Testimonial
$component = Testimonial::single([
    "text" => "Excellent product, highly recommended!",
    "author" => "Jane Smith",
    "role" => "CEO at Company",
])->setSize(60, 15);
echo $component->render();
