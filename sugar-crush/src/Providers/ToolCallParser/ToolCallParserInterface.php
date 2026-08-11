<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

use SugarCraft\Crush\Tools\ToolCall;

/**
 * Strategy for turning one OpenAI-shaped assistant `message` object into the
 * tool calls it requests.
 *
 * Mirrors SGLang's own `--tool-call-parser` CLI concept (crush_feat.md §12 D6):
 * the server decides how a model's native tool-call syntax is decoded, and the
 * shape that reaches the wire differs accordingly. Making the client side
 * pluggable too means a deployment launched *without* the right parser flag
 * degrades instead of losing tool calls outright, and it lets the three
 * providers that currently inline byte-identical parsing share one
 * implementation.
 */
interface ToolCallParserInterface
{
    /**
     * Parse the tool calls out of an assistant message.
     *
     * Returns `null` - not `[]` - when the message requests no tools at all,
     * because {@see \SugarCraft\Crush\Providers\CompleteResponse::$toolCalls}
     * distinguishes "no tool calls" (null) from "a tool-call turn" and the
     * providers propagate that distinction verbatim.
     *
     * @param array<string, mixed> $message The `choices[0].message` object.
     * @return array<ToolCall>|null
     */
    public function parse(array $message): ?array;
}
