<?php

declare(strict_types=1);

/**
 * Shared interactive-demo preamble for every examples/*.php script
 * (sc-44, finding sugar-core-078).
 *
 * The demos drive raw mode, altscreen and SGR machinery against a terminal.
 * Two shapes of terminal-less invocation used to misbehave:
 *
 *  - TERM=dumb: the escape machinery was still armed and wrote its noise
 *    (cursor hides, alt-screen switches, colour SGRs) to a terminal that is
 *    defined as NOT understanding them. The guard prints the one honest line
 *    such a terminal CAN render and exits 0 — the notice IS the demo's answer
 *    there, so no raw/stty arm is ever engaged.
 *
 *  - no terminal at all, dead input (files, /dev/null, closed descriptors
 *    without a pipe): the older shape sat in the input wait forever — the
 *    sc-05 hang class. sc-05's EOF-quit already exits 0 on `/dev/null` runs,
 *    but silently: the frame is painted into a stream nobody is reading.
 *    The guard names the requirement instead of pretending the demo ran, and
 *    exits 1 so a script that expected a demo notices the absence. Piped
 *    keystrokes (`printf 'q' | php examples/x.php`) remain a supported
 *    self-driving shape and pass the guard: a FIFO on stdin IS live input.
 *
 * The VHS tapes (.vhs/*.tape) drive the examples inside a real PTY, so both
 * streams are ttys there and the guard is a no-op — GIF regeneration stays
 * CI-side per the committed-GIF law.
 *
 * @param string $script demo file name, e.g. 'counter'
 */
function interactiveDemoGuard(string $script): void
{
    if (getenv('TERM') === 'dumb') {
        fwrite(STDOUT, $script . ": TERM=dumb — this demo needs a cursor-capable terminal; nothing to show here.\n");
        exit(0);
    }

    if (\is_resource(STDIN) && @stream_isatty(STDIN)) {
        return;
    }

    if (@stream_isatty(STDOUT)) {
        return;
    }

    // No terminal in sight. A FIFO on stdin means scripted keystrokes —
    // that run drives itself and exits via its own quit arm or EOF-quit.
    $stat = @fstat(STDIN);
    if (is_array($stat) && (0xF000 & (int) $stat['mode']) === 0x1000) {
        return;
    }

    fwrite(STDERR, $script . ": this interactive demo needs a terminal (or piped keystrokes on stdin); run it from a tty.\n");
    exit(1);
}
