<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Host\TranscriptStore;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\HistorySanitizer;
use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Role;

/**
 * The conversation-inheriting fork (CC `--fork-session` parity, opt-in).
 *
 * A plain Task launch starts the child from its delegated prompt alone —
 * the contract every preset documents. A fork additionally copies the
 * tail of the PARENT's persisted conversation into the child's context,
 * so the child knows what was said before it was asked. The copy is read
 * through the same store the launch records child sessions INTO
 * ({@see AgentManager::recordChildSessionsIn()}), so what a fork inherits
 * is exactly the parent transcript on disk — never a process-memory echo
 * the two paths could drift apart from.
 *
 * Three deliberate limits, each the reason it exists:
 * - Window: the last {@see MAX_INHERITED_MESSAGES} rows. A whole long
 *   session would re-bill the parent's entire history into every child;
 *   the tail carries the working context, and the child's own delegated
 *   prompt always rides last as the instruction.
 * - Text only: user/assistant TEXT turns are re-voiced; tool-call/result
 *   pairs are not. Rebuilding step pairs needs the private step-folding
 *   the parent engine does for its own history, and half a pair (a call
 *   whose result fell outside the window) is a provider-400 shape. The
 *   rows are cleaned with {@see HistorySanitizer} — the same sanitizer
 *   the parent's own next request runs its history through — so what the
 *   child sees is a legal slice of what the parent would have seen.
 * - Freshness: the parent's transcript is written debounced during its
 *   turn, so the very newest in-flight rows may not be persisted yet at
 *   launch time. The delegation prompt is unaffected (it is not read from
 *   the transcript), and a launch after any settled turn inherits fully.
 *
 * Inherited rows are billed as input tokens to the child on every launch
 * — that is the cost the default-OFF `fork` argument defends against.
 * System rows never ride: the child gets its own preset's system turn.
 */
final class ConversationFork
{
    /**
     * How many persisted parent messages at most a fork inherits. Sized to
     * the tail that carries working context while keeping a forked child's
     * input bill bounded; a delegating model that needs more of the past
     * should say so in the prompt — the self-contained-prompt contract
     * still stands, `fork` only softens it.
     */
    public const MAX_INHERITED_MESSAGES = 30;

    /**
     * The rows a fork of session $sessionId inherits, oldest first, or an
     * empty list when there is nothing to inherit (no manager/store/transcript
     * bound, unreadable store, no rows). Never throws: inheritance is an
     * opt-in convenience, and its failure must not sink the delegation.
     *
     * @return list<Message>
     */
    public static function inheritedContext(?AgentManager $manager, ?string $sessionId): array
    {
        if ($manager === null || $sessionId === null || $sessionId === '') {
            return [];
        }

        $store = $manager->boundSessionStore();
        if ($store === null) {
            return [];
        }

        $rows = TranscriptStore::new($store)->load($sessionId);
        if ($rows === []) {
            return [];
        }

        $carried = [];
        foreach ($rows as $row) {
            if ($row->role === Role::System) {
                continue;
            }
            $carried[] = $row->role === Role::Assistant
                ? new AssistantMessage($row->content, null, $row->reasoning)
                : new UserMessage($row->content);
        }

        return HistorySanitizer::sanitize(array_slice($carried, -self::MAX_INHERITED_MESSAGES));
    }
}
