<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Thin FFI bindings to the subset of libc needed for PTY control.
 *
 * The cdef is loaded lazily on first {@see lib()} call and cached for
 * the lifetime of the process. Library lookup honours
 * `SUGARCRAFT_LIBC` (env override) before falling back to the
 * platform default — `libc.so.6` on Linux, `/usr/lib/libSystem.B.dylib`
 * on macOS.
 *
 * Mirrors charmbracelet/x/xpty internals (which call the same libc
 * symbols directly via cgo / unix.Syscall).
 */
final class Libc
{
    /** Default Linux libc shared object. */
    public const DEFAULT_LINUX = 'libc.so.6';

    /** Default macOS libc shared object. */
    public const DEFAULT_DARWIN = '/usr/lib/libSystem.B.dylib';

    /** Lazily-loaded FFI handle, shared per-process. */
    private static ?\FFI $ffi = null;

    /**
     * Return the cached FFI handle, loading the libc cdef on first call.
     *
     * The cdef block is intentionally minimal — only the symbols PR1
     * needs (open round-trip + close). Later PRs extend the surface.
     *
     * @throws PtyException if the platform is unsupported or the
     *                      library cannot be loaded
     */
    public static function lib(): \FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            throw new PtyException(
                'candy-pty requires POSIX libc; use ConPTY on Windows (not yet ported).'
            );
        }

        $library = self::libraryPath();

        try {
            self::$ffi = \FFI::cdef(self::cdef(), $library);
        } catch (\FFI\Exception $e) {
            throw new PtyException(
                "Failed to load libc from '{$library}': " . $e->getMessage(),
                0,
                $e,
            );
        }

        return self::$ffi;
    }

    /**
     * Resolve the libc shared-object path for the host platform.
     *
     * Honours `SUGARCRAFT_LIBC` env override (useful for musl/Alpine
     * sysroots and custom builds) before defaulting per `PHP_OS_FAMILY`.
     */
    public static function libraryPath(): string
    {
        $override = \getenv('SUGARCRAFT_LIBC');
        if (\is_string($override) && $override !== '') {
            return $override;
        }

        return PHP_OS_FAMILY === 'Darwin'
            ? self::DEFAULT_DARWIN
            : self::DEFAULT_LINUX;
    }

    /**
     * Return the libc cdef declaration block.
     *
     * Kept as a constant-like static so tests can introspect it without
     * loading the FFI runtime.
     */
    public static function cdef(): string
    {
        $core = <<<'CPROTO'
int   posix_openpt(int flags);
int   grantpt(int fd);
int   unlockpt(int fd);
int   ptsname_r(int fd, char *buf, unsigned long buflen);
int   close(int fd);
int   open(const char *path, int flags);
int   ioctl(int fd, unsigned long request, void *arg);

/* termios — struct termios is treated as opaque (≥80 bytes) because
   layout differs across glibc/musl (60 bytes) and Darwin (72 bytes).
   Only call cfmakeraw/tcgetattr/tcsetattr; do NOT read individual fields. */
int   tcgetattr(int fd, void *termios_p);
int   tcsetattr(int fd, int when, void *termios_p);
void  cfmakeraw(void *termios_p);
int   cfgetospeed(void *termios_p);
int   cfsetospeed(void *termios_p, int speed);
unsigned int cfgetispeed(void *termios_p);
int   cfsetispeed(void *termios_p, int speed);
CPROTO;

        // POSIX 2024 winsize helpers — non-variadic wrappers around
        // the TIOCSWINSZ/TIOCGWINSZ ioctls. Available in macOS 13+
        // libSystem but NOT in older glibc (< 2.36). Only declare
        // them on Darwin so FFI's eager symbol resolution doesn't
        // error on Linux runners with older glibc.
        //
        // Reason we need them: real libc ioctl is variadic, and on
        // macOS arm64 the variadic ABI puts varargs on the stack
        // while fixed args go in x0–x7 — our fixed-arg ioctl cdef
        // pushes the winsize pointer to x2 while the kernel reads
        // it from the stack, returning -1. tcsetwinsize /
        // tcgetwinsize have explicit `struct winsize *` pointers,
        // so the ABI mismatch doesn't apply.
        if (PHP_OS_FAMILY === 'Darwin') {
            $core .= "\nint   tcsetwinsize(int fd, void *winsize_p);";
            $core .= "\nint   tcgetwinsize(int fd, void *winsize_p);";
        }

        return $core;
    }

    /**
     * Reset the cached FFI handle.
     *
     * Test-only. Production code never needs to drop the handle since
     * libc symbols cannot become invalid mid-process.
     */
    public static function reset(): void
    {
        self::$ffi = null;
    }

    private function __construct() {}
}
