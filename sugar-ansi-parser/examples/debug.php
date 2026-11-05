<?php

declare(strict_types=1);

/**
 * Debug parser demo.
 *
 * Walks one representative stream per `Handler` event family with a fresh
 * DebugHandler each time and prints the resulting action log. The
 * adapter-side semantics (the `HandlerAdapter` sieve, the `$fallback` escape
 * hatch, DECSET multi-mode fan-out) live in `examples/adapter.php`.
 *
 * Run: php examples/debug.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\Parser;

/** Feed one stream through a fresh Parser + DebugHandler and echo the rows. */
function show(string $family, string $stream): void
{
    echo "== {$family}\n";
    echo "   stream: " . addcslashes($stream, "\x00-\x1f\x7f-\x9f") . "\n";

    $handler = new DebugHandler();
    (new Parser($handler))->parseComplete($stream);

    foreach ($handler->log as $entry) {
        $detail = is_array($entry['detail']) || is_string($entry['detail'])
            ? var_export($entry['detail'], true)
            : (string) $entry['detail'];
        echo "   -> [{$entry['type']}] {$detail}\n";
    }
    echo "\n";
}

// printChar + csiDispatch: the SGR round trip from the quickstart.
show('print + CSI (SGR)', "hello\x1b[31mworld\x1b[0m");

// execute: lone control bytes in the ground state.
show('execute', "\x07\x08");

// escDispatch: the C1 ESC family.
show('ESC (RI)', "\x1bM");

// oscDispatch: OSC 2 window title, BEL-terminated.
show('OSC (title)', "\x1b]0;window title\x07");

// oscDispatch: OSC 8 hyperlinks arrive RAW on this path — the adapter (not
// the parser) is what decodes `8;params;uri`; see examples/adapter.php.
show('OSC 8 (raw payload)', "\x1b]8;id=x;https://example.org\x1b\\");

// dcsDispatch: `ESC P 1 q ; ST` — params [1], final 'q' (113), body ";".
show('DCS', "\x1bP1q;\x1b\\");

// sosPmApcDispatch: an APC frame logs under its kind ('apc' here).
show('APC (kitty frame shape)', "\x1b_Gf=1;AAA\x1b\\");

// C1 introducer: 0x9D is OSC, 0x9C is ST — no ESC needed, same dispatch.
show('OSC via C1 introducer', "\x9d0;title via C1\x9c");
