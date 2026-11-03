<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Support;

use SugarCraft\Pty\Libc;

/**
 * Capability probe shared by the requirePtySyscalls() helpers.
 *
 * extension_loaded('ffi') is NOT a usability signal: with ffi.enable=0
 * in php.ini the extension stays "loaded" while every FFI construction
 * throws — tests that gate on the loaded bit then ERROR instead of
 * SKIPping. This probe asks the real question: can the package's own
 * Libc handle actually be created?
 */
final class PtyCapability
{
    private static ?bool $ffiUsable = null;

    public static function ffiUsable(): bool
    {
        if (self::$ffiUsable === null) {
            if (PHP_OS_FAMILY === 'Windows' || !\extension_loaded('ffi')) {
                self::$ffiUsable = false;
            } else {
                try {
                    Libc::lib();
                    self::$ffiUsable = true;
                } catch (\Throwable) {
                    self::$ffiUsable = false;
                }
            }
        }
        return self::$ffiUsable;
    }

    /**
     * Full gate: working FFI plus a usable /dev/ptmx. Tests that need
     * BOTH can skip on this single predicate.
     */
    public static function ptySyscallsUsable(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        if (!self::ffiUsable()) {
            return false;
        }

        return \is_readable('/dev/ptmx') && \is_writable('/dev/ptmx');
    }
}
