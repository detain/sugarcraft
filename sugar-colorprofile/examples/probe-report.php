<?php

/**
 * Probe report demo — the phased capability surfaces.
 *
 * Run: php examples/probe-report.php
 *
 * This exercises:
 * - Probe\TerminalProbe with a hermetic env map (CP-03's semantics: a
 *   non-empty injected map is a COMPLETE replacement, so the demo answers
 *   are reproducible on any host)
 * - ProbeReport: allWithSource() (every capability WITH the rung that minted
 *   it), has()/source() point lookups, colorDescription() prose
 * - the report-to-vocabulary bridge toColorProfile() and what
 *   ColorProfile::degradedTo() says about the resulting rung
 *
 * The run never fails on machines without an infocmp child: a missing
 * binary just degrades the phase-2 answer (that is the documented contract).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe\TerminalProbe;

$hermetic = new TerminalProbe(['TERM' => 'xterm-256color']);
$report = $hermetic->runProbe();

echo "=== TerminalProbe against {TERM: xterm-256color} (hermetic) ===\n\n";

$capacities = $report->allWithSource();
if ($capacities === []) {
    echo "  (no capabilities reported)\n";
}
foreach ($capacities as $capability => $source) {
    printf("  %-14s %s\n", $capability, $source ?? 'unknown');
}

echo "\nPoint lookups: TrueColor=" . var_export($report->hasTrueColor(), true)
    . " Color256=" . var_export($report->hasColor256(), true)
    . " NoColor=" . var_export($report->hasNoColor(), true) . "\n";

echo "\nProse       : " . $report->colorDescription() . "\n";
echo "Vocabulary  : " . $report->toColorProfile()->label() . "\n";
echo "Degradation : " . describeWalk($report->toColorProfile()) . "\n";

// A second hermetic run showing the suppression rung: NO_COLOR routes to the
// colorless Ascii answer (CP-05), not to the hardware-absence NoTTY rung.
$suppressed = (new TerminalProbe(['TERM' => 'xterm-256color', 'NO_COLOR' => '1']))->runProbe();
echo "\n=== Same TERM with NO_COLOR=1 ===\n";
echo "Prose       : " . $suppressed->colorDescription() . "\n";
echo "Vocabulary  : " . $suppressed->toColorProfile()->label() . " (decoration survives there)\n";

/**
 * Walk the degradation chain so the report's answer lands in context.
 */
function describeWalk(ColorProfile $start): string
{
    $names = [];
    for ($cursor = $start; $cursor !== null; $cursor = $cursor->degradedTo()) {
        $names[] = $cursor->label(); // NoTTY is the terminal rung: degradedTo() answers null there
    }
    $names[] = 'plain text';

    return implode(' -> ', $names);
}
