<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;
use SugarCraft\Crush\Sessions\BackgroundStopOutcome;

/**
 * Internal Msg carrying the OUTCOME of a `/bg stop <id>` (audit BG-1).
 *
 * {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor::stopSession()} waits —
 * boundedly, but for up to several seconds on its signal rungs — for the
 * daemon to exit, so it runs inside the Cmd {@see Chat::handleBackgroundCommand()}
 * returns rather than inside `update()`, the same reason
 * {@see BackgroundSessionSpawnedMsg} exists for the spawn.
 */
final class BackgroundSessionStoppedMsg implements Msg
{
    /**
     * @param string                $sessionId The id the user asked to stop.
     * @param BackgroundStopOutcome $outcome   What the supervisor achieved.
     * @param string|null           $name      The session's name, when the supervisor knows it.
     * @param string|null           $error     An unexpected throw from the stop itself, or null.
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly BackgroundStopOutcome $outcome,
        public readonly ?string $name = null,
        public readonly ?string $error = null,
    ) {
    }
}
