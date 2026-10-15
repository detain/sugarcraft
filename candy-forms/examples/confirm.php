<?php

declare(strict_types=1);

/**
 * Confirm field — three states: default-no, default-yes, custom labels.
 *
 *   php examples/confirm.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Prompt\Field\Confirm;

$cases = [
    'default no'   => Confirm::new('proceed', false)->withTitle('Proceed?'),
    'default yes'  => Confirm::new('save', true)->withTitle('Save changes?'),
    'custom labels'=> Confirm::new('ship', true)
        ->withTitle('Ship to production?')
        ->withLabels('Ship it', 'Hold'),
];

foreach ($cases as $label => $field) {
    [$focused] = $field->focus();
    echo "\x1b[36m$label\x1b[0m\n" . $focused->view() . "\n\n";
}
