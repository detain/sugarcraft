<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Thin FFI bindings to the subset of libc needed for PTY control.
 *
 * The cdef is loaded lazily on first {@see lib()} call and cached for
 * the lifetime of the process. Library lookup honours
 * `SUGARCRAFT_LIBC` (env override) before falling back to the
 * per-family default — `libc.so.6` on Linux, `/usr/lib/libSystem.B.dylib`
 * on macOS, the first existing soname candidate on FreeBSD; families
 * without a validated map (Solaris included) throw instead of
 * inheriting the Linux name (PTY-27, see {@see libraryPathFor()}).
 *
 * SECURITY: `SUGARCRAFT_LIBC` feeds directly into `FFI::cdef($cdef, $lib)`,
 * which loads and RUNS native code from the named shared object — i.e. it
 * is an arbitrary-code-execution primitive if the value is ever influenced
 * by untrusted input. Only set it in a trusted environment and never derive
 * it from a request, config downloaded at runtime, etc. {@see libraryPath()}
 * validates the override (absolute path, existing regular file, libc-shaped
 * basename) and throws rather than loading anything that fails those checks.
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

    /**
     * FreeBSD base-libc soname candidates, newest era first. The soname
     * has tracked the major version (libc.so.7 through the 11.x-14.x
     * era, libc.so.8 on 15+); none of these names exist on Linux, so
     * the resolver picks the first real file and refuses to guess
     * further (PTY-27).
     *
     * @var list<string>
     */
    public const FREEBSD_LIBRARY_CANDIDATES = ['/lib/libc.so.8', '/lib/libc.so.7'];

    /** Lazily-loaded FFI handle, shared per-process. */
    private static ?\FFI $ffi = null;

    /** Lazily-loaded libutil FFI handle (Linux only, for openpty). */
    private static ?\FFI $ffiUtil = null;

    /** Which library path {@see libutil()} actually selected (PTY-28 test surface). */
    private static ?string $ffiUtilSource = null;

    /**
     * The candidate library {@see libutil()} bound openpty against —
     * null before the first load. Test surface for the override
     * precedence pins; production code decides nothing from it.
     *
     * @internal
     */
    public static function libutilSource(): ?string
    {
        return self::$ffiUtilSource;
    }

    /**
     * Return the cached FFI handle, loading the libc cdef on first call.
     *
     * The cdef block is intentionally minimal — it starts with only the
     * symbols needed for an open/close round-trip and grows per feature.
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
            throw new PtyException(Lang::t('libc.posix_only'));
        }

        $library = self::libraryPath();

        try {
            self::$ffi = \FFI::cdef(self::cdef(), $library);
        } catch (\FFI\Exception $e) {
            throw new PtyException(
                Lang::t('libc.load_failed', ['library' => $library, 'detail' => $e->getMessage()]),
                0,
                $e,
            );
        }

        return self::$ffi;
    }

    /**
     * Resolve the libc shared-object path for the host platform.
     *
     * Honours the `SUGARCRAFT_LIBC` env override (required for musl/Alpine
     * sysroots and custom builds — musl's libc is e.g.
     * `/lib/ld-musl-x86_64.so.1`, not `libc.so.6`) before defaulting per
     * `PHP_OS_FAMILY`.
     *
     * SECURITY: the override value is loaded and executed by
     * `FFI::cdef($cdef, $lib)`, so an attacker-controlled `SUGARCRAFT_LIBC`
     * is remote code execution. Only set it in a trusted environment; NEVER
     * expose it to untrusted input. When present it is validated here —
     * must be an absolute path (relative paths would load from the CWD),
     * must exist as a regular file, and its basename must look like a real
     * libc (contains `libc`, `libSystem`, or `musl`). Any violation throws
     * loudly rather than silently loading the object or falling back to the
     * platform default.
     *
     * @throws PtyException if `SUGARCRAFT_LIBC` is set but fails validation
     */
    public static function libraryPath(): string
    {
        $override = \getenv('SUGARCRAFT_LIBC');
        if (\is_string($override) && $override !== '') {
            if (!\str_starts_with($override, '/')) {
                throw new PtyException(Lang::t('libc.override_not_absolute', ['path' => $override]));
            }
            if (!\is_file($override)) {
                throw new PtyException(Lang::t('libc.override_not_file', ['path' => $override]));
            }
            $base = \strtolower(\basename($override));
            if (!\str_contains($base, 'libc')
                && !\str_contains($base, 'libsystem')
                && !\str_contains($base, 'musl')
            ) {
                throw new PtyException(Lang::t('libc.override_not_libc', ['path' => $override]));
            }
            return $override;
        }

        return self::libraryPathFor(\PHP_OS_FAMILY);
    }

    /**
     * Per-family default shared object (PTY-27, sugar-pty-024). Before
     * this the tail was a ternary: anything not literally "Darwin" got
     * `libc.so.6` — a FreeBSD or Solaris host would fail to load with
     * a message about a file that has never existed there, and the
     * README's "best-effort" prose was the only thing telling the
     * truth about it.
     *
     * Linux: `libc.so.6` (musl/Alpine needs the `SUGARCRAFT_LIBC`
     * override). Darwin: the libSystem stub. FreeBSD: the first
     * existing soname candidate. Every other family — Solaris
     * included — throws instead of guessing: an unmapped family gets a
     * loud refusal, never a Linux-shaped load attempt.
     *
     * @throws PtyException for unmapped families or an unfound FreeBSD soname
     */
    public static function libraryPathFor(string $family): string
    {
        return match ($family) {
            'Linux' => self::DEFAULT_LINUX,
            'Darwin' => self::DEFAULT_DARWIN,
            'BSD' => self::resolveFreebsdLibrary(),
            default => throw new PtyException(
                Lang::t('libc.no_soname_family', ['family' => $family]),
            ),
        };
    }

    private static function resolveFreebsdLibrary(): string
    {
        foreach (self::FREEBSD_LIBRARY_CANDIDATES as $candidate) {
            if (\is_file($candidate)) {
                return $candidate;
            }
        }

        throw new PtyException(Lang::t('libc.no_freebsd_soname', [
            'candidates' => \implode(', ', self::FREEBSD_LIBRARY_CANDIDATES),
        ]));
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
        return self::cdefFor(\PHP_OS_FAMILY);
    }

    /**
     * The cdef for a NAMED platform family, independent of the host.
     *
     * Static readers of the declaration (sugar-core's descriptor-sink census
     * parses it as text) must not depend on where they run: {@see cdef()}
     * asks for the host's errno accessor, and on a Windows host, which has
     * no libc to bind, that throws, which made 9 census tests error on the
     * sugar-core Windows CI cell. Families without a validated accessor
     * still throw here, exactly as {@see errnoSymbolFor()} does.
     */
    public static function cdefFor(string $family): string
    {
        $openpty = $family === 'Darwin'
            ? "int   openpty(int *amaster, int *aslave, char *name, void *termp, void *winp);\n"
            : '';

        // FFI::cdef() resolves every declared symbol eagerly, so declaring
        // the wrong platform's errno accessor fails the WHOLE libc load
        // ("Failed resolving C function '__errno_location'" on macOS).
        $errnoFn = self::errnoSymbolFor($family);

        return <<<CPROTO
int   setsid(void);
int   posix_openpt(int flags);
int   grantpt(int fd);
int   unlockpt(int fd);
int   ptsname_r(int fd, char *buf, unsigned long buflen);
{$openpty}int   waitpid(int pid, int *status, int options);
int   dup(int fd);
int   close(int fd);
/* kill(2): the ext-posix-less path of Libc::kill(). pid_t is int on
   every target ABI. */
int   kill(int pid, int sig);
/* isatty(3): the ext-posix-less arm of Termios isAtty(). 1 = tty,
   0 = not (ENOTTY et al. via errno). */
int   isatty(int fd);
int   open(const char *path, int flags);
/* ioctl is variadic in <sys/ioctl.h>; this fixed-arg form is what every
   call site uses. On arm64 DARWIN a winsize pointer does NOT survive the
   variadic frame in either direction — measured for TIOCSWINSZ (PR #475)
   and TIOCGWINSZ (CI run 33796495350: rc=-1 on a posix_isatty-accepted
   slave fd). All size ioctls therefore go through SizeIoctl, which owns
   the Darwin stty(1) fallbacks; do not call this declaration with a
   struct pointer from new sites. Integer/pointer-free third args
   (TIOCSCTTY passing null as 0) are unaffected on every lane. */
int   ioctl(int fd, unsigned long request, void *arg);

/* fcntl is varargs in <fcntl.h> on Linux/macOS, but the int-arg form
   (used here for F_SETFD/FD_CLOEXEC) is ABI-compatible with this
   fixed-prototype cdef on every System V calling convention sugar-pty
   targets. Do NOT call commands that take a struct flock * through this
   declaration — add a separate `int fcntl_lock(...)` cdef if needed. */
int   fcntl(int fd, int cmd, int arg);

/* errno is thread-local; the accessor symbol is platform-specific:
   __errno_location() on glibc/musl (Linux), __error() on Darwin.
   Returns int*. */
int * {$errnoFn}(void);

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
     * Return the current value of errno for this thread.
     *
     * This is the only portable way to read errno after an FFI call,
     * since PHP's own errno constants (like EIO) are not available
     * until PHP 8.4+ and FFI calls do not set PHP's errno.
     */
    public static function errno(): int
    {
        $ptr = self::lib()->{self::errnoSymbol()}();
        return $ptr === null ? 0 : $ptr[0];
    }

    /**
     * The platform's thread-local errno accessor symbol.
     *
     * Linux (glibc and musl both) exports `__errno_location`; Darwin's
     * libSystem and FreeBSD's libc export `__error` (same `int *`
     * contract). PTY-27 (sugar-pty-025): the old ternary handed every
     * non-Darwin family `__errno_location`, so a FreeBSD host would
     * bind a symbol its libc does not export. Solaris uses `___errno`
     * but stays unmapped — the load itself refuses first.
     */
    public static function errnoSymbol(): string
    {
        return self::errnoSymbolFor(\PHP_OS_FAMILY);
    }

    /**
     * Pure errno-accessor map, testable for any family (PTY-27 seam).
     *
     * @throws PtyException for unmapped families
     */
    public static function errnoSymbolFor(string $family): string
    {
        return match ($family) {
            'Linux' => '__errno_location',
            'Darwin', 'BSD' => '__error',
            default => throw new PtyException(
                Lang::t('libc.no_errno_symbol', ['family' => $family]),
            ),
        };
    }

    /**
     * Human-readable errno for error messages: `"13 (Permission denied)"`.
     *
     * Uses ext-posix's strerror when available (the FFI cdef stays
     * minimal — no `strerror` symbol needed); falls back to the bare
     * number otherwise. Read errno IMMEDIATELY after the failing FFI
     * call — any intervening syscall may clobber it.
     */
    public static function errnoDetail(): string
    {
        $errno = self::errno();
        return \function_exists('posix_strerror')
            ? $errno . ' (' . \posix_strerror($errno) . ')'
            : (string) $errno;
    }

    /** EINTR is the errno value for "interrupted system call". */
    public const EINTR = 4;

    /**
     * Send `$signal` to `$pid`; true when the signal was delivered.
     *
     * Uses `posix_kill()` when ext-posix is loaded and libc `kill(2)` over
     * FFI otherwise. ext-posix is optional for this package (every other
     * posix_* call site is `function_exists`-guarded, and candy-code runs
     * without it by design), but the child `kill()` methods called
     * `posix_kill()` bare -- a fatal "undefined function" at exactly the
     * moment a caller tries to terminate a wedged child. ext-ffi is a hard
     * requirement already, so the libc path is always available.
     *
     * @throws PtyException if ext-posix is absent and libc cannot be loaded
     */
    public static function kill(int $pid, int $signal): bool
    {
        if (\function_exists('posix_kill')) {
            return \posix_kill($pid, $signal);
        }

        return self::killViaLibc($pid, $signal);
    }

    /**
     * The ext-posix-less arm of {@see kill()}: libc `kill(2)` over FFI.
     *
     * @internal public only so the arm is testable on hosts that DO load
     *           ext-posix, where {@see kill()} never reaches it.
     * @throws PtyException if libc cannot be loaded
     */
    public static function killViaLibc(int $pid, int $signal): bool
    {
        return self::lib()->kill($pid, $signal) === 0;
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
        self::$ffiUtilSource = null;
    }

    /**
     * Return the libutil FFI handle on Linux (contains openpty).
     *
     * On Darwin this method returns the regular libc handle since
     * openpty is already in libSystem.B.dylib. On Windows it throws.
     *
     * TEST SURFACE (PTY-28, sugar-pty-083): this loader has ZERO
     * production callers — verified by grep at this commit and earlier
     * by the P2 pass. The Linux hot path opens ptys through the
     * posix_openpt quartet on the MAIN cdef handle, not through
     * openpty(3); Darwin gets openpty from libSystem. Nothing real
     * ever needs this handle except tests and diagnostics that want
     * the openpty symbol specifically.
     *
     * The `SUGARCRAFT_LIBC` override (validated through
     * {@see libraryPath()}, same security rules) is attempted FIRST
     * (sugar-pty-011): on musl/Alpine sysroots openpty lives in the
     * overriding libc itself, and before this commit this method
     * ignored the env completely and hard-loaded `libutil.so.1`.
     * Candidates are proven by `dlopen`+`dlsym` before the cdef is
     * pointed at them, because `FFI::cdef()` on glibc resolves symbols
     * LAZILY — a cdef against a library lacking openpty succeeds and
     * the later call jumps through a null stub (measured on PHP 8.3:
     * segfault, not a catchable error). `libutil.so.1` stays the
     * classic home on glibc; `libc.so.6` last covers the glibc ≥ 2.38
     * merge and any future stub-free packaging.
     *
     * @internal public only for the test surface above.
     * @throws PtyException if the platform is unsupported or no
     *                     candidate library resolves the openpty cdef
     * @return \FFI the libutil FFI handle (contains openpty on Linux)
     */
    public static function libutil(): \FFI
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new PtyException(Lang::t('libc.posix_only'));
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            self::$ffiUtilSource = self::libraryPath();

            return self::lib();
        }

        if (self::$ffiUtil !== null) {
            return self::$ffiUtil;
        }

        $override = \getenv('SUGARCRAFT_LIBC');
        if (\is_string($override) && $override !== '') {
            // Validate the override NOW — libraryPath() throws on the
            // documented security shape, and that refusal propagates.
            $candidates = [self::libraryPath(), self::DEFAULT_LINUX_UTILS, self::DEFAULT_LINUX];
        } else {
            $candidates = [self::DEFAULT_LINUX_UTILS, self::DEFAULT_LINUX];
        }

        $libutilCdef = <<<'CPROTO'
int openpty(int *amaster, int *aslave, char *name, void *termp, void *winp);
int close(int fd);
CPROTO;

        $verdicts = self::openptyCandidateVerdicts($candidates);
        foreach ($candidates as $candidate) {
            if ($verdicts[$candidate] === 'has-openpty') {
                self::$ffiUtil = \FFI::cdef($libutilCdef, $candidate);
                self::$ffiUtilSource = $candidate;

                return self::$ffiUtil;
            }
        }

        if (($verdicts[$candidates[0]] ?? '') === 'unverifiable') {
            // Pre-PTY-28 floor when the probe itself cannot bind:
            // cdef each candidate in order, catching load failures.
            // (A late-binding host may still hand back a handle whose
            // openpty call fails — exactly the old behaviour.)
            foreach ($candidates as $candidate) {
                try {
                    self::$ffiUtil = \FFI::cdef($libutilCdef, $candidate);
                    self::$ffiUtilSource = $candidate;

                    return self::$ffiUtil;
                } catch (\FFI\Exception) {
                    continue;
                }
            }
        }

        $report = [];
        foreach ($verdicts as $candidate => $verdict) {
            $report[] = '\'' . $candidate . '\' ' . $verdict;
        }

        throw new PtyException(
            Lang::t('libc.openpty_candidates_failed', ['report' => \implode(' | ', $report)])
            . (\is_string($override) && $override !== '' ? '' : Lang::t('libc.openpty_hint_set_libc')),
        );
    }

    /**
     * dlopen+dlsym verdicts per candidate, in order. The probe handle
     * binds against the running PHP binary's own scope (it is always
     * linked with libc), so no library path guessing is involved here.
     *
     * @param list<string> $candidates
     * @return array<string, string>
     */
    private static function openptyCandidateVerdicts(array $candidates): array
    {
        try {
            $probe = \FFI::cdef(
                'void *dlopen(const char *filename, int flag);
                 void *dlsym(void *handle, const char *symbol);
                 int dlclose(void *handle);'
            );
        } catch (\FFI\Exception) {
            // No probe available (would be an ancient/broken FFI):
            // treat every candidate as unverifiable and let the cdef
            // itself answer — the pre-PTY-28 shape, kept as a floor.
            $verdicts = [];
            foreach ($candidates as $candidate) {
                $verdicts[$candidate] = 'unverifiable';
            }

            return $verdicts;
        }

        $verdicts = [];
        foreach ($candidates as $candidate) {
            $handle = $probe->dlopen($candidate, 1); // RTLD_LAZY — a failed load yields PHP null here
            if ($handle === null || \FFI::isNull($handle)) {
                $verdicts[$candidate] = 'not-loadable';
                continue;
            }
            $symbol = $probe->dlsym($handle, 'openpty');
            $probe->dlclose($handle);
            $verdicts[$candidate] = ($symbol === null || \FFI::isNull($symbol))
                ? 'no-openpty'
                : 'has-openpty';
        }

        return $verdicts;
    }

    private function __construct() {}
}
