<?php

declare(strict_types=1);

namespace SugarCraft\Bounce;

/**
 * Sequences multiple springs so that one spring's settle triggers the next.
 *
 * Each spring in the chain advances only when the previous spring has reached
 * its target (settled). Useful for staggered animations where each stage must
 * complete before the next begins.
 */
final class SpringChain
{
    /**
     * Threshold below which a chain stage is considered settled.
     *
     * Deliberately looser than a lone spring's {@see Spring::SETTLING_THRESHOLD}
     * (1e-4): staggered stages hand off as soon as the previous stage is
     * visually at rest, so the wider 5e-4 band avoids a perceptible pause
     * between stages.
     */
    private const SETTLING_THRESHOLD = 0.0005;

    /** @var list<array{0: Spring, 1:float, 2:float, 3:float}> */
    private array $stages;

    private int $activeIndex = 0;

    /**
     * @param list<array{0: Spring, 1:float, 2:float, 3:float}> $stages
     * @param int $activeIndex Internal index for the active stage (used by tick())
     */
    public function __construct(array $stages, int $activeIndex = 0)
    {
        $this->stages = $stages;
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
     * Add a stage to the chain (fluent).
     *
     * @param Spring $spring      The spring for this stage
     * @param float  $position     Initial position
     * @param float  $velocity     Initial velocity
     * @param float  $target       Target position
     */
    public function withStage(Spring $spring, float $position, float $velocity, float $target): self
    {
        $stages = $this->stages;
        $stages[] = [$spring, $position, $velocity, $target];
        return new self($stages);
    }

    /**
     * Advance the chain by one step.
     *
     * Only the active stage advances. Once it reaches its target, the next
     * stage becomes active. Returns current positions of all settled stages
     * plus the active stage.
     *
     * @return array{0: list<float>, 1: bool, 2: self}  [positions, chainComplete, newChain]
     */
    public function tick(): array
    {
        if ($this->activeIndex >= count($this->stages)) {
            return [$this->currentPositions(), true, new self($this->stages, $this->activeIndex)];
        }

        [$spring, $pos, $vel, $target] = $this->stages[$this->activeIndex];

        if ($this->isSettled($pos, $vel, $target)) {
            $newActiveIndex = $this->activeIndex + 1;
            return [$this->currentPositions(), $newActiveIndex >= count($this->stages), new self($this->stages, $newActiveIndex)];
        }

        [$newPos, $newVel] = $spring->update($pos, $vel, $target);

        // Copy-on-write: clone the stages list once and rewrite only the active
        // stage instead of rebuilding the whole array element by element.
        $newStages = $this->stages;
        $newStages[$this->activeIndex] = [$spring, $newPos, $newVel, $target];

        return [$this->currentPositions(), false, new self($newStages, $this->activeIndex)];
    }

    /**
     * Get all current positions of all stages.
     *
     * @return list<float>
     */
    public function currentPositions(): array
    {
        $positions = [];
        foreach ($this->stages as [$spring, $pos, $vel, $target]) {
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
