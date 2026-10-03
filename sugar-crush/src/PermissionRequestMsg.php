<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Raises a blocking permission prompt for ONE tool call (crush_feat.md §1 E2).
 *
 * Dispatched into {@see Chat::update()} when a `PreToolUse` hook answers
 * {@see \SugarCraft\Crush\Hooks\HookResult::ask()} instead of allow/deny:
 * the hook has no verdict and defers to the user, so the whole batch of tool
 * calls from `$assistantMessage` is paused until a {@see PermissionReplyMsg}
 * arrives. Handling it mirrors {@see ToolResultsMsg} — a Msg that advances
 * the tool-execution flow rather than a keystroke.
 *
 * It is a Msg, not a private call inside {@see Chat}, so that any pipeline
 * can raise the same prompt, and two do:
 *
 * - the Chat-native tool path ({@see Chat::beginToolCalls()}), which parks the
 *   gated batch and resumes it from the answer;
 * - the ENGINE path (roadmap 1.C-2): a turn started through
 *   {@see Backend\InteractiveTurn::completeInteractive()} puts each ASK on the
 *   turn's event channel as a {@see Events\PermissionAsked}, and Chat raises
 *   this Msg for it carrying the {@see Backend\PendingAsk} — the only route
 *   from the user's keypress back to the forked child blocked on the
 *   question. There is no parked batch on that path (the child owns the
 *   call), so `$assistantMessage` is an empty placeholder and the answer goes
 *   out through `$pendingAsk` instead.
 */
final class PermissionRequestMsg implements Msg
{
    /**
     * @param Message  $assistantMessage the turn whose tool calls are paused,
     *                                   replayed unchanged once the user answers
     * @param ToolCall $toolCall         the call the hook asked about
     * @param string   $prompt           the hook's question, rendered as the prompt body
     * @param int|null $generation       stamped like {@see AssistantMsg}'s, so an
     *                                   answer for a superseded turn can be recognised
     * @param ?Backend\PendingAsk $pendingAsk the engine-path reply handle; null on
     *                                   the Chat-native path
     */
    public function __construct(
        public readonly Message $assistantMessage,
        public readonly ToolCall $toolCall,
        public readonly string $prompt,
        public readonly ?int $generation = null,
        public readonly ?Backend\PendingAsk $pendingAsk = null,
    ) {}
}
