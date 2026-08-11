<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\Concerns;

/**
 * Splits a model's "thinking" out of `content` regardless of which
 * server-side reasoning parser produced the response - shared by every
 * OpenAI-compatible provider so the split logic lives in exactly one place
 * (previously `SglangProvider`/`CustomProvider`/`OpenAIProvider` each
 * hardcoded `reasoning: null` independently).
 *
 * Mirrors SGLang's own `--reasoning-parser` duality (crush_feat.md §12 D1/D3):
 * the deployed `minimax` parser (`Qwen3Detector`, `force_reasoning=true`)
 * already splits reasoning into a dedicated `reasoning_content` field and
 * leaves `content` clean, while the narrower `minimax-append-think` detector
 * never populates `reasoning_content` at all and instead leaves raw
 * `<think>...</think>` markup inline in `content`. A client that only
 * handles one shape silently breaks reasoning display the moment the
 * server-side flag changes; this extractor handles both so it never does.
 */
trait ReasoningExtractor
{
    /**
     * @param array<string, mixed> $message a chat-completion `message` (or
     *   streaming `delta`) payload carrying `content` and, on a
     *   properly-splitting parser, `reasoning_content`
     * @return array{0: ?string, 1: string} `[reasoning, content]` - content
     *   has any inline `<think>` markup already stripped
     */
    private function extractReasoning(array $message): array
    {
        // Case 1: server-side parser split it out already (SGLang `minimax`/
        // Qwen3Detector, or any other properly-splitting parser). Trust it
        // directly - `content` has already had the reasoning removed.
        //
        // Deliberately NOT `!empty()`: PHP counts the one-character string
        // "0" as empty, so a genuine (if terse) reasoning_content of "0"
        // would be misread as absent and fall through to the <think>-strip
        // fallback, reporting reasoning: null for a field the server did
        // populate. Only null/missing/'' mean "the parser didn't split".
        $reasoningContent = $message['reasoning_content'] ?? null;
        if (is_string($reasoningContent) && $reasoningContent !== '') {
            return [$reasoningContent, $message['content'] ?? ''];
        }

        // Case 2: no reasoning_content field, but raw <think>...</think>
        // markup is still inline in content (SGLang `minimax-append-think`,
        // or any parser/model combination that didn't split). Strip it out
        // client-side so reasoning still renders separately.
        $content = $message['content'] ?? '';
        if (preg_match('/<think>(.*?)<\/think>/s', $content, $m)) {
            $reasoning = trim($m[1]);
            $content = trim(preg_replace('/<think>.*?<\/think>/s', '', $content, 1));

            return [$reasoning, $content];
        }

        return [null, $content];
    }
}
