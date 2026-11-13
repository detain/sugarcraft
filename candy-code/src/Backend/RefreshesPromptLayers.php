<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use SugarCraft\Code\Backend;

/**
 * Opt-in declaration that a {@see Backend} freezes the system prompt's
 * session-stable layers and can be told to re-freeze them NOW (S-X03).
 *
 * WHY THE OWNER SAYS SO IN ADDITION TO THE BACKEND DETECTING IT. A turn
 * notices a rewritten history by itself ({@see \SugarCraft\Code\Context\SessionPromptMemo::observeTurn()}),
 * so the next TURN after `/clear` or a compaction already runs on rebuilt
 * layers. Nothing notices between the rewrite and that turn: `/context` and
 * `/context --raw` build the prompt without running one, and read the still
 * frozen repo map, memory and instruction slab beside fresh byte statistics.
 * The two refresh sites that rewrite the transcript on purpose — `/clear` and
 * `/compact` — call this so the introspection surfaces and the next turn agree.
 *
 * A session switch, a model switch and an ordinary turn are NOT callers
 * (ISS-054 keeps session switches out of the refresh points).
 *
 * A separate capability for the reason {@see DumpsSystemPrompt} gives:
 * `Backend` is a third-party extension point.
 */
interface RefreshesPromptLayers
{
    /**
     * Drop this backend's frozen layers for its session and what the shared
     * instruction loader read, so the next prompt build re-reads them.
     */
    public function refreshPromptLayers(): void;
}
