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
    /**
     * @param string $title The title to latch, or '' when there is none to
     *                      latch but there IS usage to account — an empty or
     *                      unusable model answer, or a store that refused the
     *                      rename. {@see Chat::update()} drops an empty title
     *                      and keeps the money, which is why the failure paths
     *                      dispatch one of these rather than nothing.
     * @param ?Usage $usage What the title call cost, or null when the provider
     *                      reported nothing. Same rule the compaction call
     *                      follows ({@see HistoryCompactedMsg}): every provider
     *                      call this app makes on the user's key reaches the
     *                      tracker, whatever becomes of its answer.
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $title,
        public readonly ?Usage $usage = null,
    ) {}
}
