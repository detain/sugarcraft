<?php

declare(strict_types=1);

namespace SugarCraft\Input\Driver;

use SugarCraft\Input\Event\ResizeEvent;
use SugarCraft\Input\InputDriver;
use SugarCraft\Input\Event;

/**
 * InputDriver that detects terminal resize events via SIGWINCH signals.
 *
 * This driver wraps Unix signal handling to bridge the gap between
 * signal-based resize notification and the stream-based InputDriver interface.
 * Since signals are delivered asynchronously, read() returns null when no
 * resize has been detected since the last call.
 *
 * Usage:
 *   $driver = new SignalResizeDriver();
 *   $driver->arm();
 *   while (true) {
 *       $event = $driver->read();
 *       if ($event instanceof ResizeEvent) {
 *           // terminal was resized to $event->cols x $event->rows
 *       }
 *   }
 *
 * ONE-DISPOSITION LAW: arm() IS the SIGWINCH handler — a process can hold
 * only one, and this driver's handler fans the signal out to every armed
 * instance. Applications must NOT install a second pcntl_signal(SIGWINCH,
 * ...): whichever installs LAST silently blinds the other. Responding to a
 * resize needs no handler of your own — read() returns the ResizeEvent
 * carrying the fresh dimensions. (If your program already owns SIGWINCH for
 * other reasons, do not arm this driver.)
 *
 * arm() returns false — and read() then stays null forever — when ext-pcntl
 * is absent: the degraded mode is observable, never a silent half-start.
 * disarm() reverses the registration for one driver, and when the LAST armed
 * driver disarms, the SIGWINCH disposition returns to SIG_DFL.
 *
 * Construction is side-effect free. Arm() flips the process-global signal
 * disposition (pcntl_async_signals + a SIGWINCH handler) and probes the
 * initial terminal size once (fork-free tty ioctl first, tput only as a
 * last resort) — opt-in only, so merely building the
 * driver never mutates the process it lives in.
 *
 * @see ResizeEvent
 * @see InputDriver
 */
final class SignalResizeDriver implements InputDriver
{
    /**
     * Allowlist shape for terminfo capability names passed to tput.
     *
     * POSIX/terminfo-style short lowercase capability names — the two
     * callers of this driver request "cols" and "lines", and every name
     * this driver may ever ask for fits the lowercase form below. Anything
     * else is a malformed name — or an injection attempt riding a future
     * caller — and never reaches a shell.
     *
     * This guard, plus escapeshellarg() on the validated name, is
     * defense-in-depth for the shell_exec() in probeTput(). The guard parses;
     * quoting is applied unconditionally, so a future regex relaxation cannot
     * reintroduce an injection vector.
     */
    private const CAPABILITY_PATTERN = '/^[a-z][a-z0-9]{0,4}$/';

    /**
     * Live drivers the single process-wide SIGWINCH handler fans out to.
     *
     * A process can only carry one SIGWINCH disposition, and a WeakMap keeps
     * the registry from extending driver lifetimes (detached drivers fall out
     * on GC — no explicit unregister needed).
     *
     * @var \WeakMap<self>|null
     */
    private static ?\WeakMap $sigwinchListeners = null;

    /**
     * The SIGWINCH disposition as it stood BEFORE the first arm() in this
     * process, captured lazily so disarm() can hand it back. 'captured'
     * is the sentinel — the stored handler may legitimately be 0 (SIG_DFL)
     * or a Closure owned by other code.
     *
     * @var array{captured: bool, handler: \Closure|string|int}
     */
    private static array $sigwinchPrevious = ['captured' => false, 'handler' => 0];

    /** Flag set by the SIGWINCH handler for THIS instance only */
    private bool $sigwinchReceived = false;

    /** Last known terminal columns */
    private int $cols = 80;

    /** Last known terminal rows */
    private int $rows = 24;

    /**
     * @var callable(string): (string|null|false) Executes one built command;
     *      seam so tests can capture the command string without a
     *      shell. Defaults to `shell_exec()`.
     * @internal Test seam, not public API — PHP cannot seal a constructor
     *      parameter, so the boundary is stated here and in the README.
     */
    private $commandRunner;

    /**
     * @var callable(): (array{cols:int, rows:int}) First-arm probe of the
     *      kernel terminal size; seam so tests keep the tput last
     *      resort reachable on hosts whose terminal answers the real ioctl.
     *      Defaults to sugar-core's Tty over STDOUT.
     * @internal Test seam, not public API — same boundary note as above.
     */
    private $ttyProbe;

    /**
     * @param (callable(string): (string|null|false))|null $commandRunner test seam, not public API
     * @param (callable(): (array{cols:int, rows:int}))|null $ttyProbe    test seam, not public API
     */
    public function __construct(?callable $commandRunner = null, ?callable $ttyProbe = null)
    {
        $this->commandRunner = $commandRunner
            ?? static fn (string $command): string|false|null => @\shell_exec($command);
        $this->ttyProbe = $ttyProbe
            ?? static fn (): array => (new \SugarCraft\Core\Util\Tty(STDOUT))->size();
    }

    /**
     * Opt in to the driver's process-global side effects.
     *
     * Arm() is the ONLY place this class touches global state: it registers
     * the instance with the shared SIGWINCH fan-out registry, installs the
     * process-wide handler (flipping pcntl_async_signals on), and probes the
     * initial dimensions once via the probeDimensions() ladder — forking only
     * if the tput last resort is reached. Construction alone never does
     * any of that — callers who only build (or test-build) a driver leave the
     * process disposition untouched. Calling arm() twice is harmless: the
     * WeakMap set is idempotent and the handler re-install is the same closure.
     *
     * @return bool false when ext-pcntl is absent — the driver cannot detect
     *               resizes in that environment and read() will always return
     *               null (M8: no silent half-start).
     */
    public function arm(): bool
    {
        if (!function_exists('pcntl_signal')) {
            return false;
        }

        // The process can hold only ONE SIGWINCH disposition, so registration
        // is global: every live instance joins a WeakMap registry and a single
        // shared handler fans the flag out per-instance. A WeakMap is used so
        // an instance drops out of the registry on GC — no explicit unregister,
        // and a dead driver is never revived by a later signal.
        self::$sigwinchListeners ??= new \WeakMap();
        self::$sigwinchListeners[$this] = true;
        self::installSigwinchHandler();

        // Capture initial dimensions
        $this->updateDimensions();

        return true;
    }

    /**
     * Undo arm() for this instance (m3: arming used to be a one-way door).
     *
     * Removes this driver from the fan-out registry. When it was the LAST
     * armed driver, the process-wide SIGWINCH disposition is restored —
     * to whatever handler predates the first ever arm() in this process,
     * or SIG_DFL if none existed — so a host that armed this driver for a
     * phase can hand the signal back intact instead of leaking the
     * replacement. pcntl_async_signals() mode is deliberately NOT restored:
     * flipping a process-wide dispatch mode off could strand signal code
     * other libraries armed after this driver, and the disposition (not the
     * mode) is what this driver ever claimed to own.
     *
     * @return bool false when ext-pcntl is absent — exactly as in arm(),
     *               nothing was ever installed to undo.
     */
    public function disarm(): bool
    {
        if (!function_exists('pcntl_signal')) {
            return false;
        }

        $this->sigwinchReceived = false;

        if (self::$sigwinchListeners === null) {
            return true;
        }

        unset(self::$sigwinchListeners[$this]);

        if (count(self::$sigwinchListeners) === 0) {
            $previous = self::$sigwinchPrevious;
            self::$sigwinchPrevious = ['captured' => false, 'handler' => 0];
            pcntl_signal(SIGWINCH, $previous['captured'] ? $previous['handler'] : SIG_DFL);
        }

        return true;
    }

    /**
     * Install the single process-wide SIGWINCH handler that marks every live
     * registered driver. Safe to call from each arm(): re-installing the
     * same fan-out handler is idempotent.
     */
    private static function installSigwinchHandler(): void
    {
        if (!self::$sigwinchPrevious['captured'] && function_exists('pcntl_signal_get_handler')) {
            self::$sigwinchPrevious = [
                'captured' => true,
                'handler' => pcntl_signal_get_handler(SIGWINCH),
            ];
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGWINCH, static function (int $sig): void {
            // WeakMap single-variable foreach yields VALUES — iterate keys.
            foreach (self::$sigwinchListeners ?? new \WeakMap() as $driver => $ignored) {
                $driver->markSigwinch();
            }
        });
    }

    /**
     * Record a SIGWINCH for THIS instance only.
     *
     * Deliberately a bare flag write: this runs from the async-signal
     * handler, and the two tput forks used to ride it. A resize-drag
     * storm nesting shell_exec mid-dispatch is both slow and hostile to
     * non-reentrant state; the refresh now happens lazily in read(), where
     * N coalesced signals cost exactly one probe pair.
     */
    private function markSigwinch(): void
    {
        $this->sigwinchReceived = true;
    }

    /**
     * Read the next resize event, or null if no resize has been detected.
     *
     * Per-path honesty: the null fast path (no SIGWINCH since the last call)
     * is non-blocking and does nothing else. When a resize IS delivered, the
     * dimensions are re-probed HERE — not in the signal handler — and
     * the probe only forks on the tput last-resort arm: the Tty ioctl/env
     * legs are in-process (see probeDimensions).
     *
     * @return Event|ResizeEvent|null
     */
    public function read(): Event|ResizeEvent|null
    {
        if (!function_exists('pcntl_signal')) {
            return null;
        }

        if (!$this->sigwinchReceived) {
            return null;
        }

        $this->sigwinchReceived = false;
        $this->updateDimensions();

        return new ResizeEvent($this->cols, $this->rows);
    }

    /**
     * Refresh stored terminal dimensions from the cheapest truthful source.
     *
     * Arm 1 is sugar-core's Tty probe over STDOUT: the kernel's TIOCGWINSZ
     * ioctl on the terminal fd — zero forks, and it is the only source that
     * tracks a resize LIVE. Its documented ladder (see
     * SugarCraft\Core\Util\Tty) is ioctl first, then exported LINES/COLUMNS,
     * then the controlling terminal, then its own stty query; kernel truth
     * deliberately outranks env because bash refreshes COLUMNS/LINES only for
     * its own prompt and SSH/tmux exports capture the login size — trusting
     * stale env sizes frames to the wrong (often halved) terminal. An
     * exported env still beats the terminfo defaults below: it is somebody's
     * real answer, where tput-on-a-quiet-terminal is a guess.
     *
     * Arm 2 keeps tput as the LAST resort (the capability allowlist and its
     * test seam stay meaningful), for the cases Tty cannot answer at all —
     * no controlling terminal, no exported size, stubbed probes.
     *
     * @return array{0:int, 1:int} cols, rows — zeros mean "no source
     *                    answered; keep the last known dimensions"
     */
    private function probeDimensions(): array
    {
        try {
            $size = ($this->ttyProbe)();
            $ttyCols = (int) ($size['cols'] ?? 0);
            $ttyRows = (int) ($size['rows'] ?? 0);
        } catch (\Throwable) {
            // A host with no answerable terminal at all — the probe's own
            // failure is not an error to propagate, it is the signal to
            // consult the last resort below.
            $ttyCols = $ttyRows = 0;
        }
        if ($ttyCols > 0 && $ttyRows > 0) {
            return [$ttyCols, $ttyRows];
        }

        return [$this->probeTput('cols'), $this->probeTput('lines')];
    }

    /**
     * Update stored terminal dimensions from the probe ladder above.
     */
    private function updateDimensions(): void
    {
        [$cols, $rows] = $this->probeDimensions();

        if ($cols > 0) {
            $this->cols = $cols;
        }
        if ($rows > 0) {
            $this->rows = $rows;
        }
    }

    /**
     * Fork a `tput` and return its numeric answer, or 0 on failure — the
     * last-resort arm of probeDimensions(), named for the fork+parse it does.
     *
     * @throws \InvalidArgumentException when the capability is not a
     *                   well-formed terminfo name — never shelled.
     */
    private function probeTput(string $capability): int
    {
        $output = ($this->commandRunner)(self::tputCommand($capability));
        if ($output === null || $output === '') {
            return 0;
        }
        $value = (int) trim($output);
        return $value > 0 ? $value : 0;
    }

    /**
     * Refuse malformed names, then build the exact tput command line.
     *
     * Fail-fast: an unparseable capability throws before any shell-out.
     * The validated name is still escapeshellarg()'d (AGENTS.md: pass ALL
     * external-CLI flags every invocation via escapeshellarg()).
     */
    private static function tputCommand(string $capability): string
    {
        if (\preg_match(self::CAPABILITY_PATTERN, $capability) !== 1) {
            // The message quotes the offender (control bytes folded to '.')
            // and states the rule in prose — no hand-copied regex literal,
            // which would drift from CAPABILITY_PATTERN the moment either
            // side moved.
            $quoted = preg_replace('/[^\x20-\x7e]/', '.', $capability) ?? '';

            throw new \InvalidArgumentException(
                'SignalResizeDriver refused capability "' . $quoted . '": '
                . 'a terminfo capability is a lowercase letter followed by '
                . 'up to four lowercase letters or digits.'
            );
        }

        // 2>/dev/null: tput grumbles on stderr when terminfo cannot be
        // resolved (no tty, unknown TERM); probeTput() reads the empty stdout
        // as "keep last known dimensions". POSIX-sh idiom — on cmd.exe the
        // redirect is inert and the error text lands on stdout, where the
        // numeric cast yields 0: the same safe answer by a different road.
        return 'tput ' . \escapeshellarg($capability) . ' 2>/dev/null';
    }
}
