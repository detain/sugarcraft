<?php

declare(strict_types=1);

/**
 * Input field — title + description + placeholder, focused.
 *
 *   php examples/input.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Prompt\Field\Input;

$field = Input::new('email')
    ->withTitle('Email')
    ->withDescription('We never spam, promise.')
    ->withPlaceholder('you@example.com');

[$focused] = $field->focus();
echo $focused->view() . "\n";
