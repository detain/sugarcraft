<?php

declare(strict_types=1);

/**
 * Text field — multi-line input.
 *
 *   php examples/text.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Prompt\Field\Text;

$field = Text::new('notes')
    ->withTitle('Release notes')
    ->withDescription('Markdown OK.')
    ->withPlaceholder("## v1.0\nFirst release…");

[$focused] = $field->focus();
echo $focused->view() . "\n";
