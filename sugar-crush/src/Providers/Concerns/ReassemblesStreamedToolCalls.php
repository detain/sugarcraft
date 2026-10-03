<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\Concerns;

use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Reassembles OpenAI-shaped streamed tool calls (roadmap X-31a): a streamed
 * reply sends each call as successive `delta.tool_calls[]` fragments - the
 * first naming the call's `id` and `function.name`, the rest carrying pieces
 * of `function.arguments` that only form JSON once the call is complete.
 *
 * Extracted for {@see \SugarCraft\Crush\Providers\OpenAIProvider}, which until
 * X-31a hard-coded `toolCalls: null` on every chunk - and since
 * `supportsStreaming()` is always true, Runtime only ever took the stream
 * path, so no OpenAI tool call ever reached it. The semantics are
 * {@see \SugarCraft\Crush\Providers\CustomProvider}'s
 * `resolveStreamedToolCalls()` / `flushBufferedToolCalls()` (audits W1.A1,
 * 15a A4, A11, A23), which SglangProvider repeats near-verbatim; both can
 * adopt this trait in place of their copies.
 *
 * ONE DIFFERENCE, AND WHY: fragments are keyed by the wire's `index` when it
 * is present, as the raw-JSON providers see it. The openai-php SDK's
 * `CreateStreamedResponseToolCall::toArray()` DROPS `index`, so on that path
 * a fragment carrying an `id` other than the current call's opens a new call
 * and an id-less one continues the current call - the order the API streams
 * parallel calls in (each call's fragments complete before the next starts).
 *
 * The buffer is a local of one `completeStream()` call, threaded by
 * reference: every provider using this is a `final readonly class`.
 */
trait ReassemblesStreamedToolCalls
{
    /**
     * Longest raw-argument excerpt quoted in a drop warning.
     */
    private const STREAMED_TOOL_CALL_EXCERPT_LIMIT = 200;

    /**
     * Buffer this delta's tool-call fragments; when $finishReason is
     * `tool_calls` (the server declaring the calls complete), assemble every
     * buffered call, drain the buffer and return them. Null otherwise - on
     * any other end the buffer is left for {@see flushStreamedToolCalls()}.
     *
     * A payload that does not decode is still emitted (the server said the
     * call was complete) but carries its `argumentsError` so Runtime reports
     * it instead of running the tool with `[]` (audit A11); the raw string
     * rides along to be replayed verbatim (audit A23).
     *
     * @param array<string, mixed> $delta
     * @param array<int, array{id?: ?string, name?: ?string, arguments?: string}> $toolCallBuffer
     * @return ?list<ToolCall>
     */
    private function reassembleStreamedToolCalls(array $delta, ?string $finishReason, array &$toolCallBuffer): ?array
    {
        $fragments = \is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [];
        foreach ($fragments as $tc) {
            if (!\is_array($tc)) {
                continue;
            }
            $idx = self::streamedToolCallSlot($tc, $toolCallBuffer);
            $function = \is_array($tc['function'] ?? null) ? $tc['function'] : [];
            $toolCallBuffer[$idx]['id'] ??= \is_string($tc['id'] ?? null) ? $tc['id'] : null;
            $toolCallBuffer[$idx]['name'] ??= \is_string($function['name'] ?? null) ? $function['name'] : null;
            $toolCallBuffer[$idx]['arguments'] = ($toolCallBuffer[$idx]['arguments'] ?? '')
                . (\is_string($function['arguments'] ?? null) ? $function['arguments'] : '');
        }

        if ($finishReason !== 'tool_calls' || $toolCallBuffer === []) {
            return null;
        }

        $toolCalls = array_values(array_map(
            static fn (array $tc): ToolCall => ToolCall::fromArray([
                'id' => $tc['id'] ?? '',
                'name' => $tc['name'] ?? '',
                'arguments' => \is_array($decoded = json_decode($tc['arguments'] ?? '{}', true)) ? $decoded : [],
                'argumentsError' => ToolCall::argumentsErrorFor($tc['arguments'] ?? null),
                'rawArguments' => $tc['arguments'] ?? null,
            ]),
            $toolCallBuffer,
        ));
        $toolCallBuffer = [];

        return $toolCalls;
    }

    /**
     * Drain fragments a stream left buffered because it never sent
     * `finish_reason: "tool_calls"` - it ended on `stop`, on a truncating
     * reason, or with no finish at all (audit 15a A4). Call it once, after
     * the stream's last frame, unless the stream ended on `error` (the server
     * disowned its own generation).
     *
     * Decode-or-drop, never half-decoded: the server did not declare these
     * calls complete, so a payload that is not a whole JSON object may be a
     * call that was never finished, and running it is the silent corruption
     * the drop prevents. Each drop names itself through
     * {@see RuntimeNoticeSink::warn()}. An EMPTY payload is a genuine
     * zero-argument call on a clean end and runs with `[]`; on a truncated
     * end it is indistinguishable from a call cut off before its arguments,
     * so it drops.
     *
     * @param array<int, array{id?: ?string, name?: ?string, arguments?: string}> $toolCallBuffer
     * @param string $provider the provider named in a drop warning
     * @return ?list<ToolCall> null when nothing survived
     */
    private static function flushStreamedToolCalls(
        array $toolCallBuffer,
        bool $truncated,
        ?string $finishReason,
        string $provider,
    ): ?array {
        $why = $truncated
            ? 'the stream was truncated before its arguments completed'
            : sprintf(
                'the stream ended with finish_reason "%s" without declaring its tool calls complete',
                (string) $finishReason,
            );
        $calls = [];

        foreach ($toolCallBuffer as $tc) {
            $name = (string) ($tc['name'] ?? '');
            $raw = \is_string($tc['arguments'] ?? null) ? $tc['arguments'] : '';

            if (trim($raw) === '') {
                if ($truncated) {
                    RuntimeNoticeSink::warn(sprintf(
                        '%s: tool call "%s" arguments never streamed (empty payload); '
                        . 'the call is being DROPPED, not executed, because %s.',
                        $provider,
                        $name,
                        $why,
                    ));
                    continue;
                }
                $arguments = [];
            } else {
                $decoded = json_decode($raw, true);
                if (!\is_array($decoded)) {
                    RuntimeNoticeSink::warn(sprintf(
                        '%s: tool call "%s" arguments are not a complete JSON object (%s); '
                        . 'the call is being DROPPED, not executed, because %s. Raw payload: %s',
                        $provider,
                        $name,
                        json_last_error() === JSON_ERROR_NONE ? 'decoded to ' . get_debug_type($decoded) : json_last_error_msg(),
                        $why,
                        \strlen($raw) <= self::STREAMED_TOOL_CALL_EXCERPT_LIMIT
                            ? $raw
                            : substr($raw, 0, self::STREAMED_TOOL_CALL_EXCERPT_LIMIT) . ' [...]',
                    ));
                    continue;
                }
                $arguments = $decoded;
            }

            $calls[] = ToolCall::fromArray([
                'id' => $tc['id'] ?? '',
                'name' => $name,
                'arguments' => $arguments,
                'rawArguments' => $raw,
            ]);
        }

        return $calls === [] ? null : $calls;
    }

    /**
     * The buffer slot one fragment belongs to: its wire `index` when it has
     * one; otherwise (the SDK path, see the class docblock) a new slot when it
     * names an id the current call does not have, the current slot when it
     * does not.
     *
     * @param array<string, mixed> $fragment
     * @param array<int, array{id?: ?string, name?: ?string, arguments?: string}> $toolCallBuffer
     */
    private static function streamedToolCallSlot(array $fragment, array $toolCallBuffer): int
    {
        if (\is_int($fragment['index'] ?? null)) {
            return $fragment['index'];
        }

        if ($toolCallBuffer === []) {
            return 0;
        }

        $current = array_key_last($toolCallBuffer);
        $id = \is_string($fragment['id'] ?? null) && $fragment['id'] !== '' ? $fragment['id'] : null;
        $currentId = $toolCallBuffer[$current]['id'] ?? null;

        return $id !== null && $currentId !== null && $id !== $currentId ? $current + 1 : $current;
    }
}
