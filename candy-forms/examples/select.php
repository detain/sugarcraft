<?php

declare(strict_types=1);

/**
 * Select field — single-choice dropdown.
 *
 *   php examples/select.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Prompt\Field\Select;

$field = Select::new('shell')
    ->withTitle('Default shell')
    ->withDescription('Used by `script` and `spawn` for new commands.')
    ->withOptions('bash', 'zsh', 'fish', 'nushell', 'pwsh');

[$focused] = $field->focus();
echo $focused->view() . "\n";
