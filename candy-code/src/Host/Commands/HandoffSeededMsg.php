<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Usage;

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
     * @param string|null $remoteUrl set on the `/handoff --remote` arm (C-SES-7.g):
     *        the teleport target this landing belongs to — the local window must
     *        not switch onto the new id, so $sessionId stays null and this row is
     *        what tells the landing path which arm produced it
     */
    public function __construct(
        public readonly string $fromSessionId,
        public readonly ?string $sessionId,
        public readonly string $seed,
        public readonly bool $modelWritten,
        public readonly ?Usage $usage = null,
        public readonly ?string $error = null,
        public readonly ?string $remoteUrl = null,
    ) {
    }
}
