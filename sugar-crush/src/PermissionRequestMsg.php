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
 * can raise the same prompt: the Chat-native tool path builds it directly
 * today, and the engine path ({@see Runtime}) can dispatch it once its ASK
 * resolver is threaded through {@see Backend\EngineBackend} FROM THE TUI.
 *
 * That resolver seam is no longer unused — the console callers attach
 * {@see Cli\HeadlessPermissionPrompt} to it — but a blocking closure is the
 * wrong shape for this Msg, which is settled asynchronously by a later
 * {@see PermissionReplyMsg}, and {@see Backend\EngineBackend::completeAsync()}
 * runs its turn in a forked child that has no way to send a question home. So
 * nothing dispatches this from the engine path yet.
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
     */
    public function __construct(
        public readonly Message $assistantMessage,
        public readonly ToolCall $toolCall,
        public readonly string $prompt,
        public readonly ?int $generation = null,
    ) {}
}
