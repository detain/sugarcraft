<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

use SugarCraft\Pty\Contract\Termios;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\SizeIoctl;
use SugarCraft\Pty\TermiosFactory;

/**
 * POSIX TTY backend delegating to candy-pty for termios and size queries.
 *
 * Uses TermiosFactory (FFI primary, stty fallback) for raw mode and
 * SizeIoctl for terminal dimensions.
 *
 * Mirrors charmbracelet/bubbletea TtyBackend
 */
final class PosixBackend implements Backend
{
    /** @var resource */
    private $stream;

    /** @var Termios|null */
    private ?Termios $termios = null;

    /** @var Termios|null saved original termios for restore() */
    private ?Termios $saved = null;

    /**
     * PID that called {@see enableRawMode()} - {@see restore()} only
     * applies the saved termios when called from this SAME process. See
     * restore()'s docblock for why.
     */
    private ?int $ownerPid = null;

    /**
     * Injected Termios override (set when a caller wired one via
     * {@see \SugarCraft\Core\ProgramOptions::$termios}). When non-null
     * {@see enableRawMode()} uses it directly instead of resolving via
     * {@see TermiosFactory}; the host TTY is never touched. Test seam.
     */
    private readonly ?Termios $injectedTermios;

    /** Saved termios snapshot for restoreLast(). */
    private static ?\SugarCraft\Pty\Contract\Termios $rescueSnapshot = null;

    /**
     * @param resource|null $stream  defaults to STDIN
     * @param Termios|null  $termios optional pre-built Termios; when
     *                               null, {@see enableRawMode()} resolves
     *                               via {@see TermiosFactory}.
     */
    public function __construct($stream = null, ?Termios $termios = null)
    {
        $this->stream = $stream ?? STDIN;
        $this->injectedTermios = $termios;
    }

    public function isTty(): bool
    {
        return is_resource($this->stream) && stream_isatty($this->stream);
    }

    /**
     * @return array{0:resource,1:resource}|null
     */
    public static function openTty(): ?array
    {
        if (!is_readable('/dev/tty') || !is_writable('/dev/tty')) {
            return null;
        }
        $in  = @fopen('/dev/tty', 'rb');
        $out = @fopen('/dev/tty', 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            return null;
        }
        return [$in, $out];
    }

    /** @return array{cols:int, rows:int} */
    public function size(): array
    {
        // 1. FFI ioctl on the stream's fd (works for PTY slave).
        // The kernel's TIOCGWINSZ is the ground truth and is updated live on
        // every resize. We prefer it over the COLUMNS/LINES env vars because
        // those are frequently stale — bash only refreshes them for its own
        // prompt (and does not export them by default), and SSH/tmux/screen
        // sessions often export the size captured at login, which never
        // tracks a later resize. Trusting stale env produced frames sized to
        // the wrong (often halved) height. Env remains the fallback for the
        // no-tty case where the ioctl can't run.
        if ($this->isTty()) {
            // WHAT THIS USED TO SAY: `$fd = (int) $this->stream;` and then
            // `if ($fd >= 0)`. That cast is the stream's RESOURCE ID, not
            // its descriptor, and the `>= 0` guard could never fail for one
            // -- a resource id is always positive -- so it read as a check
            // and was not one. See descriptorForStream() for the
            // measurement and for why this needed no constructor change.
            $fd = self::descriptorForStream($this->stream);
            if ($fd !== null) {
                try {
                    $result = SizeIoctl::query($fd);
                    // Validate: kernel returns 0 for unset fields on some emulators
                    if ($result['cols'] > 0 && $result['rows'] > 0) {
                        return ['cols' => $result['cols'], 'rows' => $result['rows']];
                    }
                } catch (\Throwable $e) {
                    // FFI ioctl failed — fall through to next method
                }
            }
        }

        // 2. Env vars (the only signal when stdout is not a tty, e.g. piped
        // or non-interactive; on a live tty the ioctl above already won).
        $cols = (int) (getenv('COLUMNS') ?: 0);
        $rows = (int) (getenv('LINES') ?: 0);
        if ($cols > 0 && $rows > 0) {
            return ['cols' => $cols, 'rows' => $rows];
        }

        // 3. /dev/tty — the controlling terminal (always has the real size)
        //
        // WHAT THIS ARM USED TO DO, AND WHY IT CHANGED
        //
        // WHAT IT DID: `$tty = self::openTty(); $ttyFd = (int) $tty[0];` and
        // then `SizeIoctl::query($ttyFd)`. An `(int)` cast of a PHP stream
        // yields its RESOURCE ID, which is not a file descriptor.
        //
        // WHAT IS TRUE NOW: the other members of this family are latent
        // because descriptors 0/1/2 name the same device in an ordinary
        // terminal, so asking the wrong number still returns the right
        // answer. This arm could never be: `openTty()` FRESHLY OPENS
        // /dev/tty, and a fresh handle's resource id can never equal its own
        // descriptor once the low numbers are taken. MEASURED, PHP 8.3.6,
        // under a real pty (`script -qec`), three takes: the handle's
        // resource id and its actual descriptor were different numbers, and
        // `posix_isatty()` gave OPPOSITE answers for the two — false for the
        // resource id, true for the descriptor (4 in that harness).
        //
        // DO NOT READ A PARTICULAR RESOURCE ID OUT OF THIS PARAGRAPH. An
        // earlier revision of it named one and called it identical across
        // takes. It is identical within one harness and nowhere else: the
        // number counts how many streams the process opened first, so it
        // moves with the harness. Re-measured it was 15; under this suite it
        // runs into the hundreds, which
        // {@see \SugarCraft\Core\Tests\Util\TtyDetectTest} already recorded
        // about the same cast. The INVARIANT the fix rests on is only that
        // the two numbers differ. `SizeIoctl::query()` opens with
        // exactly that `posix_isatty()` check, so this arm THREW on every
        // single invocation it ever had and fell through to the `stty`
        // shell-out below. It had never returned an answer.
        //
        // WHY THIS ARM STILL EARNS ITS PLACE: the reasoning above it is
        // correct and unchanged — the controlling terminal is the one device
        // that always carries the real size, and reaching it by ioctl rather
        // than by shelling out to `stty` is the whole point of arms 1 and 3.
        // Only the descriptor was wrong. It now asks libc to open /dev/tty,
        // which hands back a GENUINE descriptor rather than a number derived
        // from a PHP stream. There is no portable userland call that maps a
        // PHP stream to its descriptor, which is why the fix opens its own
        // rather than trying to recover one.
        //
        // `self::openTty()` is untouched and is NOT made dormant by this: it
        // is a {@see Backend} interface method and `Program` reaches it
        // through `Tty::openTty()` for its `openTty: true` option.
        $ttyFd = self::openDeviceDescriptor(self::CONTROLLING_TERMINAL);
        if ($ttyFd !== null) {
            try {
                $result = SizeIoctl::query($ttyFd);
                if ($result['cols'] > 0 && $result['rows'] > 0) {
                    return ['cols' => $result['cols'], 'rows' => $result['rows']];
                }
            } catch (\Throwable $e) {
                // /dev/tty query failed — fall through
            } finally {
                self::closeDeviceDescriptor($ttyFd);
            }
        }

        // 4. stty -F /dev/tty — queries the controlling terminal directly (most reliable)
        // This avoids the stdin redirection problem where "stty size" reads from pipe
        $sttyTty = trim((string) shell_exec('stty -F /dev/tty size 2>/dev/null'));
        if ($sttyTty !== '' && str_contains($sttyTty, ' ')) {
            [$sRows, $sCols] = explode(' ', $sttyTty, 2);
            if ((int) $sRows > 0 && (int) $sCols > 0) {
                return ['cols' => (int) $sCols, 'rows' => (int) $sRows];
            }
        }

        // 5. stty size fallback — works when stdin is the terminal
        $stty = trim((string) shell_exec('stty size 2>/dev/null'));
        if ($stty !== '' && str_contains($stty, ' ')) {
            [$sRows, $sCols] = explode(' ', $stty, 2);
            if ((int) $sRows > 0 && (int) $sCols > 0) {
                return ['cols' => (int) $sCols, 'rows' => (int) $sRows];
            }
        }

        // 6. Wtmp query — check last login's terminal size (rough proxy)
        $who = trim((string) shell_exec('who -a 2>/dev/null | grep -m1 pts/0'));
        if ($who !== '' && preg_match('/\d+\s+\d+\s+(\d+)\s+(\d+)/', $who, $m)) {
            // who format: user tty pts/0 ... rows cols
            if ((int) $m[1] > 0 && (int) $m[2] > 0) {
                return ['cols' => (int) $m[2], 'rows' => (int) $m[1]];
            }
        }

        // 7. Final fallback — reasonable default for modern terminals
        return ['cols' => 200, 'rows' => 60];
    }

    /**
     * The controlling terminal's device path.
     *
     * Named rather than inlined so {@see openDeviceDescriptor()} reads as
     * a general "open this terminal device" helper — which is what makes it
     * testable on a host that has no controlling terminal at all, by handing
     * it /dev/ptmx instead. See that method's doc-block.
     */
    private const CONTROLLING_TERMINAL = '/dev/tty';

    /**
     * `O_RDONLY`. Zero on both platforms candy-pty's libc cdef supports
     * (Linux and Darwin); it is the one open flag whose value is fixed by
     * POSIX rather than by the platform's <fcntl.h>.
     */
    private const O_RDONLY = 0;

    /**
     * Open a device file and return the GENUINE file descriptor, or null.
     *
     * Named for what it GUARANTEES rather than for what it is used for: it
     * hands back a descriptor for whatever it was pointed at, terminal or
     * not. `SizeIoctl::query()` does the `posix_isatty()` check, and
     * duplicating it here would make the "null exactly when the device could
     * not be opened" contract -- which is what the guard asserts -- two
     * contracts instead of one.
     *
     * This exists because {@see SizeIoctl::query()} and
     * {@see TermiosFactory::open()} both take an `int` descriptor and PHP
     * offers no portable way to get one out of a stream handle — an `(int)`
     * cast yields the resource id, a different number entirely. So rather
     * than deriving a descriptor from a stream, this asks libc for one
     * directly.
     *
     * @internal Test seam as well as an implementation detail: the parameter
     *           is what lets a test exercise this on a host with no
     *           controlling terminal. MEASURED, PHP 8.3.6, in a process whose
     *           /dev/tty open fails with ENXIO (no controlling terminal),
     *           three takes: `open('/dev/ptmx', O_RDONLY)` returns descriptor
     *           3 with `posix_isatty(3) === true`, while `/dev/tty` returns
     *           -1. So the positive half of this helper's contract is
     *           assertable without a controlling terminal.
     *
     *           NOT "assertable everywhere", which an earlier revision of this
     *           sentence said: it needs libc, and `ffi.enable=0` -- a stock
     *           setting on several distributions, and one that leaves
     *           `extension_loaded('ffi')` answering true -- takes it away. The
     *           guard probes for the capability; see
     *           PosixBackendTerminalDescriptorTest::requireLibcDescriptors().
     *
     * @return int|null a descriptor the caller MUST hand to
     *                  {@see closeDeviceDescriptor()}, or null when the
     *                  device cannot be opened (no controlling terminal, no
     *                  ext-ffi, no libc)
     */
    public static function openDeviceDescriptor(string $device): ?int
    {
        try {
            $fd = Libc::lib()->open($device, self::O_RDONLY);
        } catch (\Throwable) {
            // No ext-ffi, or libc would not load. SizeIoctl::query() needs
            // the same FFI handle, so there is nothing this arm could have
            // done with a descriptor anyway.
            return null;
        }

        return \is_int($fd) && $fd >= 0 ? $fd : null;
    }

    /**
     * Close a descriptor obtained from {@see openDeviceDescriptor()}.
     *
     * Paired rather than inlined so the `finally` at the call site cannot
     * drift away from the libc handle that produced the descriptor. A leaked
     * descriptor here would be per-`size()`-call, and `size()` is called on
     * every SIGWINCH.
     *
     * @internal
     */
    public static function closeDeviceDescriptor(int $fd): void
    {
        try {
            Libc::lib()->close($fd);
        } catch (\Throwable) {
            // Unreachable in practice: we only get here with a descriptor
            // that the same libc handle just returned. Swallowed rather than
            // propagated because size() must always answer.
        }
    }


    /**
     * The three standard streams and the descriptors POSIX fixes them to.
     *
     * Held as names rather than as the constants themselves because
     * `defined('STDIN')` STAYS TRUE after the handle is closed while
     * `is_resource()` goes false -- see the closed-descriptor-0 family in
     * {@see \SugarCraft\Core\Util\TtyDetect}'s doc-block. A table of live
     * resources built at class-load time would carry a dead handle here.
     *
     * @var array<string,int>
     */
    private const STANDARD_DESCRIPTORS = ['STDIN' => 0, 'STDOUT' => 1, 'STDERR' => 2];

    /**
     * The GENUINE file descriptor behind a PHP stream, or null.
     *
     * ## What this replaces, and why it is not a constructor change
     *
     * WHAT THE TWO CALL SITES BELOW USED TO SAY: `$fd = (int) $this->stream`,
     * one to two lines above {@see SizeIoctl::query()} and
     * {@see TermiosFactory::open()}. An `(int)` cast of a PHP stream yields
     * its RESOURCE ID, which is a different number from its descriptor.
     * MEASURED, PHP 8.3.6, fresh CLI process, three takes: `(int) STDIN` is
     * 1, `(int) STDOUT` is 2, `(int) STDERR` is 3, over descriptors 0, 1
     * and 2. Both sites were latent rather than broken only because
     * `$this->stream` defaults to `STDIN` and 0 and 1 name the same device
     * in an ordinary terminal.
     *
     * WHAT IS TRUE NOW, AND WHERE THE EARLIER REASONING WAS INCOMPLETE: the
     * backlog recorded that the only two available fixes were to carry the
     * descriptor through {@see __construct()} or to resolve the three
     * standard streams and REFUSE an injected one -- i.e. that closing these
     * two sites had to change this class's constructor and every
     * `new Tty(...)` call site with it. There is a third answer, and it
     * changes no signature: the process's OWN descriptor table is readable,
     * so a stream can be matched to a descriptor by device and inode. That
     * is what the second arm below does, and it is why an injected stream --
     * `new PosixBackend($ptySlaveHandle)`, which
     * {@see \SugarCraft\Core\Tests\Util\Tty\PosixBackendTest} really does --
     * keeps working instead of being refused.
     *
     * ## The two arms, and why BOTH earn their place
     *
     *  1. IDENTITY against `STDIN`/`STDOUT`/`STDERR`. Exact, and it needs no
     *     filesystem at all, so it survives a container with no `/proc`
     *     mounted. It is also not merely a fast path: MEASURED on this box,
     *     PHP 8.3.6, in a CLI process whose stdout and stderr were both
     *     redirected onto one pipe (`php probe.php 2>&1 | cat`), `fstat()`
     *     gave descriptors 1 and 2 IDENTICAL dev+ino, so arm 2 on its own
     *     answers 1 for `STDERR`. Arm 1 is what makes the answer canonical,
     *     and the guard pins it by handing arm 2 an empty directory.
     *  2. The descriptor table (`/proc/self/fd` on Linux, `/dev/fd` on
     *     Darwin and FreeBSD), matched on `st_dev` + `st_ino`.
     *
     * Arm 1 answering first is also what keeps the cost out of the hot path:
     * `size()` runs on every SIGWINCH, and `$this->stream` defaults to
     * `STDIN`, which is the FIRST entry in the table above -- so the common
     * case is one identity comparison and no filesystem access at all.
     *
     * WHAT THIS PARAGRAPH USED TO SAY: "three identity comparisons", and
     * "only an INJECTED stream reaches the walk". Both are wrong. The loop
     * returns on its first iteration for the default stream, not its third.
     * And the walk is reachable in PRODUCTION, not only from a test seam:
     * `ProgramOptions::$openTty` -- public, and set by
     * `ProgramOptionsBuilder::withOpenTty()` -- makes `Program::__construct()`
     * replace its input with a fresh `/dev/tty` handle, which becomes this
     * backend's `$stream`. Every `size()` in such a program, at startup and
     * on every SIGWINCH, goes through arm 2.
     *
     * WHY THAT IS RECORDED RATHER THAN FIXED: because the cost is small, and
     * saying so honestly is worth more than implying the path is rare.
     * MEASURED on this box, PHP 8.3.6, 2000 iterations per arm, 3 takes: arm
     * 1 0.41 / 0.28 / 0.28 us per call, arm 2 36.73 / 36.58 / 36.70 us. Around
     * 100x, on a call that happens when a human drags a window edge. Nothing
     * here needs a cache; a future reader tempted to add one should have this
     * number rather than a guess.
     *
     * ## What this hands back, and who closes it
     *
     * NOTHING IS OPENED HERE, so unlike {@see openDeviceDescriptor()} there
     * is no paired close and nothing can leak: every descriptor this returns
     * is one the process already holds. The caller must not close it.
     *
     * The honest limitation is the other way round. Arm 2 identifies a
     * descriptor naming the SAME DEVICE as $stream, not necessarily
     * $stream's own -- MEASURED, PHP 8.3.6: two `fopen()`s of one path
     * produced descriptors 4 and 5 with identical dev+ino, and one pty slave
     * path opened by both `fopen()` and libc produced 5 and 6. For the two
     * sinks this feeds that is not a defect: `tcgetattr`/`tcsetattr` and
     * `TIOCGWINSZ` all act on the TERMINAL, not on the description, so every
     * descriptor open on one terminal gives one answer. It would matter if
     * the sibling descriptor were closed while $stream stayed open; the
     * lowest match is preferred because a long-lived standard descriptor is
     * the likeliest to outlive the object.
     *
     * @internal Public and static only so the guard can drive it directly
     *           and point arm 2 at a directory of its own -- the same seam,
     *           and for the same reason, as {@see openDeviceDescriptor()}'s
     *           `$device`. Nothing outside this class may call it.
     *
     * @param  resource|mixed $stream
     * @param  string|null    $fdDirectory the descriptor table to walk;
     *                                     null selects the platform's
     * @return int|null       a descriptor the caller must NOT close, or null
     *                        when the stream is dead, has no descriptor
     *                        (`php://memory` and friends), or the platform
     *                        exposes no descriptor table
     */
    public static function descriptorForStream($stream, ?string $fdDirectory = null): ?int
    {
        if (!\is_resource($stream)) {
            return null;
        }

        foreach (self::STANDARD_DESCRIPTORS as $name => $descriptor) {
            if (\defined($name) && \is_resource(\constant($name)) && \constant($name) === $stream) {
                return $descriptor;
            }
        }

        $directory = $fdDirectory ?? self::descriptorTable();
        if ($directory === null) {
            return null;
        }

        $target = @fstat($stream);
        if (!\is_array($target)) {
            return null;
        }

        $candidates = [];
        foreach ((array) @scandir($directory) as $entry) {
            if (!\ctype_digit((string) $entry)) {
                continue;
            }

            // The stat cache is keyed by path, and `/proc/self/fd/1` is a
            // path whose TARGET changes when the process redirects. size()
            // runs on every SIGWINCH, so a cached answer here would outlive
            // the thing it described.
            //
            // Evicted PER ENTRY rather than by a bare clearstatcache(). The
            // bare call empties the cache for the WHOLE PROCESS, and this
            // method is on the SIGWINCH path: a user dragging a window edge
            // would repeatedly throw away stat entries belonging to code that
            // has nothing to do with terminals, to fix a staleness that only
            // ever affects the handful of paths read on the next four lines.
            // MEASURED, PHP 8.3.6, 3 takes: clearstatcache(true, $path)
            // evicts exactly that path, which is all this walk needs.
            $path = $directory . '/' . $entry;
            clearstatcache(true, $path);

            $stat = @stat($path);
            if (!\is_array($stat)) {
                continue;
            }
            if ($stat['dev'] === $target['dev'] && $stat['ino'] === $target['ino']) {
                $candidates[] = (int) $entry;
            }
        }

        if ($candidates === []) {
            return null;
        }
        sort($candidates);

        return $candidates[0];
    }

    /**
     * The platform's per-process descriptor table, or null.
     *
     * Linux publishes `/proc/self/fd`; Darwin and FreeBSD publish
     * `/dev/fd` (on FreeBSD only when fdescfs is mounted, which is why this
     * probes rather than switching on `PHP_OS_FAMILY`). A host that exposes
     * neither -- a container with `/proc` unmounted is the realistic one --
     * loses arm 2 and keeps arm 1.
     */
    private static function descriptorTable(): ?string
    {
        foreach (['/proc/self/fd', '/dev/fd'] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function enableRawMode(): void
    {
        if ($this->termios !== null) {
            return;
        }

        if ($this->injectedTermios !== null) {
            $this->termios = $this->injectedTermios;
        } else {
            if (!$this->isTty()) {
                return;
            }
            // WHAT THIS USED TO SAY: `$fd = (int) $this->stream;` guarded
            // by `if ($fd < 0)`. Same resource-id cast as size()'s first
            // arm, and the same never-taken guard. A null answer here means
            // no descriptor could be resolved, which is a real outcome --
            // a `php://memory` stream has none at all -- so raw mode is
            // skipped rather than applied to a number that names nothing.
            $fd = self::descriptorForStream($this->stream);
            if ($fd === null) {
                return;
            }
            $this->termios = TermiosFactory::open($fd);
        }

        $this->saved = $this->termios->current();
        $this->ownerPid = getmypid();
        $this->termios->makeRaw()->apply();
        if (is_resource($this->stream)) {
            @stream_set_blocking($this->stream, false);
        }
    }

    /**
     * Restore the termios captured by {@see enableRawMode()}.
     *
     * A `pcntl_fork()`'d child inherits a COPY of this object with $saved
     * already populated - termios settings live on the shared kernel TTY
     * device, not per-process, so if that child's own shutdown sequence
     * ever reaches here (a plain `exit()` runs PHP's normal destructor
     * chain), applying $saved would restore the PARENT's terminal to its
     * pre-raw-mode state the instant the child exits, even though the
     * parent's raw-mode session is still live. Only the process that
     * actually called enableRawMode() may take the terminal back out of
     * raw mode - a forked child silently skips the real syscall instead.
     *
     * ## Why this calls restore() on the snapshot and not apply()
     *
     * WHAT THIS LINE USED TO BE: `$this->saved->apply()`. WHAT IS TRUE: that
     * put the terminal back only on the FFI backend, and was a silent no-op on
     * the `stty` one -- the fallback that exists precisely for hosts without
     * ext-ffi, i.e. the hosts least able to notice. `Termios::current()` hands
     * back an immutable SNAPSHOT, and the two implementations record the
     * restore target differently: `PosixTermios::current()` copies the captured
     * struct into the snapshot's `$original` AND its live buffer, so `apply()`
     * and `restore()` are the same syscall there; `SttyTermios::current()`
     * records the `stty -g` string in `$savedMode` and leaves `$raw` false, and
     * `SttyTermios::apply()` opens with `if (!$this->raw) { return; }`. So the
     * snapshot's `apply()` did nothing at all, and the method that replays
     * `$savedMode` -- `restore()`, which is on the `Termios` contract for
     * exactly this -- was never reached from here.
     *
     * MEASURED on this box, PHP 8.3.6, GNU coreutils `stty`, a real pty slave,
     * reading the device with `stty -a` at each step (ICANON and ECHO both
     * cleared = raw):
     *
     *   backend        before      after enableRawMode()   after restore()
     *   PosixTermios   cooked      raw                     cooked
     *   SttyTermios    cooked      raw                     RAW  <- the defect
     *
     * The user-visible shape of that row is a program that exits leaving the
     * terminal in raw mode and no echo, which is the `reset(1)` bug. Calling
     * `restore()` is identical for `PosixTermios` (same buffer, same
     * `tcsetattr`) and correct for `SttyTermios`, so nothing is traded for it.
     * Pinned on a real pty by
     * {@see \SugarCraft\Core\Tests\Util\Tty\PosixBackendTest::testRawModeWithSttyFallbackOnRealPty()}
     * and on the seam by `PosixBackendInjectedTermiosTest`.
     */
    public function restore(): void
    {
        if ($this->saved === null) {
            return;
        }
        if ($this->ownerPid !== null && $this->ownerPid !== getmypid()) {
            $this->termios = null;
            $this->saved = null;
            $this->ownerPid = null;

            return;
        }
        $this->saved->restore();
        $this->termios = null;
        $this->saved = null;
        $this->ownerPid = null;
        if (is_resource($this->stream)) {
            @stream_set_blocking($this->stream, true);
        }
    }

    public function __destruct()
    {
        $this->restore();
    }

    public static function onResize(\Closure $onResize): bool
    {
        if (!function_exists('pcntl_signal')) {
            return false;
        }
        // SIGWINCH = 28 on Linux; look it up portably.
        $sig = defined('SIGWINCH') ? SIGWINCH : 28;
        $tty = new self();
        return @\pcntl_signal($sig, static function () use ($tty, $onResize): void {
            $size = $tty->size();
            $onResize($size['cols'], $size['rows']);
        });
    }

    /**
     * @return int|false bitmask of dispatched signals (SIGNAL_RESIZE), or false if not available
     */
    public static function drainSignals(): int|false
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            return false;
        }

        // pcntl_signal_dispatch() returns true if any handler was invoked.
        // We treat that as equivalent to SIGNAL_RESIZE since drainSignals
        // on POSIX is only wired for SIGWINCH; a fired handler means a
        // resize was detected.
        return @\pcntl_signal_dispatch() ? self::SIGNAL_RESIZE : 0;
    }

    public static function restoreLast(): void
    {
        if (self::$rescueSnapshot !== null) {
            // Second+ call: restore saved termios.
            try {
                self::$rescueSnapshot->apply();
            } finally {
                self::$rescueSnapshot = null;
            }
            return;
        }
        // First call: save current state from STDIN.
        //
        // WHAT THE CODE UNDER THIS SENTENCE USED TO SAY, AND WHY IT MOVED
        //
        // WHAT IT SAID: `TermiosFactory::open((int) STDIN)`. The sentence
        // above it has always read "save current state from STDIN", and the
        // sentence was the half telling the truth about the intent.
        //
        // WHAT IS TRUE: an `(int)` cast of a PHP stream yields its RESOURCE
        // ID, and for the three standard streams that number is not the
        // descriptor. MEASURED, PHP 8.3.6, in a fresh CLI process, three
        // takes, identical every time: `(int) STDIN` is 1, `(int) STDOUT` is
        // 2 and `(int) STDERR` is 3, over descriptors 0, 1 and 2. So this
        // snapshotted descriptor 1 — STDOUT — and the whole rescue path was
        // saving and restoring the wrong end of the terminal. It looked
        // right in an ordinary terminal only because 0 and 1 name the same
        // device there.
        //
        // WHY THE FIX IS A LITERAL AND NOT A CONSTANT: `TermiosFactory::open()`
        // takes an `int` descriptor, and STDIN's descriptor is 0. There is no
        // PHP constant that holds it — `STDIN` holds the stream. candy-vcr's
        // `Cli\RecordCommand` already spells the same call `open(0)`.
        try {
            self::$rescueSnapshot = TermiosFactory::open(0)->current();
        } catch (\Throwable) {
            // Descriptor 0 is closed or is not a terminal (CI runner):
            // silently no-op.
        }
    }
}
