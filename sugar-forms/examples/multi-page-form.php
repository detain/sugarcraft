<?php

declare(strict_types=1);

/**
 * Multi-page form with conditional show/hide.
 *
 *   php examples/multi-page-form.php
 */

require __DIR__ . '/../vendor/autoload.php';

// Belt-braces (outside-group QA-01): the Program input reader never stops
// on a closed stdin, so without a terminal this script would spin until
// killed. The real EOF-stop fix lives in sugar-core Program.php:1302-1310;
// until it lands, refuse non-interactive stdin up front with a clean rc=2
// instead of hanging to a timeout rc=124.
if (!stream_isatty(STDIN)) {
    fwrite(STDERR, "interactive terminal required\n");
    exit(2);
}

use SugarCraft\Core\Program;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;
use SugarCraft\Forms\Theme;

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

$form = (new Program($form))->run(); // Program drives a COPY; run() returns the final model

if ($form->isSubmitted()) {
    echo "\nValues:\n";
    foreach ($form->values() as $key => $val) {
        echo "  $key = " . var_export($val, true) . "\n";
    }
} else {
    echo "\nForm aborted.\n";
}
