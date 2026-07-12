<?php

declare(strict_types=1);

/**
 * Interactive focus-ring demo.
 *
 * Registers four labeled panels in a {@see FocusRing} and draws them side by
 * side. The focused panel gets a bright accent border; Tab advances focus
 * (Shift-Tab / Backtab reverses it) and the ring wraps around at either end.
 * Press q to quit.
 *
 *   php examples/focus-ring.php
 *
 * candy-focus itself carries no rendering — this example hand-rolls the ANSI so
 * the library stays dependency-free. It shows the one job the ring has: "which
 * panel has focus", moving as Tab is pressed.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\Focus\FocusRing;

$regions = ['Sidebar', 'Content', 'Filters', 'Status'];
$ring    = FocusRing::of(...$regions);

$esc   = "\x1b";
$reset = "{$esc}[0m";

/**
 * Build one panel as an array of text rows. The focused panel is drawn with a
 * bold bright-cyan border + label; the rest are dimmed grey.
 *
 * @return list<string>
 */
$panel = static function (string $label, bool $focused) use ($esc, $reset): array {
    $inner  = 13;
    $border = $focused ? "{$esc}[1;96m" : "{$esc}[90m";
    $title  = $focused ? "{$esc}[1;97m" : "{$esc}[37m";
    $mark   = $focused ? "{$esc}[96m"   : "{$esc}[90m";

    $labelRow  = str_pad('  ' . $label, $inner);
    $statusRow = str_pad('  ' . ($focused ? '> FOCUS' : 'idle'), $inner);
    $line      = str_repeat('─', $inner);

    return [
        "{$border}┌{$line}┐{$reset}",
        "{$border}│{$reset}{$title}{$labelRow}{$reset}{$border}│{$reset}",
        "{$border}│{$reset}{$mark}{$statusRow}{$reset}{$border}│{$reset}",
        "{$border}└{$line}┘{$reset}",
    ];
};

$render = static function (FocusRing $ring) use ($regions, $panel, $esc, $reset): void {
    // Home the cursor and clear the screen so each redraw overwrites the last.
    $out = "{$esc}[H{$esc}[2J";
    $out .= "{$esc}[1mcandy-focus — FocusRing traversal{$reset}\n";
    $out .= "{$esc}[90mTab: next   Shift-Tab: previous   q: quit{$reset}\n\n";

    $panels = array_map(
        static fn(string $id): array => $panel($id, $ring->isFocused($id)),
        $regions,
    );

    for ($row = 0; $row < 4; $row++) {
        $line = '';
        foreach ($panels as $p) {
            $line .= $p[$row] . '  ';
        }
        $out .= $line . "\n";
    }

    $current = $ring->current() ?? '(none)';
    $out .= "\n{$esc}[96m● focused: {$current}{$reset}  {$esc}[90m(region " .
        ($ring->index() + 1) . ' of ' . $ring->count() . "){$reset}\n";

    fwrite(STDOUT, $out);
};

// Non-interactive fallback (piped stdin / no TTY): draw one frame and exit so
// the script never blocks on a read that can't arrive.
if (!stream_isatty(STDIN)) {
    $render($ring);
    exit(0);
}

$sttyState = trim((string) shell_exec('stty -g 2>/dev/null'));
$restore   = static function () use ($sttyState): void {
    if ($sttyState !== '') {
        shell_exec('stty ' . escapeshellarg($sttyState) . ' 2>/dev/null');
    } else {
        shell_exec('stty sane 2>/dev/null');
    }
    fwrite(STDOUT, "\x1b[?25h"); // ensure the cursor is visible again
};
register_shutdown_function($restore);

// Raw, unbuffered single-byte reads so Tab is seen immediately (no Enter).
shell_exec('stty -echo -icanon min 1 time 0 2>/dev/null');
fwrite(STDOUT, "\x1b[?25l"); // hide the cursor while animating

$render($ring);

while (true) {
    $chunk = fread(STDIN, 8);
    if ($chunk === '' || $chunk === false) {
        continue;
    }

    // 'q' or Ctrl-C ends the demo.
    if ($chunk[0] === 'q' || $chunk[0] === "\x03") {
        break;
    }

    if ($chunk === "\t") {
        $ring = $ring->next();
        $render($ring);
        continue;
    }

    // ESC [ Z is Backtab — what most terminals send for Shift-Tab.
    if ($chunk === "\x1b[Z") {
        $ring = $ring->previous();
        $render($ring);
        continue;
    }
}

$restore();
fwrite(STDOUT, "\n");
