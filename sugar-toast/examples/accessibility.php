<?php

declare(strict_types=1);

/**
 * sugar-toast — accessibility surface demo.
 *
 * withAccessibleText() adds a plain-text severity word beside the colour
 * and glyph channels, so an alert still reads when both are lost. The
 * motion and colour environment variables are honoured at paint time:
 * REDUCE_MOTION / PREFERS_REDUCED_MOTION drop blink; NO_COLOR and
 * CLICOLOR=0 strip colour bytes (CLICOLOR_FORCE overrides). The checks
 * are environment-only — sugar-toast composites for its host and never
 * probes a TTY itself — so this file drives them with putenv around each
 * render, the same effect your terminal's settings have in real use.
 *
 * Run: php examples/accessibility.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Toast\{Position, SymbolSet, Toast, ToastType};

$bgLines = [];
for ($i = 0; $i < 20; $i++) {
    $bgLines[] = "  Application content line " . ($i + 1);
}
$bg = \implode("\n", $bgLines);

$base = Toast::new(50)
    ->withPosition(Position::TopLeft)
    ->withSymbolSet(SymbolSet::Unicode)
    ->alert(ToastType::Warning, 'Disk usage at 85%');

echo "=== Accessible text OFF — severity carried by icon + colour ===\n";
echo $base->view($bg, 80, 20) . "\n\n";

echo "=== Accessible text ON — plain severity word rides beside the channel ===\n";
echo $base->withAccessibleText()->view($bg, 80, 20) . "\n\n";

echo "=== NO_COLOR — every colour byte suppressed; glyphs and layout survive ===\n";
putenv('NO_COLOR=1');
echo $base->view($bg, 80, 20) . "\n\n";
putenv('NO_COLOR');

echo "=== REDUCE_MOTION — blink dropped, layout unchanged ===\n";
putenv('REDUCE_MOTION=1');
echo $base->view($bg, 80, 20) . "\n";
putenv('REDUCE_MOTION');
