<?php

declare(strict_types=1);

/**
 * SugarReadline — interactive TTY demo via candy-input.
 *
 * Demonstrates real TTY keypress handling: arrow keys, Ctrl+chars,
 * Escape, F-keys, and bracketed paste.
 *
 * Run: php examples/interactive.php
 * (Requires a TTY — won't work in non-interactive environments)
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\History\InMemoryHistory;

echo "=== Interactive Readline Demo ===\n";
echo "Try: type text, arrow keys, Ctrl+A/E, Tab, Enter to submit, Esc to abort\n";
echo "Paste: select text and paste (bracketed paste mode)\n";
echo "Type 'quit' to exit.\n\n";

// Use Readline with real TTY input
$history = new InMemoryHistory();

$readline = Readline::fromStdin()
    ->onKey('ctrl_c', function ($event) {
        echo "\n[Ctrl+C — abort]\n";
    })
    ->onPaste(function ($event) {
        echo "\n[pasted: " . strlen($event->content) . " chars]\n";
    })
    ->onFocus(function ($event) {
        echo "\n[" . ($event->gained ? 'focus gained' : 'focus lost') . "]\n";
    });

// Simple interactive loop
$running = true;
$prompt = TextPrompt::new('> ')
    ->withHistory($history);

echo $prompt->view() . "\n";

while ($running) {
    $result = $readline->run($prompt);

    if ($result->isAborted()) {
        echo "\n[aborted — empty input]\n";
        $prompt = TextPrompt::new('> ')->withHistory($history);
        echo $prompt->view() . "\n";
        continue;
    }

    if ($result->isSubmitted()) {
        $value = $result->value();
        echo "\n[submitted: '$value']\n";

        if ($value === 'quit') {
            echo "Goodbye!\n";
            $running = false;
        } else {
            echo $prompt->view() . "\n";
            $prompt = TextPrompt::new('> ')
                ->withHistory($history);
            echo $prompt->view() . "\n";
        }
    }
}
