<?php

declare(strict_types=1);

/**
 * Conditional field visibility via `withHideFunc()`. Mirrors huh's
 * `hide` example — the visibility predicate runs every time the form
 * collects values (so `values()` skips fields whose group's
 * predicate returns true).
 *
 *   php examples/hide.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Prompt\Field\Confirm;
use SugarCraft\Prompt\Field\Input;
use SugarCraft\Prompt\Field\Select;
use SugarCraft\Prompt\Form;
use SugarCraft\Prompt\Group;

// Group 1 always asks the gating question.
$gate = Group::new(
    Confirm::new('subscribe')
        ->withTitle('Subscribe to the newsletter?')
        ->withLabels('Yes', 'No')
        ->withDefault(false),
)->withTitle('Gate');

// Group 2 only renders when the user said yes — withHideFunc() reads
// the accumulated values so far and returns true to skip the group.
$followUp = Group::new(
    Input::new('email')
        ->withTitle('Email address')
        ->withPlaceholder('you@example.com'),
    Select::new('cadence')
        ->withTitle('How often?')
        ->withOptions('Daily', 'Weekly', 'Monthly'),
)
->withTitle('Subscription details')
->withHideFunc(static fn(array $values): bool
    => ($values['subscribe'] ?? false) !== true);

$form = Form::groups($gate, $followUp);
echo "Active group view (gate):\n\n";
echo $form->view() . "\n";

echo "\nGroup hidden when answer is 'No':\n";
echo "  values() result: ";
print_r($form->values());
