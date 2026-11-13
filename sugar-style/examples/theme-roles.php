<?php

declare(strict_types=1);

/**
 * Theme roles — the role→Style registry and the injectable darkness seam.
 *
 *   php examples/theme-roles.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Style\Style;
use SugarCraft\Style\Theme;

// A caller that already knows the polarity (OSC-11 probe, host setting,
// stored preference) picks the palette without touching the environment.
$theme = Theme::for(isDark: true);

// Bind semantic roles once; widgets read them by name instead of each
// re-implementing the theme glue.
$theme = $theme
    ->withStyle('title', Style::new()->bold(true)->foreground($theme->primary))
    ->withStyle('hint', Style::new()->foreground($theme->muted));

$title = $theme->style('title') ?? Style::new();
$hint = $theme->style('hint') ?? Style::new();

echo $title->render('Theme roles') . "\n";
echo $hint->render('Bindings keep widgets free of palette knowledge.') . "\n";

// Removal is copy-on-write: the original theme keeps its bindings.
$stripped = $theme->withStyle('hint', null);
echo 'hint on the original: ' . var_export($theme->style('hint') !== null, true) . "\n";
echo 'hint after removal:   ' . var_export($stripped->style('hint') !== null, true) . "\n";
