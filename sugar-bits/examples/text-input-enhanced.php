<?php

declare(strict_types=1);

/**
 * Show enhanced TextInput features: custom placeholder styling,
 * prefix, and suffix support. Each variant demonstrates a different
 * combination of these features.
 *
 *   php examples/text-input-enhanced.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Bits\TextInput\TextInput;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

$inputs = [
    // Default placeholder (faint styling)
    'default placeholder' => TextInput::new()
        ->withPlaceholder('Search…'),

    // Custom placeholder style (italic + red foreground)
    'custom placeholder style' => TextInput::new()
        ->withPlaceholder('Enter username…')
        ->withPlaceholderStyle(Style::new()->italic()->foreground(Color::hex('#ff6b6b'))),

    // With prefix (e.g., shell prompt style)
    'with prefix' => TextInput::new()
        ->withPlaceholder('command')
        ->withPrefix('$ '),

    // With suffix (e.g., closing delimiter)
    'with suffix' => TextInput::new()
        ->withPlaceholder('value')
        ->withSuffix(' <'),

    // All features combined: prefix + custom placeholder + suffix
    'prefix + styled placeholder + suffix' => TextInput::new()
        ->withPlaceholder('amount')
        ->withPlaceholderStyle(Style::new()->foreground(Color::hex('#ffd93d'))->bold())
        ->withPrefix('$ ')
        ->withSuffix(' USD'),
];

foreach ($inputs as $label => $input) {
    printf("  \x1b[36m%-38s\x1b[0m %s\n", $label, $input->view());
}
