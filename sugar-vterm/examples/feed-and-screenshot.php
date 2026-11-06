<?php

declare(strict_types=1);

/**
 * Feed an ANSI byte stream into a virtual terminal, then print a
 * cell-grid screenshot showing what the screen would look like —
 * with cursor position and window title — without ever touching a
 * real PTY.
 *
 *   php examples/feed-and-screenshot.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Vterm\Terminal\Terminal;

$bytes = "\x1b]2;SugarVterm demo\x07"            // OSC 2: window title
       . "\x1b[2J\x1b[H"                       // ED 2J + cursor home
       . "\x1b[1;38;5;213m  SugarVterm\x1b[0m\r\n"  // bold, 256-color magenta
       . "\x1b[38;5;245m  --------\x1b[0m\r\n\r\n" // dim divider
       . "\x1b[1mWelcome\x1b[0m, "             // bold "Welcome"
       . "\x1b]8;id=1;https://github.com/charmbracelet/x/tree/main/vt\x07"
       . "\x1b[4;38;5;39mclick here\x1b[0m"    // underlined blue hyperlink
       . "\x1b]8;;\x07"                        // close hyperlink
       . " \xe2\x86\x92 source.\r\n\r\n"       // " → source.\r\n\r\n"
       . "\x1b[38;5;208m  PR1-PR9 shipped \xe2\x9c\x94\x1b[0m"; // orange "PR1-PR9 shipped ✔"

$term = Terminal::new(cols: 40, rows: 8);
$term->feed($bytes);

// windowTitle() is VERBATIM attacker text (README §Trust boundary): route
// through Sanitize::untrusted() before echoing, exactly as any consumer must.
echo "Window title: " . Sanitize::untrusted($term->windowTitle() ?? '(none)') . "\n";
echo "Cursor:       row=" . $term->cursor()->row . ", col=" . $term->cursor()->col . "\n";
echo "Screen (cell grid, plain text):\n";
echo str_repeat('-', $term->screen()->cols + 2) . "\n";

$screen = $term->screen();
foreach ($screen->lines() as $row => $line) {
    // Grid rows are decoded graphemes only, but the consumer law is
    // uniform: anything that came out of an untrusted feed passes
    // Sanitize::untrusted() before it is echoed to a real terminal.
    echo '|' . Sanitize::untrusted($line) . '|' . "\n";
}
echo str_repeat('-', $screen->cols + 2) . "\n\n";

// Cells styled with bold inherit their SGR — show a few specific ones:
echo "Highlights:\n";
$cell = $screen->cell(0, 2);
echo "  (0,2) grapheme=" . json_encode($cell->grapheme)
   . " bold=" . ($cell->sgr()->bold ? 'true' : 'false')
   . " fg=" . ($cell->foreground() ? "kind={$cell->foreground()->kind} value={$cell->foreground()->value}" : 'default')
   . "\n";

// Find a cell inside the hyperlink span.
for ($c = 0; $c < $screen->cols; $c++) {
    $cell = $screen->cell(3, $c);
    if ($cell->hyperlink !== null) {
        // Cell::\$hyperlink keeps the OSC 8 URI VERBATIM (Stored strings
        // bullet): the parser records it, the consumer sanitises before
        // display or onward propagation.
        echo "  Hyperlink found at row=3 col=$c -> " . Sanitize::untrusted($cell->hyperlink->uri) . "\n";
        break;
    }
}
