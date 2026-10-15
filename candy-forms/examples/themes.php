<?php

declare(strict_types=1);

/**
 * Themes — render the same Form under five built-in themes for
 * comparison.
 *
 *   php examples/themes.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Prompt\Field\Input;
use SugarCraft\Prompt\Field\Select;
use SugarCraft\Prompt\Form;
use SugarCraft\Prompt\Group;
use SugarCraft\Prompt\Theme;

$themes = [
    'base'       => Theme::base(),
    'charm'      => Theme::charm(),
    'dracula'    => Theme::dracula(),
    'base16'     => Theme::base16(),
    'catppuccin' => Theme::catppuccin(),
];

$buildForm = static fn(Theme $t): Form => Form::groups(
    Group::new(
        Input::new('email')
            ->withTitle('Email')
            ->withPlaceholder('you@example.com'),
        Select::new('plan')
            ->withTitle('Plan')
            ->withOptions('free', 'pro', 'enterprise'),
    ),
)->withTheme($t);

foreach ($themes as $name => $theme) {
    echo "\x1b[1m── $name ──\x1b[0m\n";
    echo $buildForm($theme)->view() . "\n\n";
}
