<?php

declare(strict_types=1);

/**
 * SugarReadline — scripted walkthrough of every prompt form.
 *
 * Every keypress is pre-fed through handleChar()/handleKey(); nothing reads
 * STDIN, so the demo runs identically on a pipe, in CI, or on a terminal.
 * (For a real TTY loop see interactive.php.)
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Readline\{ConfirmationPrompt, Key, SelectionPrompt, TextareaPrompt, TextPrompt};

// ---- Text Prompt ----
echo "=== Text Prompt ===\n";
$p = TextPrompt::new('Your name: ')
    ->withDefault('Anonymous')
    ->withCompletions(['Alice', 'Bob', 'Carol', 'Dave']);
echo $p->view() . "\n\n";

$p = TextPrompt::new('Your name: ')
    ->withCompletions(['Alice', 'Bob', 'Carol', 'Dave'])
    ->handleChar('A')->handleChar('l');
echo "After typing 'Al':\n" . $p->view() . "\n\n";

$p = $p->handleKey(Key::Tab);
echo "After Tab-completion:\n" . $p->view() . "\n\n";

// ---- Selection Prompt ----
echo "=== Selection Prompt ===\n";
$s = SelectionPrompt::new('Pick a fruit:', ['Apple', 'Banana', 'Cherry', 'Date', 'Elderberry']);
echo $s->view() . "\n\n";

$s = $s->withFilter('er');
echo "After filter 'er':\n" . $s->view() . "\n\n";

$s = $s->handleKey(Key::Down)->submit();
echo "Selected (after filter 'er', cursor moved Down once): " . $s->selectedValue() . "\n\n";

// ---- Confirmation Prompt ----
echo "=== Confirmation Prompt ===\n";
$c = ConfirmationPrompt::new('Delete the file?')
    ->withConfirmLabel('Yes, delete')
    ->withCancelLabel('No, keep');
echo $c->view() . "\n";
$c = $c->handleKey('n')->submit();
echo "Result: " . ($c->result() ? 'confirmed' : 'cancelled') . "\n\n";

// ---- Textarea Prompt ----
echo "=== Textarea Prompt ===\n";
$t = TextareaPrompt::new('Description:')
    ->withMaxLines(5)
    ->withDefault("Line one\nLine two");
echo $t->view() . "\n";
echo "Value:\n" . $t->value() . "\n";
