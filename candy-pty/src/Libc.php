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

    /** Default Linux libutil shared object (contains openpty on some distros). */
    public const DEFAULT_LINUX_UTILS = 'libutil.so.1';

    /** Default macOS libc shared object. */
    public const DEFAULT_DARWIN = '/usr/lib/libSystem.B.dylib';

    /** Lazily-loaded FFI handle, shared per-process. */
    private static ?\FFI $ffi = null;

    /** Lazily-loaded libutil FFI handle (Linux only, for openpty). */
    private static ?\FFI $ffiUtil = null;

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
     *
     * On Darwin, `openpty` is included (provided by libSystem.B.dylib).
     * On Linux it is NOT declared here — it lives in libutil.so and is
     * loaded via a separate FFI handle in {@see libutil()} to avoid
     * eager-symbol-resolution failures when loading libc.so.6.
     */
    public static function cdef(): string
    {
        $openpty = \PHP_OS_FAMILY === 'Darwin'
            ? "int   openpty(int *amaster, int *aslave, char *name, void *termp, void *winp);\n"
            : '';

        return <<<CPROTO
int   setsid(void);
int   posix_openpt(int flags);
int   grantpt(int fd);
int   unlockpt(int fd);
int   ptsname_r(int fd, char *buf, unsigned long buflen);
{$openpty}int   waitpid(int pid, int *status, int options);
int   close(int fd);
int   open(const char *path, int flags);
int   ioctl(int fd, unsigned long request, void *arg);

/* fcntl is varargs in <fcntl.h> on Linux/macOS, but the int-arg form
   (used here for F_SETFD/FD_CLOEXEC) is ABI-compatible with this
   fixed-prototype cdef on every System V calling convention candy-pty
   targets. Do NOT call commands that take a struct flock * through this
   declaration — add a separate `int fcntl_lock(...)` cdef if needed. */
int   fcntl(int fd, int cmd, int arg);

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
        self::$ffiUtil = null;
    }

    /**
     * Return the libutil FFI handle on Linux (contains openpty).
     *
     * On Darwin this method returns the regular libc handle since
     * openpty is already in libSystem.B.dylib. On Windows it throws.
     *
     * @throws PtyException if the platform is unsupported or the
     *                      library cannot be loaded
     * @return \FFI the libutil FFI handle (contains openpty on Linux)
     */
    public static function libutil(): \FFI
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new PtyException(
                'candy-pty requires POSIX libc; use ConPTY on Windows (not yet ported).'
            );
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            return self::lib();
        }

        if (self::$ffiUtil !== null) {
            return self::$ffiUtil;
        }

        $libutilCdef = <<<'CPROTO'
int openpty(int *amaster, int *aslave, char *name, void *termp, void *winp);
int close(int fd);
CPROTO;

        try {
            self::$ffiUtil = \FFI::cdef($libutilCdef, self::DEFAULT_LINUX_UTILS);
        } catch (\FFI\Exception $e) {
            throw new PtyException(
                'Failed to load libutil from \'' . self::DEFAULT_LINUX_UTILS . '\': ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return self::$ffiUtil;
    }

    private function __construct() {}
}
