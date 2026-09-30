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
 *   while (true) {
 *       $event = $driver->read();
 *       if ($event instanceof ResizeEvent) {
 *           // terminal was resized to $event->cols x $event->rows
 *       }
 *   }
 *
 * NOTE: Applications must still install a SIGWINCH handler if they need
 * to RESPOND to resizes, not just detect them. This driver only detects
 * that a resize occurred by checking a flag set in the signal handler.
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
     * E715: this gate, plus escapeshellarg() on the validated name, is
     * defense-in-depth for the shell_exec() in getTput(). The gate parses;
     * the quoting stays even though a passing name needs none, so a later
     * regex relaxation cannot silently un-defend the shell-out.
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

    /** Flag set by the SIGWINCH handler for THIS instance only */
    private bool $sigwinchReceived = false;

    /** Last known terminal columns */
    private int $cols = 80;

    /** Last known terminal rows */
    private int $rows = 24;

    /**
     * @var callable(string): (string|null|false) Executes one built command;
     *      internal seam so tests can capture the command string without a
     *      shell. Defaults to @shell_exec.
     */
    private $commandRunner;

    /**
     * @param (callable(string): (string|null|false))|null $commandRunner test seam, not public API
     */
    public function __construct(?callable $commandRunner = null)
    {
        $this->commandRunner = $commandRunner
            ?? static fn (string $command): string|false|null => @\shell_exec($command);

        if (!function_exists('pcntl_signal')) {
            return;
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
    }

    /**
     * Install the single process-wide SIGWINCH handler that marks every live
     * registered driver. Safe to call from each constructor: re-installing the
     * same fan-out handler is idempotent.
     */
    private static function installSigwinchHandler(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGWINCH, static function (int $sig): void {
            // WeakMap single-variable foreach yields VALUES — iterate keys.
            foreach (self::$sigwinchListeners ?? new \WeakMap() as $driver => $ignored) {
                $driver->markSigwinch();
            }
        });
    }

    /**
     * Record a SIGWINCH for THIS instance only, refreshing its dimensions.
     */
    private function markSigwinch(): void
    {
        $this->sigwinchReceived = true;
        // Update stored dimensions on signal receipt
        $this->updateDimensions();
    }

    /**
     * Read the next resize event, or null if no resize has been detected.
     *
     * This method is non-blocking — it returns null immediately if no
     * SIGWINCH has been received since the last call.
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

        return new ResizeEvent($this->cols, $this->rows);
    }

    /**
     * Update stored terminal dimensions using tput.
     */
    private function updateDimensions(): void
    {
        $cols = $this->getTput('cols');
        $rows = $this->getTput('lines');

        if ($cols > 0) {
            $this->cols = $cols;
        }
        if ($rows > 0) {
            $this->rows = $rows;
        }
    }

    /**
     * Run tput and return the numeric value, or 0 on failure.
     *
     * @throws \InvalidArgumentException when the capability is not a
     *                   well-formed terminfo name — never shelled.
     */
    private function getTput(string $capability): int
    {
        $output = ($this->commandRunner)(self::tputCommand($capability));
        if ($output === null || $output === '') {
            return 0;
        }
        $value = (int) trim($output);
        return $value > 0 ? $value : 0;
    }

    /**
     * Gate then build the exact tput command line for one capability.
     *
     * Fail-fast: an unparseable capability throws before any shell-out.
     * The validated name is still escapeshellarg()'d (AGENTS.md: pass ALL
     * external-CLI flags every invocation via escapeshellarg()).
     */
    private static function tputCommand(string $capability): string
    {
        if (\preg_match(self::CAPABILITY_PATTERN, $capability) !== 1) {
            throw new \InvalidArgumentException(
                "SignalResizeDriver capability name rejected: " .
                var_export($capability, true) .
                " does not match terminfo shape ^[a-z][a-z0-9]{0,4}$"
            );
        }

        return 'tput ' . \escapeshellarg($capability) . ' 2>/dev/null';
    }
}
