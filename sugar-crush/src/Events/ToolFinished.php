<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * "This tool call is over" — the terminating half of every {@see ToolStarted}.
 *
 * Exactly one of these follows each {@see ToolStarted} with the same
 * {@see $toolCallId}, whichever way the call ended: unknown tool name, a
 * {@see \SugarCraft\Crush\Hooks\HookManager::preToolUse()} denial, or a real
 * {@see \SugarCraft\Crush\Tools\Tool::execute()} return. The failure branches
 * carry a synthetic error {@see ToolResult} rather than a null result, so a
 * consumer has one shape to render.
 *
 * The whole {@see ToolResult} is carried (not just its text) because that is
 * where the renderer-side payloads live — the unified diff from an edit-shaped
 * tool ({@see ToolResult::diff()}, W1.F1) and image bytes from an
 * image-bearing tool ({@see ToolResult::imageBytes()}) — and re-deriving them
 * downstream would mean string-scanning a free-text summary.
 */
final readonly class ToolFinished
{
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public ToolResult $result,
    ) {}

    /**
     * Pair a result with the call that requested it.
     *
     * The id comes from the CALL, not from `$result->toolCallId()`: a tool
     * never sees its own call id (it only gets the decoded arguments), so
     * built-ins routinely return an empty or invented one — the engine echoes
     * the original id back for correlation, and this event has to match.
     * `$result` is otherwise passed through verbatim, so its own
     * `toolCallId()` may still hold that invented value; correlate on
     * {@see $toolCallId}.
     */
    public static function fromResult(ToolCall $call, ToolResult $result): self
    {
        return new self($call->id(), $call->name(), $result);
    }
}
