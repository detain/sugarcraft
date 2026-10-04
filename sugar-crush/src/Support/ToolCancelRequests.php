<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * The turn child's view of `cancel_tool{callId}` (roadmap 1.C-4b): which of
 * the turn's running tool calls the user has asked to stop.
 *
 * WHY A PROCESS-SCOPED SEAM. The request arrives on the turn's frame channel
 * ({@see \SugarCraft\Crush\Backend\ChildChannel}), which only the turn loop
 * holds, but the code that can act on it sits several frames below: the reap
 * loop of {@see \SugarCraft\Crush\Runtime::executeConcurrently()}, which owns
 * each member's pid, and a lone Task's own progress hook. Neither is handed
 * the channel, and threading a probe through `Runtime::run()` would widen a
 * signature every turn shares for a question only a forked turn can ask. So
 * the turn child names the channel here once ({@see listen()}), and whoever
 * runs a call asks by its id ({@see isRequested()}).
 *
 * OWNED BY ONE PROCESS. A request is about the turn child's own calls. A
 * process forked below it (a concurrent member) inherits this state, and
 * must neither read the turn socket nor act on the parent's requests — the
 * turn child kills that member itself — so every answer outside the
 * listening process is false.
 *
 * Requests are LATCHED: once a call is named it stays cancelled for the rest
 * of the turn, however many times it is asked about.
 */
final class ToolCancelRequests
{
    /** What a call stopped this way reports, to the model and on its row. */
    public const CANCELLED = 'Cancelled by the user (Esc) while it was running.';

    /** Pull the channel at most this often; a running call asks on every poll pass. */
    private const PULL_INTERVAL_SECONDS = 0.05;

    /** @var (\Closure(): list<string>)|null */
    private static ?\Closure $source = null;

    private static int $ownerPid = 0;

    private static float $lastPull = 0.0;

    /** @var array<string, true> */
    private static array $requested = [];

    /**
     * Name where this process's requests come from — the turn child's
     * channel — and forget any earlier turn's.
     *
     * @param \Closure(): list<string> $source the call ids requested since its last call
     */
    public static function listen(\Closure $source): void
    {
        self::$source = $source;
        self::$ownerPid = (int) getmypid();
        self::$lastPull = 0.0;
        self::$requested = [];
    }

    /** Stop listening (the turn is over, or a test is done). */
    public static function forget(): void
    {
        self::$source = null;
        self::$ownerPid = 0;
        self::$lastPull = 0.0;
        self::$requested = [];
    }

    /**
     * Whether the user asked to stop the call with this id. Always false in a
     * process other than the one that listened, and for an empty id.
     */
    public static function isRequested(string $callId): bool
    {
        if ($callId === '' || self::$source === null || (int) getmypid() !== self::$ownerPid) {
            return false;
        }

        $now = microtime(true);
        if ($now - self::$lastPull >= self::PULL_INTERVAL_SECONDS) {
            self::$lastPull = $now;
            foreach ((self::$source)() as $id) {
                if (is_string($id) && $id !== '') {
                    self::$requested[$id] = true;
                }
            }
        }

        return isset(self::$requested[$callId]);
    }
}
