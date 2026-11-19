<?php

declare(strict_types=1);

/**
 * Version ordering and sidecar parsing, the two pure helpers the update
 * engine leans on. Version compares strict X.Y.Z (a leading v is allowed) and
 * answers null when it cannot order, which is the fail-closed refusal rather
 * than a guess. ReleaseSelector reads the .sha256 sidecar the same way the
 * swap does.
 *
 *   php examples/version-ordering.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Releases\ReleaseSelector;
use SugarCraft\Releases\Version;

$pairs = [
    ['v1.10.0', 'v1.9.9'],   // numeric, not lexical: 10 > 9
    ['1.2.3', 'v1.2.3'],     // the leading v is optional
    ['1.2', '1.2.0'],        // not X.Y.Z: cannot order
    ['dev-abc1234', 'v1.0.0'],
];

foreach ($pairs as [$left, $right]) {
    $verdict = Version::compare($left, $right);
    printf(
        "%-12s vs %-8s => %s\n",
        $left,
        $right,
        match ($verdict) {
            -1 => 'older',
            0 => 'equal',
            1 => 'newer',
            default => 'cannot compare',
        },
    );
}

echo "\n";
echo 'isDev(dev-abc1234):    ' . var_export(Version::isDev('dev-abc1234'), true) . "\n";
echo 'devShaOf(dev-abc1234): ' . Version::devShaOf('dev-abc1234') . "\n";
echo 'devShaOf(v1.0.0):      ' . var_export(Version::devShaOf('v1.0.0'), true) . "\n";

// A .sha256 sidecar is "<64 lowercase hex>  <file name>" as sha256sum writes it.
$sidecar = \hash('sha256', 'phar bytes') . "  widget.phar\n";
echo "\n";
echo 'digest: ' . ReleaseSelector::parseSidecar($sidecar) . "\n";
echo 'name:   ' . ReleaseSelector::sidecarName($sidecar) . "\n";

// Uppercase hex, a short digest or a bare name is refused, never normalized.
echo 'uppercase refused: ' . var_export(ReleaseSelector::parseSidecar(strtoupper($sidecar)), true) . "\n";
echo 'short refused:     ' . var_export(ReleaseSelector::parseSidecar("abc123  widget.phar\n"), true) . "\n";
