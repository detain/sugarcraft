<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg carrying the answer to the background "what will the user
 * most likely type next?" call {@see Chat::schedulePromptSuggestion()} makes
 * once a turn settles.
 *
 * {@see Chat::update()} latches the text as the input box's grayed ghost
 * suggestion (→ on an empty box accepts it), but only while the chat is
 * still in the state the call was made for: stamped with the turn
 * $generation, transcript length and session it followed, so a suggestion that lands after the user has
 * sent something else - or cancelled, cleared, or switched sessions - is
 * dropped rather than painted under a conversation it was not written for.
 *
 * The text is model-authored and re-sanitised on arrival; the usage is
 * accounted whatever becomes of the text, the rule every provider call on
 * the user's key follows ({@see SessionTitledMsg}).
 */
final class PromptSuggestionMsg implements Msg
{
    /**
     * @param string $suggestion The predicted next prompt, or '' when the
     *                           model had nothing useful (still dispatched,
     *                           to carry $usage)
     * @param int    $generation   {@see Chat}'s turn generation when the call
     *                             was scheduled
     * @param int    $historyCount the transcript's length then
     * @param ?string $sessionId   the session it was scheduled in
     * @param ?Usage $usage        What the call cost, or null when unreported
     */
    public function __construct(
        public readonly string $suggestion,
        public readonly int $generation,
        public readonly int $historyCount,
        public readonly ?string $sessionId,
        public readonly ?Usage $usage = null,
    ) {}
}
