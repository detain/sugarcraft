<?php

declare(strict_types=1);

/**
 * SugarToast — floating alert notifications demo.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Toast\{Position, SymbolSet, Toast, ToastType};

// Background — simulate an app viewport
$bgLines = [];
for ($i = 0; $i < 20; $i++) {
    $bgLines[] = "  Application content line " . ($i + 1);
}
$bg = \implode("\n", $bgLines);

// Build toast — the last message simulates bytes from a remote service:
// attacker-controlled text must arrive at alert() already sanitised (see
// README Security, "the terminal is a sink"). untrusted() strips control
// and escape constructs; reach for untrustedForMarkedFrames() when the
// host layers its own zone markers over the frame.
$remoteError = Sanitize::untrusted("Failed to load plugin:\x1b[2J missing dependency");

$t = Toast::new(50)
    ->withPosition(Position::TopLeft)
    ->withSymbolSet(SymbolSet::Unicode)
    ->info('Application started')
    ->success('Connected to server')
    ->warning('Config file not found, using defaults')
    ->error($remoteError);

echo "=== Toast at TopLeft ===\n";
echo $t->view($bg, 80, 20) . "\n\n";

// Different position
$t2 = Toast::new(50)
    ->withPosition(Position::TopRight)
    ->withSymbolSet(SymbolSet::Ascii)
    ->success('File saved')
    ->error('Connection lost!');

echo "=== Toast at TopRight (ASCII symbols) ===\n";
echo $t2->view($bg, 80, 20) . "\n";

// Bottom-center
$t3 = Toast::new(40)
    ->withPosition(Position::BottomCenter)
    ->info('System ready');

echo "\n=== Toast at BottomCenter ===\n";
echo $t3->view($bg, 80, 20) . "\n";
