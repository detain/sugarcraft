<?php

declare(strict_types=1);

namespace SugarCraft\Bounce;

/**
 * Manages multiple named spring instances.
 *
 * Provides a collection interface for tracking and updating multiple
 * springs simultaneously.
 */
final class SpringCollection
{
    /** @var array<string, Spring> */
    private array $springs = [];

    /** @var array<string, float> */
    private array $positions = [];

    /** @var array<string, float> */
    private array $velocities = [];

    /** @var array<string, float> */
    private array $targets = [];

    /**
     * Add a spring to the collection.
     */
    public function add(string $id, Spring $spring, float $position = 0.0, float $velocity = 0.0, float $target = 0.0): void
    {
        $this->springs[$id] = $spring;
        $this->positions[$id] = $position;
        $this->velocities[$id] = $velocity;
        $this->targets[$id] = $target;
    }

    /**
     * Remove a spring from the collection by id.
     */
    public function remove(string $id): void
    {
        unset(
            $this->springs[$id],
            $this->positions[$id],
            $this->velocities[$id],
            $this->targets[$id]
        );
    }

    /**
     * Advance all springs by one step.
     *
     * Returns a NEW SpringCollection with updated positions/velocities,
     * leaving the original instance unchanged (immutable pattern).
     *
     * @return self New SpringCollection with updated state
     */
    public function tick(): self
    {
        $newPositions = [];
        $newVelocities = [];

        foreach ($this->springs as $id => $spring) {
            [$pos, $vel] = $spring->update(
                $this->positions[$id],
                $this->velocities[$id],
                $this->targets[$id]
            );
            $newPositions[$id] = $pos;
            $newVelocities[$id] = $vel;
        }

        return $this->withState($newPositions, $newVelocities);
    }

    /**
     * @param array<string, Spring> $springs
     * @param array<string, float> $positions
     * @param array<string, float> $velocities
     * @param array<string, float> $targets
     */
    public function __construct(
        array $springs = [],
        array $positions = [],
        array $velocities = [],
        array $targets = []
    ) {
        $this->springs = $springs;
        $this->positions = $positions;
        $this->velocities = $velocities;
        $this->targets = $targets;
    }

    /**
     * Create a new collection with updated state (used by tick()).
     *
     * @param array<string, float> $positions
     * @param array<string, float> $velocities
     */
    private function withState(array $positions, array $velocities): self
    {
        return new self($this->springs, $positions, $velocities, $this->targets);
    }

    /**
     * Get the current position of a spring by id.
     */
    public function get(string $id): float
    {
        return $this->positions[$id];
    }

    /**
     * Check if a spring exists in the collection.
     */
    public function has(string $id): bool
    {
        return isset($this->springs[$id]);
    }

    /**
     * Get all current positions.
     *
     * @return array<string, float>
     */
    public function all(): array
    {
        return $this->positions;
    }

    /**
     * Set the target for a specific spring.
     */
    public function setTarget(string $id, float $target): void
    {
        $this->targets[$id] = $target;
    }

    /**
     * Get the target for a specific spring.
     */
    public function getTarget(string $id): float
    {
        return $this->targets[$id];
    }
}
