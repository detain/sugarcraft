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
    /** Flag set by SIGWINCH signal handler */
    private static bool $sigwinchReceived = false;

    /** Last known terminal columns */
    private int $cols = 80;

    /** Last known terminal rows */
    private int $rows = 24;

    public function __construct()
    {
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
     */
    private function getTput(string $capability): int
    {
        $output = @shell_exec('tput ' . $capability . ' 2>/dev/null');
        if ($output === null || $output === '') {
            return 0;
        }
        $value = (int) trim($output);
        return $value > 0 ? $value : 0;
    }
}
