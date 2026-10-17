<?php

declare(strict_types=1);

/**
 * Deterministic mini-TUI fixture for PtyRunToolTest — the stand-in for
 * "drive a real terminal program from the tool".
 *
 * WHY A HOME-GROWN FIXTURE: external curses programs (vim, less, btop) vary
 * across hosts, paint alternate-screen sequences that make byte assertions
 * brittle, and may not exist at all. This fixture speaks the same CONTRACT
 * candy-code's own TUI does — read the ctty winsize, paint a frame sized to
 * it, move a selection on arrow keys, quit on `q` — with plain-text output, so
 * the test pins (frame width == cols, SEL advances on <Down>, BYE on exit)
 * prove the real mechanisms: winsize-before-draw, keystroke delivery, screen
 * capture, teardown. The same assertions would hold for a second candy-code
 * session; that one needs provider config and network, so this stands in.
 *
 * Terminal mode: `stty -icanon -echo -isig` — NOT `raw`, because raw clears
 * opost/onlcr and this file's plain "\n" paint would staircase (the
 * tools/ansi-view lesson). Echo off keeps typed keys out of the transcript;
 * isig off keeps stray control bytes from killing the session before the
 * driver's own teardown.
 *
 * Prints exactly: "COLS=<n> ROWS=<m>" then a frame whose border row is <n>
 * characters wide, "| SEL=<i> |" content row. <Up>/<Left> decrement,
 * <Down>/<Right> increment the selection (clamped 0..9); `q` prints BYE and
 * exits 0. Self-exits 9 after 120 s so a test that forgets to drive it still
 * ends.
 */

// ctty geometry via stty on stdin — the same ground truth a shell-driven
// program reads. Fall back to 80x24 (never fatal: this file may also be run
// detached while someone pokes at it by hand).
$cols = 80;
$rows = 24;
$size = @shell_exec('stty size 2>/dev/null');
if (\is_string($size) && \preg_match('/^(\d+)\s+(\d+)/', \trim($size), $m) === 1) {
    $rows = (int) $m[1];
    $cols = (int) $m[2];
}

\stream_set_write_buffer(\STDOUT, 0);
@shell_exec('stty -icanon -echo -isig 2>/dev/null');
\stream_set_blocking(\STDIN, false);

$sel = 0;
$startedAt = \microtime(true);

/**
 * Paint the frame at the born-with width.
 */
$paint = static function (int $sel) use ($cols): void {
    $border = '+' . \str_repeat('-', \max(1, $cols - 2)) . '+';
    $inner = '| SEL=' . $sel . ' |';
    echo $border . "\n";
    echo \str_pad($inner, $cols, ' ') . "\n";
    echo $border . "\n";
};

echo 'COLS=' . $cols . ' ROWS=' . $rows . "\n";
$paint($sel);

$buffer = '';

while (true) {
    if (\microtime(true) - $startedAt > 120.0) {
        echo "LIFETIME\n";
        exit(9);
    }

    $byte = \fread(\STDIN, 8);
    if ($byte === false || $byte === '') {
        \usleep(10000);

        continue;
    }

    $buffer .= $byte;

    while ($buffer !== '') {
        if ($buffer[0] === "\x1b") {
            if (\strlen($buffer) < 3) {
                // Partial escape: wait for the rest (bytes arrive in one
                // write from the driver, this is belt-and-braces).
                \usleep(5000);
                $more = \fread(\STDIN, 8);
                if (\is_string($more) && $more !== '') {
                    $buffer .= $more;

                    continue;
                }

                break;
            }

            $seq = \substr($buffer, 0, 3);
            $buffer = \substr($buffer, 3);
            $before = $sel;

            if ($seq === "\x1b[A" || $seq === "\x1b[D") {
                $sel = \max(0, $sel - 1);
            } elseif ($seq === "\x1b[B" || $seq === "\x1b[C") {
                $sel = \min(9, $sel + 1);
            }

            if ($sel !== $before) {
                $paint($sel);
            }

            continue;
        }

        $char = $buffer[0];
        $buffer = \substr($buffer, 1);

        if ($char === 'q') {
            echo "BYE\n";
            exit(0);
        }
    }
}
