<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg dispatched once the background title call for a session
 * has produced (and persisted) an auto-generated title.
 *
 * Carried by the Cmd {@see Chat::scheduleTitleGeneration()} schedules
 * alongside — never instead of — the turn's own backend completion, so
 * the prompt is never blocked on the extra call. {@see Chat::update()}
 * folds the title into `currentSessionName` so the UI can show it live
 * without re-reading the session store.
 *
 * The title is model-authored, i.e. untrusted text bound for a tab
 * strip; `Chat::update()` re-sanitises it rather than trusting whatever
 * a caller put in this DTO.
 */
final class SessionTitledMsg implements Msg
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $title,
    ) {}
}
