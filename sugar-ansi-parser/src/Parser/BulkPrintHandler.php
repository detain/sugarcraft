<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Parser;

/**
 * Opt-in capability: receive contiguous printable-ASCII runs from the Ground
 * fast path as ONE {@see printRun()} call instead of one
 * {@see Handler::printChar()} per byte (finding sugar-ansi-parser-002).
 *
 * The contract that makes the capability safe: its absence must never change
 * observable dispatch ORDER or CONTENT. A single `printRun("abc")` arrival is
 * exactly the `printChar("a")`, `printChar("b")`, `printChar("c")` trio the
 * classic contract delivers at the same position of the event stream — a bulk
 * sink that wants uniform per-char handling can define `printRun()` as a loop
 * over its own printChar leg ({@see HandlerAdapter::printRun()} does just
 * that). Multi-byte runes and everything outside 0x20-0x7E still arrive
 * per-char/per-byte through the inherited {@see Handler} methods: only the
 * Ground printable sweep collapses.
 *
 * Like {@see SubparamsAwareHandler}, this is deliberately a capability
 * interface extending {@see Handler} rather than a ninth Handler method: the
 * parser resolves it once in its constructor via instanceof, and handlers
 * outside this repository that never implemented it keep compiling — adding a
 * required method to `Handler` would be a load-time fatal for every existing
 * implementation.
 *
 * Lib-original — charmbracelet/x/ansi.Handler has no bulk print sink; the
 * function-field struct dispatches per rune.
 */
interface BulkPrintHandler extends Handler
{
    /**
     * A maximal contiguous run (length >= 1) of printable-ASCII bytes
     * (0x20-0x7E) collected while in Ground, arriving at exactly the point
     * where the equivalent per-byte printChar() calls would have occurred.
     */
    public function printRun(string $run): void;
}
