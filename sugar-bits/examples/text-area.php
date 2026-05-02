<?php

declare(strict_types=1);

/**
 * TextArea — multi-line text input. Static snapshot showing
 * empty/placeholder, typed content, and a sized box with line
 * numbers.
 *
 *   php examples/text-area.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\TextArea\TextArea;

$cases = [
    'empty + placeholder' => TextArea::new()
        ->withPlaceholder('Type something…')
        ->withWidth(40)
        ->withHeight(3),
    'with text + line numbers' => TextArea::new()
        ->setValue("# todo\n- write more demos\n- regenerate gifs\n- ship")
        ->withWidth(40)
        ->withHeight(5)
        ->showLineNumbers(true),
];

foreach ($cases as $label => $ta) {
    printf("\x1b[36m%s\x1b[0m\n%s\n\n", $label, $ta->view());
}
