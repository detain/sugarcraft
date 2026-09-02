<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\Donut;

// Wireframe donut: rim runes, one radial divider per segment boundary, hub.
// Shape-only, so it reads on colourless terminals; assign setSize()'s clone
// back (BL-8 discipline) so the requested 60x15 actually takes effect.
$component = Donut::mocha([["label" => "Category A", "value" => 35.0], ["label" => "Category B", "value" => 25.0], ["label" => "Category C", "value" => 40.0]])
    ->withRenderMode(Donut::RENDER_WIREFRAME);
$component = $component->setSize(60, 15);
echo $component->render();
