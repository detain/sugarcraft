<?php

declare(strict_types=1);

/**
 * SugarReadline — interactive TTY demo via sugar-input.
 *
 * Demonstrates real TTY keypress handling: arrow keys, Ctrl+chars,
 * Escape, and bracketed paste — through Readline::interactive(), which
 * flips the terminal out of canonical mode around the loop and restores it
 * on every exit path (cooked discipline would eat Enter, Backspace and
 * Ctrl+ chords; see the README's "Terminal mode" section).
 *
 * Run: php examples/interactive.php
 * (Requires a TTY — on a pipe the raw-mode step is skipped and the loop
 * exits at EOF instead of spinning.)
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\History\InMemoryHistory;

echo "=== Interactive Readline Demo ===\n";
echo "Try: type text, arrow keys, Ctrl+A/E, Ctrl+R search, Tab, Enter to submit, Esc to abort\n";
echo "Paste: select text and paste (bracketed paste mode)\n";
echo "Type 'quit' to exit.\n\n";

// Raw-mode handling is the library's job: Readline::interactive() around
// each run captures the `stty -g` snapshot, flips -icanon -echo -isig -ixon
// for the duration (Ctrl+C and Ctrl+S must arrive as ordinary bytes — the
// README's "Terminal mode" section explains why each flag is load-bearing),
// and restores on every exit path, shutdown backstop included. Passing the
// configured $readline keeps the owned STDIN pump — and its already-decoded
// trailing bytes — alive between iterations.

$history = new InMemoryHistory();

$readline = Readline::fromStdin()
    ->onKey('ctrl_c', function ($event) {
        // Notification only; the prompt aborts through its own binding.
        echo "\n[Ctrl+C — abort]\n";
    })
    ->onPaste(function ($event) {
        echo "\n[pasted: " . strlen($event->content) . " chars]\n";
    })
    ->onFocus(function ($event) {
        echo "\n[" . ($event->gained ? 'focus gained' : 'focus lost') . "]\n";
    });

// Simple interactive loop. run() paints the prompt's view() as it changes;
// pass STDOUT or nothing is drawn.
$prompt = TextPrompt::new('> ')->withHistory($history);

while (true) {
    $prompt = Readline::interactive($prompt, STDOUT, $readline);

    if ($prompt->isAborted()) {
        echo "\n[aborted — empty input]\n";
        break;
    }

    if (!$prompt->isSubmitted()) {
        // True EOF: the owned pump has nothing left to read.
        echo "\n[end of input]\n";
        break;
    }

    $value = $prompt->value();
    echo "\n[submitted: '$value']\n";

    if ($value === 'quit') {
        echo "Goodbye!\n";
        break;
    }

    $prompt = TextPrompt::new('> ')->withHistory($history);
}
