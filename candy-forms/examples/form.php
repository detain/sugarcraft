<?php

declare(strict_types=1);

/**
 * Static render of a whole CandyForms form — the pieces sugar-bits and
 * sugar-prompt re-export. One {@see Form} composes an {@see Input}
 * (pre-filled), a {@see Select} (options with one highlighted), and a
 * {@see Confirm} (Yes / No toggle), then echoes the styled `view()`.
 *
 * No Program loop, no keystrokes — just build the model and print a
 * frame, which makes for a clean, deterministic VHS recording.
 *
 *   php examples/form.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;
use SugarCraft\Forms\Theme;
use SugarCraft\Forms\Validator\Required;

$form = Form::groups(
    Group::new(
        Input::new('project')
            ->withTitle('Project name')
            ->withDescription('The repository to deploy.')
            ->withValue('sugarcraft')
            ->withValidator(new Required()),
        Select::new('environment')
            ->withTitle('Target environment')
            ->withDescription('Where the build ships.')
            ->withOptions('development', 'staging', 'production')
            ->withSelected('staging'),
        Confirm::new('confirm')
            ->withTitle('Run database migrations?')
            ->withLabels('Yes', 'No')
            ->withDefault(true),
    )
        ->withTitle('Deploy configuration')
        ->withDescription('Review the settings below before shipping.'),
)->withTheme(Theme::charm());

echo "\n" . $form->view() . "\n\n";
