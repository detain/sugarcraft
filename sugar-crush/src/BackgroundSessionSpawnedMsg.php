<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg carrying the OUTCOME of a `/bg`/`/fork` spawn attempt
 * (crush_feat.md section 5 E3).
 *
 * `BackgroundSupervisor::spawnSession()` forks a daemon and then waits on a
 * socket handshake, so calling it inline from `Chat::update()` would freeze
 * the whole TUI - including the prompt the user is supposed to be freed to
 * keep typing into - for as long as the child takes to connect. The spawn
 * therefore runs inside the Cmd {@see Chat::scheduleBackgroundSpawn()}
 * returns, and its result comes back through this Msg the way every other
 * off-turn side effect on this class does ({@see SessionTitledMsg}).
 *
 * Exactly one of $sessionId / $error is non-null: a spawn either produced a
 * live session or threw, and reporting a session id for a failed spawn would
 * invite the user to `/agents` something that does not exist.
 */
final class BackgroundSessionSpawnedMsg implements Msg
{
    /**
     * @param string      $command   The slash command that asked for the spawn ('/bg' or '/fork'), used only to word the transcript line.
     * @param string      $name      The session name that was requested; still meaningful on failure.
     * @param string|null $sessionId Id of the spawned session, or null when the spawn failed.
     * @param string|null $error     Failure reason, or null on success.
     */
    public function __construct(
        public readonly string $command,
        public readonly string $name,
        public readonly ?string $sessionId = null,
        public readonly ?string $error = null,
    ) {}
}
