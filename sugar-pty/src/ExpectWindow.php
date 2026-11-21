<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * The outcome of one bounded read window from {@see Expect::collectWindow()}.
 *
 * `retained` and `dropped` are the session totals AFTER this window, so a
 * caller threads them into the next window to keep one byte budget across
 * every step of a scripted session.
 */
final class ExpectWindow
{
    /**
     * @param string $output      bytes retained by this window, under the cap
     * @param ?bool  $matched     null when no regex was asked for; true when the
     *                            regex matched the retained output; false otherwise
     * @param bool   $childExited the child was seen gone during or at the end of the window
     * @param bool   $wallHit     the window closed because the wall deadline passed
     *                            without a match or a child exit
     * @param int    $retained    session total of retained bytes after this window
     * @param int    $dropped     session total of bytes refused by the cap after this window
     * @param bool   $eof         the master reached EOF during the window, so no more output can follow
     */
    public function __construct(
        public readonly string $output,
        public readonly ?bool $matched,
        public readonly bool $childExited,
        public readonly bool $wallHit,
        public readonly int $retained,
        public readonly int $dropped,
        public readonly bool $eof = false,
    ) {
    }
}
