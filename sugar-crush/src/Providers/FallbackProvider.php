<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;

/**
 * A provider that retries a failed request on the next model of a provider
 * block's `fallbackModels` list (roadmap 5.13b).
 *
 * WHAT TRIGGERS A SWITCH. Only the two failures a different model can fix,
 * judged by the shared 2.7-1a classification and nothing else:
 *
 *  - a TRANSIENT failure ({@see TransientFailure}) — overloaded, rate-limited,
 *    5xx, a dropped connection — that is still failing after the wrapped
 *    provider's own retries are spent;
 *  - a CONTEXT OVERFLOW ({@see ContextOverflow}), but only onto a model whose
 *    window the model database says is LARGER than the one that overflowed.
 *    A model of the same or a smaller known size would only overflow again;
 *    an unknown size is tried, because "unknown" is not "smaller".
 *
 * Every other failure — a 401, a malformed request, a refused tool schema —
 * is the operator's to fix and is rethrown untouched: another model would
 * fail the same way, more slowly and on a different bill.
 *
 * ONLY BEFORE THE FIRST TOKEN. On the streaming path the switch happens only
 * while nothing has been yielded. Once a chunk has reached the caller the
 * reply is that model's, and a failure after it belongs to 2.7's "continue"
 * recovery — a second model restarting the answer would paint it twice.
 *
 * NEVER SILENT (Part II #37). Each switch puts one line in the transcript
 * through {@see RuntimeNoticeSink::warn()}, naming both models and why, and a
 * transient switch PINS the fallback for {@see PIN_SECONDS} — nanobot's
 * cool-down — so the following requests do not each pay the failing model's
 * full retry ladder first. The pin is what {@see servedModel()} reports, so
 * the status bar names the model actually answering; it reaches the parent
 * process the way SGLang's served-model discovery does — the turn child's
 * result frame carries it home and {@see noteServedModel()} adopts it — and
 * it lapses on its own, after which the primary is tried first again.
 *
 * TRANSPARENT OTHERWISE. Name, capabilities, embeddings and the prompt-cache
 * and served-model seams answer for the primary (or, where a model is named,
 * for that model's provider), so wrapping a provider changes nothing until a
 * failure does. Each fallback model is its own provider instance, built
 * lazily from the same block with only `model` replaced, so its context
 * window and prices come from its own row of the model database.
 *
 * No total-request timeout is added here or anywhere on a provider call.
 */
final class FallbackProvider implements ProviderInterface, ReportsServedModel, MarksPromptCache
{
    /** How long a transient switch keeps the fallback first in line. */
    public const PIN_SECONDS = 60.0;

    /** @var array<string, ProviderInterface> */
    private array $built = [];

    private ?string $pinnedModel = null;

    /**
     * The model the pin stands in for, or null when it was adopted from a
     * turn child's frame (which names only the answering model) and so
     * stands in for whatever is requested.
     */
    private ?string $pinnedFor = null;

    private float $pinnedUntil = 0.0;

    /**
     * @param array<string, \Closure(): ProviderInterface> $fallbacks model id => builder, in order
     * @param \Closure(): float $clock
     * @param \Closure(string): void $notice
     */
    private function __construct(
        private readonly ProviderInterface $primary,
        private readonly string $primaryModel,
        private readonly array $fallbacks,
        private readonly \Closure $clock,
        private readonly \Closure $notice,
    ) {
    }

    /**
     * @param string $primaryModel the model the primary provider was built for
     * @param array<string, \Closure(): ProviderInterface> $fallbacks model id => a builder for that
     *        model's provider, in fallback order; the primary's own id is dropped
     * @param (\Closure(): float)|null $clock seconds; defaults to microtime(true)
     * @param (\Closure(string): void)|null $notice defaults to {@see RuntimeNoticeSink::warn()}
     */
    public static function new(
        ProviderInterface $primary,
        string $primaryModel,
        array $fallbacks,
        ?\Closure $clock = null,
        ?\Closure $notice = null,
    ): self {
        unset($fallbacks[$primaryModel]);
        if ($fallbacks === []) {
            throw new \InvalidArgumentException('FallbackProvider needs at least one fallback model other than the primary');
        }

        return new self(
            $primary,
            $primaryModel,
            $fallbacks,
            $clock ?? static fn (): float => microtime(true),
            $notice ?? static function (string $line): void {
                RuntimeNoticeSink::warn($line);
            },
        );
    }

    /** @return list<string> the fallback model ids, in order */
    public function fallbackModels(): array
    {
        return array_keys($this->fallbacks);
    }

    public function primary(): ProviderInterface
    {
        return $this->primary;
    }

    public function name(): string
    {
        return $this->primary->name();
    }

    public function supportsStreaming(): bool
    {
        return $this->primary->supportsStreaming();
    }

    public function supportsFunctionCalling(): bool
    {
        return $this->primary->supportsFunctionCalling();
    }

    public function supportsVision(): bool
    {
        return $this->primary->supportsVision();
    }

    public function supportsJsonSchema(): bool
    {
        return $this->primary->supportsJsonSchema();
    }

    /**
     * The window of the model currently answering — the pinned fallback's
     * while a pin holds, the primary's otherwise — because that is the model
     * every context tier is a percentage of for the next request.
     */
    public function contextWindow(): int
    {
        $pinned = $this->activePin();

        return $pinned === null ? $this->primary->contextWindow() : $this->providerFor($pinned)->contextWindow();
    }

    public function costPer1kTokens(string $model, string $direction): ?float
    {
        return $this->providerFor($model)->costPer1kTokens($model, $direction);
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $candidates = $this->candidates($request->model);
        $failed = null;
        $overflowWindow = null;

        foreach ($candidates as $index => $model) {
            if (!$this->eligibleAfterOverflow($model, $overflowWindow)) {
                continue;
            }

            try {
                $response = $this->providerFor($model)->complete($this->addressedTo($request, $model));
            } catch (\Throwable $failure) {
                $reason = self::reasonFor($failure);
                if (!$this->fallsBack($candidates, $index, $model, $reason, $overflowWindow)) {
                    throw $failure;
                }
                $failed ??= [$model, (string) $reason];

                continue;
            }

            $reason = $response->isError ? self::reasonFor($response) : null;
            if ($this->fallsBack($candidates, $index, $model, $reason, $overflowWindow)) {
                $failed ??= [$model, (string) $reason];

                continue;
            }

            $this->settle($failed, $model, $request->model, !$response->isError);

            return $response;
        }

        // Unreachable: the request's own model is always the first eligible
        // candidate, and a candidate with nowhere left to fall back to
        // returns or rethrows. Kept so the signature holds if that changes.
        throw new \LogicException('FallbackProvider ran out of candidates without a verdict');
    }

    /**
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $candidates = $this->candidates($request->model);
        $failed = null;
        $overflowWindow = null;

        foreach ($candidates as $index => $model) {
            if (!$this->eligibleAfterOverflow($model, $overflowWindow)) {
                continue;
            }
            $started = false;

            try {
                foreach ($this->providerFor($model)->completeStream($this->addressedTo($request, $model)) as $key => $chunk) {
                    if (!$started) {
                        $reason = $chunk->isError ? self::reasonFor($chunk) : null;
                        if ($this->fallsBack($candidates, $index, $model, $reason, $overflowWindow)) {
                            $failed ??= [$model, (string) $reason];

                            continue 2;
                        }
                        $started = true;
                        $this->settle($failed, $model, $request->model, !$chunk->isError);
                    }

                    yield $key => $chunk;
                }
            } catch (\Throwable $failure) {
                if ($started) {
                    throw $failure;
                }
                $reason = self::reasonFor($failure);
                if (!$this->fallsBack($candidates, $index, $model, $reason, $overflowWindow)) {
                    throw $failure;
                }
                $failed ??= [$model, (string) $reason];

                continue;
            }

            if (!$started) {
                // An empty stream is still that model's answer.
                $this->settle($failed, $model, $request->model, true);
            }

            return;
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return $this->primary->embeddings($request);
    }

    public function marksPromptCache(string $model): bool
    {
        $provider = $this->providerFor($model);

        return $provider instanceof MarksPromptCache && $provider->marksPromptCache($model);
    }

    /**
     * The pinned fallback while its pin holds, else whatever the primary
     * reports — so a wrapped SGLang provider keeps its own discovery.
     */
    public function servedModel(): ?string
    {
        return $this->activePin()
            ?? ($this->primary instanceof ReportsServedModel ? $this->primary->servedModel() : null);
    }

    /**
     * A fallback model's id is this wrapper's own pin, carried back from the
     * turn child that switched; any other name is the primary's discovery.
     */
    public function noteServedModel(string $servedModel): void
    {
        if (isset($this->fallbacks[$servedModel])) {
            $this->pin($servedModel);

            return;
        }

        if ($this->primary instanceof ReportsServedModel) {
            $this->primary->noteServedModel($servedModel);
        }
    }

    /**
     * Why $failure warrants trying another model — `transient` or `overflow`
     * — or null when it does not.
     */
    public static function reasonFor(\Throwable|CompleteResponse $failure): ?string
    {
        if ($failure instanceof CompleteResponse) {
            if (TransientFailure::responseIsTransient($failure)) {
                return 'transient';
            }

            return ContextOverflow::matches($failure) ? 'overflow' : null;
        }

        if (TransientFailure::isTransient($failure)) {
            return 'transient';
        }

        return ContextOverflow::matches($failure) ? 'overflow' : null;
    }

    /**
     * The order this request tries models in: the pinned fallback first while
     * its pin holds, then the request's own model, then the list.
     *
     * @return list<string>
     */
    private function candidates(string $requested): array
    {
        $requested = $requested !== '' ? $requested : $this->primaryModel;
        $order = [$requested, ...array_keys($this->fallbacks)];
        $pinned = $this->activePin();
        // A pin stands in for the model that was failing; after a `/model`
        // switch to another one, the operator's choice goes first.
        if ($pinned !== null && ($this->pinnedFor === null || $this->pinnedFor === $requested)) {
            array_unshift($order, $pinned);
        }

        return array_values(array_unique($order));
    }

    private function providerFor(string $model): ProviderInterface
    {
        if (!isset($this->fallbacks[$model])) {
            return $this->primary;
        }

        return $this->built[$model] ??= ($this->fallbacks[$model])();
    }

    private function addressedTo(CompleteRequest $request, string $model): CompleteRequest
    {
        return $request->model === $model
            ? $request
            : new CompleteRequest(...array_replace(get_object_vars($request), ['model' => $model]));
    }

    /**
     * After an overflow, only a model with a larger KNOWN window — or an
     * unknown one — is worth the request.
     */
    private function eligibleAfterOverflow(string $model, ?int $overflowWindow): bool
    {
        if ($overflowWindow === null || $overflowWindow <= 0) {
            return true;
        }
        $window = $this->providerFor($model)->contextWindow();

        return $window <= 0 || $window > $overflowWindow;
    }

    /** @param list<string> $candidates */
    private function hasEligibleAfter(array $candidates, int $index, ?int $overflowWindow): bool
    {
        foreach (array_slice($candidates, $index + 1) as $next) {
            if ($this->eligibleAfterOverflow($next, $overflowWindow)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the failure of $model (classified $reason) moves this request
     * on to a later candidate. Raises $overflowWindow, the bar every later
     * candidate must clear, when the failure was an overflow — null while
     * nothing has overflowed, else the largest window that has (0 when none
     * of those windows is known).
     *
     * @param list<string> $candidates
     */
    private function fallsBack(array $candidates, int $index, string $model, ?string $reason, ?int &$overflowWindow): bool
    {
        if ($reason === null) {
            return false;
        }

        $bar = $reason === 'overflow'
            ? max($overflowWindow ?? 0, $this->providerFor($model)->contextWindow())
            : $overflowWindow;
        if (!$this->hasEligibleAfter($candidates, $index, $bar)) {
            return false;
        }
        $overflowWindow = $bar;

        return true;
    }

    /**
     * Announce a switch and move the pin. $failed is the first model that
     * failed this request and why; $answered says the model that took it
     * returned a reply rather than an error of its own.
     *
     * @param array{0: string, 1: string}|null $failed
     */
    private function settle(?array $failed, string $answering, string $requested, bool $answered): void
    {
        if ($failed === null) {
            return;
        }
        [$failedModel, $reason] = $failed;
        $transient = $reason === 'transient';
        $pins = $transient && $answered && isset($this->fallbacks[$answering]);

        ($this->notice)(sprintf(
            'Model fallback: %s %s, so this request went to %s%s.',
            $failedModel,
            $transient ? 'kept failing (overloaded, rate-limited or unreachable)' : 'could not fit the context',
            $answering,
            $pins ? sprintf(' — it stays first for %d s before %s is tried again', (int) self::PIN_SECONDS, $requested) : '',
        ));

        // A transient outage outlives one request, so the model that answered
        // goes first for a while; an overflow is this request's size, and the
        // next one (after compaction) may fit. A pinned fallback that itself
        // failed over to the primary gives the pin up.
        if ($pins) {
            $this->pin($answering, $requested !== '' ? $requested : $this->primaryModel);
        } elseif ($transient && $answered) {
            $this->pinnedModel = null;
        }
    }

    private function pin(string $model, ?string $for = null): void
    {
        $this->pinnedModel = $model;
        $this->pinnedFor = $for;
        $this->pinnedUntil = ($this->clock)() + self::PIN_SECONDS;
    }

    private function activePin(): ?string
    {
        if ($this->pinnedModel !== null && ($this->clock)() < $this->pinnedUntil) {
            return $this->pinnedModel;
        }

        return null;
    }
}
