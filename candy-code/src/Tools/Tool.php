<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

/**
 * Contract every built-in and user-supplied tool implements.
 *
 * {@see ToolResult} (and its {@see ToolCall} counterpart) is the CANONICAL
 * of the two tool type pairs crush_feat.md §1 D flags: it is what this
 * interface returns, what {@see \SugarCraft\Code\Runtime} executes with,
 * and what every `ProviderInterface`/`Events\Tool*` event speaks. The
 * Chat/Renderer-side pair (`Crush\ToolCall`/`Crush\ToolResult`) adapts to
 * and from this one via `Crush\ToolCall::toEngineCall()`/`fromEngineCall()`
 * and `Crush\ToolResult::toEngineResult()`/`fromEngineResult()`; a tool
 * should never reach for that pair directly.
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    public function inputSchema(): array;

    public function execute(array $args): ToolResult;
}
