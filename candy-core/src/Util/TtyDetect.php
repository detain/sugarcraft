<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\Pty\TermiosFactory;

/**
 * Static TTY-detection helpers that delegate to candy-pty.
 *
 * Provides a single call-site for every lib that needs "is this stream
 * a TTY?" without taking a direct candy-pty dependency.  candy-core
 * already requires candy-pty; this utility re-exports the check so
 * downstream consumers (candy-mosaic, sugar-bits, sugar-prompt,
 * candy-log, etc.) get the same behaviour without extra dep management.
 *
 * @see \SugarCraft\Pty\TermiosFactory
 */
final class TtyDetect
{
    private function __construct() {}

    /**
     * True when the given stream refers to a terminal device.
     *
     * Wraps TermiosFactory::open($fd)->isAtty() so that the
     * fd→Termios→isAtty dance is centralized in one place.  Returns
     * false on any error (not a tty, closed, invalid stream) rather
     * than throwing, so callers can use it in guard clauses without
     * wrapping in try/catch.
     *
     * @param resource $stream STDIN, STDOUT, STDERR or equivalent
     */
    public static function isAtty($stream): bool
    {
        if (!\is_resource($stream)) {
            return false;
        }

        $fd = (int) $stream;
        if ($fd < 0) {
            return false;
        }

        try {
            return TermiosFactory::open($fd)->isAtty();
        } catch (\Throwable) {
            return false;
        }
    }
}
