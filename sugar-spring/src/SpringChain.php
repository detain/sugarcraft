<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

/**
 * Sequences multiple springs so that one spring's settle triggers the next.
 *
 * Each spring in the chain advances only when the previous spring has reached
 * its target (settled). Useful for staggered animations where each stage must
 * complete before the next begins.
 *
 * Reduced motion (environment or per-spring override) is honored through the
 * stage springs themselves: under REDUCE_MOTION every stage snaps in one tick,
 * but the hand-off still costs its own tick — with n unsettled stages the
 * final positions are in place after 2n−1 ticks and a complete chain arrives
 * on tick 2n.
 *
 * ```php
 * $chain = SpringChain::new([
 *     [new Spring(Spring::fps(60), 6.0, 1.0), 0.0, 0.0, 100.0],
 *     [new Spring(Spring::fps(60), 6.0, 1.0), 0.0, 0.0, 50.0],
 * ]);
 * while (!$chain->isComplete()) {
 *     $chain = $chain->tick();          // MUST reassign: tick() returns a NEW chain
 *     draw($chain->currentPositions()); // positions are FRESH for this frame
 * }
 * ```
 */
final class SpringChain
{
    /**
     * Threshold below which a chain stage is considered settled.
     *
     * Deliberately looser than a lone spring's {@see Spring::SETTLING_THRESHOLD}
     * (1e-4): staggered stages hand off as soon as the previous stage is
     * visually at rest, so the wider 5e-4 band avoids a perceptible pause
     * between stages. The trade-off: a handed-off stage keeps its last
     * pre-handoff position, which can sit up to 5e-4 away from its target
     * (currentPositions() after completion is "visibly done", not exact).
     */
    public const SETTLING_THRESHOLD = 0.0005;

    /** @var list<array{0: Spring, 1: float, 2: float, 3: float}> */
    private array $stages;

    private int $activeIndex = 0;

    /**
     * @param list<array{0: Spring, 1: float, 2: float, 3: float}> $stages
     * @param int $activeIndex Internal index for the active stage (used by tick())
     *
     * @throws \InvalidArgumentException on a malformed stage tuple or an
     *         activeIndex outside 0..count($stages) — parsed once here so
     *         tick() can trust the list.
     */
    public function __construct(array $stages, int $activeIndex = 0)
    {
        $parsed = [];
        foreach ($stages as $index => $stage) {
            if (
                !is_array($stage)
                || count($stage) !== 4
                || !array_key_exists(0, $stage)
                || !array_key_exists(1, $stage)
                || !array_key_exists(2, $stage)
                || !array_key_exists(3, $stage)
                || !$stage[0] instanceof Spring
                || !is_numeric($stage[1])
                || !is_numeric($stage[2])
                || !is_numeric($stage[3])
            ) {
                throw new \InvalidArgumentException(Lang::t('spring.chain_bad_stage', ['index' => (string) $index]));
            }
            $parsed[] = [$stage[0], (float) $stage[1], (float) $stage[2], (float) $stage[3]];
        }

        if ($activeIndex < 0 || $activeIndex > count($parsed)) {
            throw new \InvalidArgumentException(Lang::t('spring.chain_active_index', [
                'activeIndex' => $activeIndex,
                'count' => count($parsed),
            ]));
        }

        $this->stages = $parsed;
        $this->activeIndex = $activeIndex;
    }

    /**
     * Build a chain from a spring and initial conditions for each stage.
     *
     * @param list<array{0: Spring, 1:float, 2:float, 3:float}> $stages
     */
    public static function new(array $stages): self
    {
        return new self($stages);
    }

    /**
     * Add a stage to the end of the chain (fluent).
     *
     * @param Spring $spring      The spring for this stage
     * @param float  $position     Initial position
     * @param float  $velocity     Initial velocity
     * @param float  $target       Target position
     *
     * @return self A NEW chain holding the extended stage list.
     *
     * ⚠ Building-time only: the returned chain restarts at stage 0 (the new
     * instance carries no activeIndex). Appending a stage to an ALREADY-TICKED
     * chain rewinds the run — build the full stage list before the first
     * tick(), and use {@see SpringCollection} for springs that join or retarget
     * while an animation is in flight.
     */
    public function withStage(Spring $spring, float $position, float $velocity, float $target): self
    {
        $stages = $this->stages;
        $stages[] = [$spring, $position, $velocity, $target];
        return new self($stages);
    }

    /**
     * Advance the chain by one step, returning the NEW chain.
     *
     * Only the active stage advances; once it settles, the next becomes
     * active. Read the advanced frame with {@see currentPositions()} (always
     * the CURRENT state of this instance — never a pre-update snapshot) and
     * the terminal condition with {@see isComplete()}. Ticking a completed
     * chain returns the same completed state.
     *
     * MUST reassign: `$chain = $chain->tick();`. The immutability is by
     * contract — forgetting the reassign replays the same frame forever.
     */
    public function tick(): self
    {
        if ($this->activeIndex >= count($this->stages)) {
            return $this;
        }

        [$spring, $pos, $vel, $target] = $this->stages[$this->activeIndex];

        if ($this->isSettled($pos, $vel, $target)) {
            return self::fromParsed($this->stages, $this->activeIndex + 1);
        }

        [$newPos, $newVel] = $spring->update($pos, $vel, $target);

        // Copy-on-write: clone the stages list once and rewrite only the active
        // stage instead of rebuilding the whole array element by element.
        $newStages = $this->stages;
        $newStages[$this->activeIndex] = [$spring, $newPos, $newVel, $target];

        return self::fromParsed($newStages, $this->activeIndex);
    }

    /**
     * Rebuild from an ALREADY-PARSED stage list, skipping the constructor's
     * per-frame re-validation: every list that reaches here was born either
     * at the construction door or inside tick() itself, which only ever
     * writes floats back into slots the door already checked. The hot path
     * pays a list copy, not an arity/instanceof/coercion sweep per stage.
     *
     * @param list<array{0: Spring, 1: float, 2: float, 3: float}> $stages
     */
    private static function fromParsed(array $stages, int $activeIndex): self
    {
        $chain = new self([]);
        $chain->stages = $stages;
        $chain->activeIndex = $activeIndex;

        return $chain;
    }

    /**
     * Get all current positions of all stages.
     *
     * @return list<float>
     */
    public function currentPositions(): array
    {
        $positions = [];
        foreach ($this->stages as [, $pos]) {
            $positions[] = $pos;
        }
        return $positions;
    }

    /**
     * Check if the chain has completed all stages.
     */
    public function isComplete(): bool
    {
        return $this->activeIndex >= count($this->stages);
    }

    /**
     * Get the index of the currently active stage.
     */
    public function activeStage(): int
    {
        return $this->activeIndex;
    }

    private function isSettled(float $pos, float $vel, float $target): bool
    {
        return Spring::settled($pos, $vel, $target, self::SETTLING_THRESHOLD);
    }
}
