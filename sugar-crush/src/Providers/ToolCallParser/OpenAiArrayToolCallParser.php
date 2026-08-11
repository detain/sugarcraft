<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

use SugarCraft\Crush\Tools\ToolCall;

/**
 * The default parser: reads the OpenAI-standard `message.tool_calls[]` array.
 *
 * This is the correct strategy for any server actually launched with a real
 * `--tool-call-parser` flag (SGLang, vLLM) or for OpenAI itself, because the
 * server has already decoded the model's native syntax into the wire schema.
 * The logic is the one previously inlined byte-identically in
 * `SglangProvider`/`CustomProvider`/`OpenAIProvider` (crush_feat.md §12 D6).
 */
final readonly class OpenAiArrayToolCallParser implements ToolCallParserInterface
{
    /**
     * @param (\Closure(mixed, string): array<string, mixed>)|null $argumentDecoder
     *        Decodes one call's raw `function.arguments` payload. Injected
     *        rather than hardcoded so a provider can hand over its own decoder
     *        - notably `SglangProvider`'s, which reports the MiniMax
     *        `</parameter>` truncation bug (§12 D5) instead of silently
     *        degrading to no arguments. Left null, this class falls back to a
     *        plain decode so it stays usable standalone.
     */
    public function __construct(
        private ?\Closure $argumentDecoder = null,
    ) {}

    /**
     * Default root factory, per repo convention.
     *
     * @param (\Closure(mixed, string): array<string, mixed>)|null $argumentDecoder
     */
    public static function new(?\Closure $argumentDecoder = null): self
    {
        return new self($argumentDecoder);
    }

    /**
     * @param array<string, mixed> $message
     * @return array<ToolCall>|null
     */
    public function parse(array $message): ?array
    {
        $rawCalls = $message['tool_calls'] ?? null;

        if (!is_array($rawCalls)) {
            return null;
        }

        $calls = [];

        foreach ($rawCalls as $tc) {
            if (!is_array($tc)) {
                continue;
            }

            // Resolved once so the name used for the ToolCall and the name used
            // to label a decode warning cannot disagree; reading it unguarded
            // would raise an undefined-key warning and then a TypeError on
            // ToolCall's `string $name` before the decoder ever reported
            // anything.
            $name = (string) ($tc['function']['name'] ?? '');

            $calls[] = ToolCall::fromArray([
                'id' => (string) ($tc['id'] ?? ''),
                'name' => $name,
                'arguments' => $this->decodeArguments($tc['function']['arguments'] ?? '', $name),
            ]);
        }

        return $calls;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArguments(mixed $raw, string $toolName): array
    {
        if ($this->argumentDecoder !== null) {
            return ($this->argumentDecoder)($raw, $toolName);
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
