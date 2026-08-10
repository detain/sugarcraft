<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Dispatched once every tool call from one assistant turn has finished
 * executing (see {@see Chat}'s async tool-execution path). Carries the
 * original assistant message (so its content/role can be appended to
 * history alongside the results, exactly as the old synchronous
 * handleToolCalls() did in one step) plus the real {@see ToolResult}s that
 * replace the "running" placeholders {@see Message::toolRunning()} put in
 * history the moment the tool calls were dispatched.
 */
final class ToolResultsMsg implements Msg
{
    /**
     * @param list<ToolResult> $results
     */
    public function __construct(
        public readonly Message $assistantMessage,
        public readonly array $results,
        public readonly ?int $generation = null,
    ) {}
}
