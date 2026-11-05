<?php

declare(strict_types=1);

/**
 * Interactive focus-ring demo.
 *
 * Registers four labeled panels in a {@see FocusRing} and draws them side by
 * side. The focused panel gets a bright accent border; Tab advances focus
 * (Shift-Tab / Backtab reverses it) and the ring wraps around at either end.
 * Press x to toggle whether the focused panel takes part in traversal — the
 * accent follows `isFocused() && isEnabled()`, so a parked focus on a
 * disabled panel shows grey. Press q to quit.
 *
 *   php examples/focus-ring.php
 *
 * sugar-focus itself carries no rendering — this example hand-rolls the ANSI so
 * the library stays dependency-free. It shows the one job the ring has: "which
 * panel has focus", moving as Tab is pressed.
 *
 * Note: every redraw homes and clears the whole screen (`ESC [ H` + `ESC [ 2J`),
 * which flickers on slow terminals — and the recorded demo GIF moves by the same
 * mechanism; a site-side reduced-motion rule cannot halt an autoplaying GIF.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\Focus\FocusRing;

$regions = ['Sidebar', 'Content', 'Filters', 'Status'];
$ring    = FocusRing::of(...$regions);

$esc   = "\x1b";
$reset = "{$esc}[0m";

/**
 * Build one panel as an array of text rows. A panel drawn with the bright
 * cyan border + bold label is the FOCUSED **and ENABLED** one — the same
 * `isFocused() && isEnabled()` conjunction the README tells consumers to
 * paint with, so a focus parked on a disabled panel stays grey.
 *
 * @return list<string>
 */
$panel = static function (string $label, bool $focused, bool $enabled) use ($esc, $reset): array {
    $inner  = 13;
    $lit    = $focused && $enabled;
    $border = $lit ? "{$esc}[1;96m" : "{$esc}[90m";
    $title  = $lit ? "{$esc}[1;97m" : "{$esc}[37m";
    $mark   = $lit ? "{$esc}[96m"   : "{$esc}[90m";

    $labelRow  = str_pad('  ' . $label, $inner);
    $statusRow = str_pad(
        $focused ? ($enabled ? '  > FOCUS' : '  > parked') : ($enabled ? '  idle' : '   off'),
        $inner,
    );
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
    $out .= "{$esc}[1msugar-focus — FocusRing traversal{$reset}\n";
    $out .= "{$esc}[90mTab: next   Shift-Tab: previous   x: toggle focusable   q: quit{$reset}\n\n";

    $panels = array_map(
        static fn(string $id): array => $panel($id, $ring->isFocused($id), $ring->isEnabled($id)),
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
    $footer  = 'region ' . ($ring->index() + 1) . ' of ' . $ring->count();
    if ($ring->disabledCount() > 0) {
        $footer .= ', enabled ' . $ring->enabledCount();
    }
    $out .= "\n{$esc}[96m● focused: {$current}{$reset}  {$esc}[90m({$footer}){$reset}\n";

    fwrite(STDOUT, $out);
};

// Non-interactive fallback (piped stdin / no TTY): draw one frame and exit so
// the script never blocks on a read that can't arrive.
if (!stream_isatty(STDIN)) {
    $render($ring);
    exit(0);
}

$sttyState = trim((string) shell_exec('stty -g 2>/dev/null'));
$restored  = false;
$restore   = static function () use ($sttyState, &$restored): void {
    // Idempotent: reached both by the explicit call at the end of the loop and
    // by the shutdown handler below.
    if ($restored) {
        return;
    }
    $restored = true;

    // Use proc_open with array args to avoid string concatenation that Codacy
    // flags as command-injection risk, even though escapeshellarg is correct.
    //
    // fd0 must be a tty: plain `stty` configures its OWN standard input, so the
    // previous '/dev/null' made every restore attempt die on "Inappropriate
    // ioctl for device" with the status ignored — the arm below took effect on
    // the real terminal while this countermand never reached it, and the shell
    // was left with echo off. '/dev/tty' is this process' controlling terminal,
    // which exists here because the stream_isatty(STDIN) guard above lets only
    // interactive runs reach this point. (GNU spells the file option -F, BSD
    // -f; /dev/tty avoids the dialect split entirely.)
    $pipes = [];
    $process = proc_open(
        $sttyState !== '' ? ['stty', $sttyState] : ['stty', 'sane'],
        [['file', '/dev/tty', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );
    if (is_resource($process)) {
        proc_close($process);
    }
    fwrite(STDOUT, "\x1b[?25h"); // ensure the cursor is visible again
};
register_shutdown_function($restore);

// Raw-ish mode so Tab is seen immediately (no Enter). -isig matters as much as
// -echo here: with signals still armed, Ctrl+C raises SIGINT, PHP dies mid-read
// and the shutdown restore never runs — the very broken-terminal story the
// comment above exists to prevent. With -isig, Ctrl+C arrives as an ordinary
// byte that the loop below quits on, exiting through the same orderly path as
// 'q'. (The `stty -g` capture above predates this arm, so the restore pair
// covers the isig flip too.)
shell_exec('stty -echo -icanon -isig min 1 time 0 2>/dev/null');
fwrite(STDOUT, "\x1b[?25l"); // hide the cursor while animating

$render($ring);

$buffer = '';
while (true) {
    // Read into a persistent buffer and consume whole events from it. Matching
    // each fread() result exactly lost two real shapes: two Tabs typed quickly
    // arrive as one "\t\t" read (neither "\t" nor "\x1b[Z" — both presses
    // dropped), and a shift-Tab straddling a read boundary ("\x1b[" then "Z")
    // lost both halves.
    $chunk = fread(STDIN, 8);
    if ($chunk === '' || $chunk === false) {
        // A blocking `min 1` read returns 0 bytes/false only on hangup; the
        // previous `continue` spun hot forever once the tty closed.
        break;
    }
    $buffer .= $chunk;

    while ($buffer !== '') {
        if ($buffer[0] === "\x1b") {
            if (strlen($buffer) === 1) {
                break; // lone ESC so far — wait for the rest of the sequence
            }
            if (str_starts_with($buffer, "\x1b[Z")) {
                $ring = $ring->previous();
                $buffer = substr($buffer, 3);
                $render($ring);
                continue;
            }
            // Any other ESC-led byte: drop just the ESC and re-enter on the
            // remainder, so a stray ESC before 'q' cannot eat the quit.
            $buffer = substr($buffer, 1);
            continue;
        }

        $byte = $buffer[0];
        $buffer = substr($buffer, 1);
        if ($byte === 'q' || $byte === "\x03" || $byte === "\x04") {
            break 2; // 'q', Ctrl-C or Ctrl-D (all delivered as bytes — see -isig above)
        }
        if ($byte === 'x') {
            // Toggle the focused region's participation. Disabling it parks
            // the focus there — the panel goes grey and reads "parked" — and
            // the next Tab demonstrates traversal skipping the disabled id.
            $focused = $ring->current();
            if ($focused !== null) {
                $ring = $ring->isEnabled($focused)
                    ? $ring->disable($focused)
                    : $ring->enable($focused);
                $render($ring);
            }
            continue;
        }
        if ($byte === "\t") {
            $ring = $ring->next();
            $render($ring);
        }
    }
}

$restore();
fwrite(STDOUT, "\n");
