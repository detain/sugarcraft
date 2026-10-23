<?php

declare(strict_types=1);

/**
 * SugarReadline — interactive TTY demo via sugar-input.
 *
 * Demonstrates real TTY keypress handling: arrow keys, Ctrl+chars,
 * Escape, and bracketed paste — with the terminal put in non-canonical
 * mode so those bytes reach the prompt at all (cooked line discipline
 * would eat Enter, Backspace and Ctrl+ chords; see the README's
 * "Terminal mode" section).
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

// Raw-ish mode: keep the line discipline out of the way, restore on the way
// out. `stty -g` prints a settings string that re-applies everything as-is —
// note the format is implementation-specific (GNU single tokens like -icanon,
// BSD/macOS space-separated words), so this save/restore pair stays on one
// machine within one run.
$isTty = stream_isatty(STDIN);
$savedMode = '';
if ($isTty) {
    $savedMode = trim((string) shell_exec('stty -g'));
    shell_exec('stty -icanon -echo min 1 time 0');
    register_shutdown_function(static function () use ($isTty, $savedMode): void {
        if ($isTty && $savedMode !== '') {
            shell_exec('stty ' . escapeshellarg($savedMode));
        }
    });
}

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
    $prompt = $readline->run($prompt, STDOUT);

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
