<?php

declare(strict_types=1);

/**
 * Border — render the same content under every built-in border
 * style for comparison.
 *
 *   php examples/border.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Layout;
use CandyCore\Sprinkles\Style;

$styles = [
    'normal'  => Border::normal(),
    'rounded' => Border::rounded(),
    'thick'   => Border::thick(),
    'double'  => Border::double(),
    'block'   => Border::block(),
    'ascii'   => Border::ascii(),
];

foreach ($styles as $label => $border) {
    $box = Style::new()
        ->border($border)
        ->padding(0, 2)
        ->render(sprintf('  %-7s  ', $label));
    echo $box . "\n";
}
