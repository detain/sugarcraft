<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Messages\Message;

/**
 * Offline provider that echoes the last user turn back as a Markdown
 * blockquote. Lets the binary run with zero network dependencies — the
 * default when no real provider is configured, and the backbone of the
 * test/demo experience.
 *
 * Mirrors the original sugar-crush EchoBackend, re-expressed against the
 * ProviderInterface so it composes with the Runtime/Agent engine.
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
        return new CompleteResponse(content: $this->echo($request->messages));
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        // Emit the reply in whitespace-delimited pieces so the UI exercises
        // incremental rendering even with no network in the loop.
        foreach ($this->pieces($this->echo($request->messages)) as $piece) {
            yield new CompleteResponse(content: $piece);
        }
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
            if ($msg instanceof Message && $msg->role() === 'user') {
                $lastUser = $msg->content();
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
