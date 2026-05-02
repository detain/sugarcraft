<?php

declare(strict_types=1);

/**
 * Style — show the headline text-styling primitives: foreground,
 * background, bold, italic, underline, strikethrough, plus
 * adaptive colour.
 *
 *   php examples/style.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Style;

$cases = [
    'foreground'   => Style::new()->foreground(Color::hex('#ff5fd2'))->render('hot pink text'),
    'background'   => Style::new()->background(Color::hex('#5fafff'))->foreground(Color::hex('#000000'))->padding(0, 2)->render('  blue bg  '),
    'bold'         => Style::new()->bold()->render('bold-faced'),
    'italic'       => Style::new()->italic()->render('italicised'),
    'underline'    => Style::new()->underline()->render('underlined'),
    'strikethru'   => Style::new()->strikethrough()->render('struck out'),
    'reverse'      => Style::new()->reverse()->render('reversed video'),
    'composed'     => Style::new()
        ->bold()->italic()->underline()
        ->foreground(Color::hex('#fde68a'))
        ->background(Color::hex('#1f162d'))
        ->padding(0, 1)
        ->render(' all of the above '),
];

foreach ($cases as $label => $rendered) {
    printf("  \x1b[36m%-12s\x1b[0m %s\n", $label, $rendered);
}
