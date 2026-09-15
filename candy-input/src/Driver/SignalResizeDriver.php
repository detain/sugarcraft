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

    /** Flag set by SIGWINCH signal handler */
    private static bool $sigwinchReceived = false;

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

        pcntl_async_signals(true);
        pcntl_signal(SIGWINCH, function (int $sig): void {
            self::$sigwinchReceived = true;
            // Update stored dimensions on signal receipt
            $this->updateDimensions();
        });

        // Capture initial dimensions
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

        if (!self::$sigwinchReceived) {
            return null;
        }

        self::$sigwinchReceived = false;

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
