<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

use SugarCraft\Crush\Context\ContextPressure;

/**
 * "Step N of the agentic loop is about to call the provider" — handed to the
 * `$onStep` observer of {@see \SugarCraft\Crush\Backend\EngineBackend}'s turn
 * loop once per step, after the step's request is built and before it is
 * sent, and carried across the fork as the `step` frame (roadmap 1.C-4,
 * Appendix O §5.1).
 *
 * It exists for two readers. Progress: a turn is up to `maxSteps` provider
 * calls and the only other sign of one was a tool call, so a turn that thinks
 * between calls showed nothing. Context: {@see $pressure} is the request's
 * size as roadmap 2.1 measures it — anchored on the provider's own count for
 * the previous step, system prompt and tool schemas included — which is the
 * first figure that can say a turn is filling the window WHILE it runs; Chat's
 * own tiers judge only at submit.
 *
 * Observation only, like every event on that channel: a consumer that ignores
 * it costs the turn nothing.
 */
final readonly class StepStarted
{
    /**
     * @param int              $step     1-based number of the step starting
     * @param int              $maxSteps the turn's step ceiling
     * @param ?ContextPressure $pressure the step's request measured against the
     *                                   step budget; null when not measured
     */
    public function __construct(
        public int $step,
        public int $maxSteps,
        public ?ContextPressure $pressure = null,
    ) {
    }

    /** @return array{step:int,maxSteps:int,context:?array<string,int|null>} the `step` frame's fields */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'maxSteps' => $this->maxSteps,
            'context' => $this->pressure?->toArray(),
        ];
    }

    /** Rebuild from {@see toArray()}; null for a shape it did not write. */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw) || !is_int($raw['step'] ?? null) || !is_int($raw['maxSteps'] ?? null)) {
            return null;
        }

        return new self($raw['step'], $raw['maxSteps'], ContextPressure::fromArray($raw['context'] ?? null));
    }
}
