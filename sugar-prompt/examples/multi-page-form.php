<?php

declare(strict_types=1);

/**
 * Multi-page form with conditional show/hide.
 *
 *   php examples/multi-page-form.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Program;
use SugarCraft\Prompt\Field\Confirm;
use SugarCraft\Prompt\Field\Input;
use SugarCraft\Prompt\Field\Note;
use SugarCraft\Prompt\Field\Select;
use SugarCraft\Prompt\Form;
use SugarCraft\Prompt\Group;
use SugarCraft\Prompt\Theme;

$form = Form::groups(
    Group::new(
        Note::new('intro')->withTitle('Welcome')
            ->withDescription("Let's set up your account."),
        Input::new('name')
            ->withTitle('Your name')
            ->withValidator(static fn(string $v): ?string
                => trim($v) === '' ? 'name is required' : null),
        Confirm::new('newsletter', false)
            ->withTitle('Subscribe to the newsletter?')
            ->withLabels('Sure', 'No thanks'),
    )
        ->withTitle('Step 1 — Profile'),

    Group::new(
        Select::new('frequency')
            ->withTitle('How often?')
            ->withOptions('Weekly', 'Monthly', 'Never'),
    )
        ->withTitle('Step 2 — Newsletter')
        ->withHideFunc(static fn(array $values): bool
            => empty($values['newsletter'])),

    Group::new(
        Note::new('thanks')
            ->withTitle('Thank you')
            ->withDescription("All set. Press Enter to finish."),
    )
        ->withTitle('Step 3 — Done'),
)->withTheme(Theme::charm());

(new Program($form))->run();

if ($form->isSubmitted() === TRUE) {
    echo "\nValues:\n";
    foreach ($form->values() as $key => $val) {
        echo "  $key = " . var_export($val, true) . "\n";
    }
} else {
    echo "\nForm aborted.\n";
}
