<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Offline provider that echoes the last user turn back as a Markdown
 * blockquote. Lets the binary run with zero network dependencies — the
 * default when no real provider is configured, and the backbone of the
 * test/demo experience.
 *
 * Mirrors the original sugar-crush EchoBackend, re-expressed against the
 * ProviderInterface so it composes with the Runtime/Agent engine.
 *
 * SCRIPTED TOOL CALLS (roadmap O-5b, Appendix O §7.8). A user turn whose
 * lines read `::tool <Name> <json-object>` makes the reply a real tool call
 * instead of an echo: one call per such line, run by the engine exactly as a
 * model's would be — through the permission gate, the hooks and the tool
 * itself. Once the results are in, the next step answers with what each call
 * returned, so the turn settles like any other. This is what lets the web
 * UI's end-to-end tests (and a demo with no model) drive tool cards, diffs
 * and permission questions deterministically and offline. Arguments that are
 * not a JSON object reach the engine as an undecodable call
 * ({@see ToolCall::argumentsError()}), which the engine refuses — the same
 * path a model's broken JSON takes. Lines that are not `::tool` lines are
 * ignored in a scripted turn. The marker is `::`, not the `!tool` Appendix O
 * sketched, because a prompt that starts with `!` is the user's own shell
 * command ({@see \SugarCraft\Crush\Commands\BangShell}) and never reaches a
 * provider.
 *
 * {@see supportsFunctionCalling()} stays false: no request's tool schema is
 * read, and nothing here chooses a tool — the user named it.
 */
final class EchoProvider implements ProviderInterface
{
    public function name(): string
    {
        return 'echo';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsFunctionCalling(): bool
    {
        return false;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    /**
     * 0 — "unknown", not "enormous".
     *
     * This provider has no model behind it; it concatenates a blockquote in
     * PHP. There is no window it could report, and the 1,000,000 it used to
     * return was a stand-in for "effectively unlimited" from a time when
     * nothing read this method (measured: zero call sites in `src/` before
     * crush_code.md Phase 5 item 4). Now that
     * {@see \SugarCraft\Crush\Chat}'s context tiers are percentages of it,
     * an invented 1,000,000 switched all four of them off on the one path
     * that runs by default: {@see \SugarCraft\Crush\Cli\Bootstrap::backend()}
     * builds `EngineBackend(EchoProvider)` both as the offline fallback and
     * as the degrade-after-provider-failure path, so the tiers would have
     * fired at 700,000 / 850,000 / 950,000 / 1,000,000 estimated tokens
     * instead of the 70,000 / 85,000 / 95,000 / 100,000 they acted on before
     * that item.
     *
     * Returning 0 routes the answer through
     * {@see \SugarCraft\Crush\Context\ContextWindow::resolve()} instead, so
     * the offline path gets the one named, auditable
     * {@see \SugarCraft\Crush\Context\ContextWindow::FALLBACK_TOKENS}
     * rather than a fabrication that looks authoritative. See
     * {@see ProviderInterface::contextWindow()} for the convention.
     */
    public function contextWindow(): int
    {
        return 0;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $calls = $this->scriptedCalls($request->messages);
        if ($calls !== []) {
            return new CompleteResponse(content: '', toolCalls: $calls);
        }

        return new CompleteResponse(content: $this->reply($request->messages));
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        $calls = $this->scriptedCalls($request->messages);
        if ($calls !== []) {
            yield new CompleteResponse(content: '', toolCalls: $calls);

            return;
        }

        // Emit the reply in whitespace-delimited pieces so the UI exercises
        // incremental rendering even with no network in the loop.
        foreach ($this->pieces($this->reply($request->messages)) as $piece) {
            yield new CompleteResponse(content: $piece);
        }
    }

    /**
     * The tool calls a scripted user turn asks for — or none, when the turn
     * is not scripted or its calls already ran (their results follow it).
     *
     * @param array<int, Message> $messages
     * @return list<ToolCall>
     */
    private function scriptedCalls(array $messages): array
    {
        [$at, $lines] = $this->scriptedTurn($messages);
        if ($at === null || $this->resultsAfter($messages, $at) !== []) {
            return [];
        }

        $calls = [];
        foreach ($lines as $n => [$name, $json]) {
            $id = 'echo_call_' . ($n + 1);
            $decoded = $json === '' ? [] : json_decode($json, true);
            $calls[] = \is_array($decoded) && ($decoded === [] || !array_is_list($decoded))
                ? new ToolCall($id, $name, $decoded, null, $json === '' ? null : $json)
                : new ToolCall($id, $name, [], \sprintf('the arguments for %s are not a JSON object: %s', $name, $json));
        }

        return $calls;
    }

    /**
     * The index of the last user turn and its `::tool` lines as
     * [name, raw-json] pairs; [null, []] when that turn is not scripted.
     *
     * @param array<int, Message> $messages
     * @return array{0: ?int, 1: list<array{0: string, 1: string}>}
     */
    private function scriptedTurn(array $messages): array
    {
        $at = $this->lastUserIndex($messages);
        if ($at === null) {
            return [null, []];
        }

        $lines = [];
        foreach (preg_split('/\R/', $messages[$at]->content()) ?: [] as $line) {
            if (preg_match('/^\s*::tool\s+([A-Za-z_][A-Za-z0-9_.:-]*)\s*(.*?)\s*$/', $line, $m) === 1) {
                $lines[] = [$m[1], $m[2]];
            }
        }

        return $lines === [] ? [null, []] : [$at, $lines];
    }

    /**
     * @param array<int, Message> $messages
     */
    private function lastUserIndex(array $messages): ?int
    {
        $at = null;
        foreach ($messages as $i => $msg) {
            // Step 1.A-1: the harness's `<turn-context>` row is user-ROLE but
            // not the user's turn, so it is never the thing echoed.
            if ($msg instanceof Message && $msg->role() === 'user' && !TurnContextBlock::isTurnContext($msg)) {
                $at = $i;
            }
        }

        return $at;
    }

    /**
     * The tool results that answer calls made after the user turn at $at,
     * each with the name of the call it answers.
     *
     * @param array<int, Message> $messages
     * @return list<array{0: string, 1: ToolResultMessage}>
     */
    private function resultsAfter(array $messages, int $at): array
    {
        $names = [];
        $results = [];
        foreach ($messages as $i => $msg) {
            if ($i <= $at) {
                continue;
            }
            if ($msg instanceof AssistantMessage) {
                foreach ($msg->toolCalls() ?? [] as $call) {
                    if ($call instanceof ToolCall) {
                        $names[$call->id()] = $call->name();
                    }
                }
            }
            if ($msg instanceof ToolResultMessage) {
                $results[] = [$names[$msg->toolCallId()] ?? 'tool', $msg];
            }
        }

        return $results;
    }

    /**
     * The text reply: what a scripted turn's calls returned, or the echo.
     *
     * @param array<int, Message> $messages
     */
    private function reply(array $messages): string
    {
        [$at] = $this->scriptedTurn($messages);
        if ($at === null) {
            return $this->echo($messages);
        }

        $parts = [];
        foreach ($this->resultsAfter($messages, $at) as [$name, $result]) {
            // The `<ctx-ref>` handle the engine appends for the model's
            // pruning tools is not part of what the tool returned.
            $content = RefTag::stripFrom($result->content());
            $body = trim($content) === '' ? '(no output)' : $content;
            $parts[] = \sprintf('Tool `%s` %s:', $name, $result->isError() ? 'failed' : 'returned')
                . "\n\n" . (string) preg_replace('/^/m', '> ', $body);
        }

        return $parts === [] ? '_(no tool ran)_' : implode("\n\n", $parts);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $inputs = is_array($request->input) ? $request->input : [$request->input];

        // Deterministic, network-free pseudo-embeddings (one dimension per
        // input, its character length) — enough for wiring tests, not for
        // real similarity search.
        return new EmbeddingsResponse(embeddings: array_map(
            static fn (string $text): array => [(float) mb_strlen($text)],
            array_values($inputs),
        ));
    }

    /**
     * @param array<int, Message> $messages
     */
    private function echo(array $messages): string
    {
        $lastUser = '';
        foreach ($messages as $msg) {
            // Step 1.A-1: the harness's `<turn-context>` row is user-ROLE but
            // not the user's turn, so it is never the thing echoed.
            if ($msg instanceof Message && $msg->role() === 'user' && !TurnContextBlock::isTurnContext($msg)) {
                // The `<ctx-ref>` handle the engine appends to every prompt
                // for the model's pruning tools is not what the user said.
                $lastUser = RefTag::stripFrom($msg->content());
            }
        }

        if (trim($lastUser) === '') {
            return '_(nothing to echo)_';
        }

        // Render every line of the user's turn as a Markdown blockquote.
        return "You said:\n\n" . (string) preg_replace('/^/m', '> ', $lastUser);
    }

    /**
     * @return list<string>
     */
    private function pieces(string $text): array
    {
        $parts = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return [$text];
        }

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
