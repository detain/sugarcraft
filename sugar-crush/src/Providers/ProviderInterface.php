<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

interface ProviderInterface
{
    public function name(): string;

    public function supportsStreaming(): bool;

    public function supportsFunctionCalling(): bool;

    public function supportsVision(): bool;

    public function supportsJsonSchema(): bool;

    /**
     * The model's context window in PROVIDER-COUNTED tokens, or 0 when this
     * provider has no way to know one.
     *
     * 0 means UNKNOWN and must never be read as "no limit": it is the signal
     * {@see \SugarCraft\Crush\Context\ContextWindow::resolve()} turns into
     * the one named fallback. A provider that guesses a large number instead
     * silently becomes the denominator of every context tier in
     * {@see \SugarCraft\Crush\Chat} — the reminder, the automatic
     * compaction and the blocking refusal are all percentages of this — and a
     * guess that is too large switches all three off rather than erring
     * toward compacting early. {@see EchoProvider} is the in-repo case.
     */
    public function contextWindow(): int;

    /**
     * @param 'input'|'output' $direction
     */
    public function costPer1kTokens(string $model, string $direction): float;

    public function complete(CompleteRequest $request): CompleteResponse;

    /**
     * Stream the completion as a generator of per-chunk deltas.
     *
     * STREAMED USAGE IS PER-DELTA, NOT CUMULATIVE. Each yielded
     * CompleteResponse's `tokensUsed`/`costUsd` is that chunk's own increment;
     * consumers sum the values across the whole stream to obtain the turn's
     * total ({@see \SugarCraft\Crush\Runtime::runStreaming()} then
     * {@see \SugarCraft\Crush\Usage::sum()}). Implementers whose wire protocol
     * reports cumulative totals must emit each total exactly once — on the
     * terminal chunk, or split across disjoint bucket events as
     * {@see \SugarCraft\Crush\Providers\VertexProvider} does — never repeated on
     * every chunk, because a repeated cumulative total is summed N times.
     * All-zero chunks are compliant when the wire carried no usage: they sum to
     * the true "nothing reported" answer.
     *
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator;

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse;
}
