<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

use SugarCraft\Crush\Tools\ToolCall;

/**
 * "This tool call is about to run" — emitted by {@see \SugarCraft\Crush\Runtime}
 * for every tool call the model asked for, before the hook gate or the tool
 * itself gets a say.
 *
 * Exists because the engine pipeline used to swallow every intermediate tool
 * call inside {@see \SugarCraft\Crush\Backend\EngineBackend::complete()}'s
 * bounded loop (crush_feat.md §1 D/E1): only the final assistant message
 * escaped, so a turn that silently ran eight rounds of tools rendered as a
 * bare "thinking…" spinner. A consumer pairs this event with the matching
 * {@see ToolFinished} (same {@see $toolCallId}) to drive the placeholder-then-
 * replace rendering shape.
 *
 * Emitted even for a tool name the engine cannot resolve, so a consumer never
 * has to special-case a {@see ToolFinished} with no preceding start.
 */
final readonly class ToolStarted
{
    /**
     * @param array<string, mixed> $arguments the model's raw tool input, as
     *                                        requested — NOT the possibly
     *                                        hook-rewritten arguments the tool
     *                                        eventually executes with, because
     *                                        this fires before the pre-hook runs.
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments = [],
    ) {}

    public static function fromCall(ToolCall $call): self
    {
        return new self($call->id(), $call->name(), $call->arguments());
    }
}
