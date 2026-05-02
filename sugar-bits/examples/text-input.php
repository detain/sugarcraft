<?php

declare(strict_types=1);

/**
 * Show every TextInput state side-by-side: empty (with placeholder),
 * typed, masked (password mode), and with a suggestion. Static
 * snapshot — useful for picking the right configuration without
 * booting a Program loop.
 *
 *   php examples/text-input.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\TextInput\EchoMode;
use CandyCore\Bits\TextInput\TextInput;

$inputs = [
    'empty + placeholder' => TextInput::new()
        ->withPlaceholder('Search…'),
    'with text + cursor'  => TextInput::new()
        ->setValue('hello world')
        ->setCursor(5),
    'password (masked)'   => TextInput::new()
        ->setValue('hunter2')
        ->withEchoMode(EchoMode::Password),
    'with suggestion'     => TextInput::new()
        ->setValue('comp')
        ->withSuggestions(['composer', 'compose', 'compatibility'])
        ->showSuggestions(true),
];

foreach ($inputs as $label => $input) {
    printf("  \x1b[36m%-22s\x1b[0m %s\n", $label, $input->view());
}
