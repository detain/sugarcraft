<?php

declare(strict_types=1);

/**
 * NO_COLOR / CLICOLOR negotiation demo (sugar-forms-088 showcase leg).
 *
 * Shows how a program asks sugar-core which colours the terminal actually
 * supports and then renders through that verdict — the negotiation lives
 * in ColorProfile::detect() (the full cure for the NO_COLOR-ignored defect
 * stays a sugar-core step); this example is the consumer-side shape: a
 * Style::withDefaultProfile() scope around the render call.
 *
 *   php examples/no-color.php                   # ambient negotiation
 *   NO_COLOR=1 php examples/no-color.php        # colour off, bold kept
 *   CLICOLOR_FORCE=1 php examples/no-color.php  # forced full colour
 *   php examples/no-color.php | cat             # pipe => NoTty, zero SGR
 *
 * Note the last line: with stdout redirected the detector answers NoTty
 * before it even reads the variables, so the piped output carries no
 * escape bytes at all — copy-and-paste from logs stays clean.
 *
 *   php examples/no-color.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;

$profile = ColorProfile::detect(null, STDOUT);

foreach (['NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR', 'TERM', 'COLORTERM'] as $var) {
    printf("%-14s %s\n", $var . ':', getenv($var) === false ? '(unset)' : getenv($var));
}

printf("negotiated:     %s\n\n", $profile->name);

$label = Style::withDefaultProfile(
    $profile,
    static fn(): string => Style::new()
        ->foreground(Color::hex('#6123ac'))
        ->bold(true)
        ->render('No color for me')
);

printf("rendered label: [%s]\n", $label);
printf("escape bytes:   %d\n", preg_match_all("/\033\[[0-9;]*m/", $label));
