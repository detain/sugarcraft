<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Core\Msg;
use SugarCraft\Crush\Usage;

/**
 * A `/handoff` landing (roadmap 5.14c): the new session is open in the store,
 * seeded with the state summary, and the window that asked may move onto it.
 *
 * Built by {@see HandoffHostCommand::call()} off `update()` — the summary is a
 * model round-trip and the fork a store write — and handed back to
 * `Chat::landHandoff()`, which switches only if the window is still on
 * $fromSessionId and idle.
 */
final class HandoffSeededMsg implements Msg
{
    /**
     * @param string $fromSessionId the session the handoff was typed in
     * @param string|null $sessionId the new session, or null when it could not be opened
     * @param string $seed the state row the new session starts from (agent-visible)
     * @param bool $modelWritten whether a summary model wrote the block, rather than the heuristic
     * @param string|null $error why there is no new session, or why the model's block was not used
     */
    public function __construct(
        public readonly string $fromSessionId,
        public readonly ?string $sessionId,
        public readonly string $seed,
        public readonly bool $modelWritten,
        public readonly ?Usage $usage = null,
        public readonly ?string $error = null,
    ) {
    }
}
