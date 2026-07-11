<?php

declare(strict_types=1);

namespace SugarCraft\Bounce;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Manages multiple named spring instances.
 *
 * Immutable: {@see withSpring()}, {@see without()} and {@see withTarget()}
 * each return a NEW collection, matching {@see tick()}'s copy-on-write
 * contract. The legacy in-place mutators — add()/remove()/setTarget() — are
 * retained as deprecated shims for one release so existing callers keep
 * working while migrating to the with*() API.
 */
final class SpringCollection
{
    use Mutable;

    /** @var array<string, Spring> */
    private array $springs;

    /** @var array<string, float> */
    private array $positions;

    /** @var array<string, float> */
    private array $velocities;

    /** @var array<string, float> */
    private array $targets;

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
     * Return a NEW collection with the given spring added (immutable).
     */
    public function withSpring(string $id, Spring $spring, float $position = 0.0, float $velocity = 0.0, float $target = 0.0): self
    {
        $springs = $this->springs;
        $positions = $this->positions;
        $velocities = $this->velocities;
        $targets = $this->targets;

        $springs[$id] = $spring;
        $positions[$id] = $position;
        $velocities[$id] = $velocity;
        $targets[$id] = $target;

        return $this->mutate([
            'springs' => $springs,
            'positions' => $positions,
            'velocities' => $velocities,
            'targets' => $targets,
        ]);
    }

    /**
     * Return a NEW collection with the named spring removed (immutable).
     */
    public function without(string $id): self
    {
        $springs = $this->springs;
        $positions = $this->positions;
        $velocities = $this->velocities;
        $targets = $this->targets;

        unset($springs[$id], $positions[$id], $velocities[$id], $targets[$id]);

        return $this->mutate([
            'springs' => $springs,
            'positions' => $positions,
            'velocities' => $velocities,
            'targets' => $targets,
        ]);
    }

    /**
     * Return a NEW collection with the target for one spring changed (immutable).
     */
    public function withTarget(string $id, float $target): self
    {
        $targets = $this->targets;
        $targets[$id] = $target;

        return $this->mutate(['targets' => $targets]);
    }

    /**
     * Add a spring to the collection (mutates in place).
     *
     * @deprecated Use {@see withSpring()}, which returns a new instance.
     *             Retained one release for backward compatibility.
     */
    public function add(string $id, Spring $spring, float $position = 0.0, float $velocity = 0.0, float $target = 0.0): void
    {
        @trigger_error(
            'SpringCollection::add() is deprecated; use withSpring() which returns a new collection.',
            E_USER_DEPRECATED,
        );

        $this->springs[$id] = $spring;
        $this->positions[$id] = $position;
        $this->velocities[$id] = $velocity;
        $this->targets[$id] = $target;
    }

    /**
     * Remove a spring from the collection by id (mutates in place).
     *
     * @deprecated Use {@see without()}, which returns a new instance.
     *             Retained one release for backward compatibility.
     */
    public function remove(string $id): void
    {
        @trigger_error(
            'SpringCollection::remove() is deprecated; use without() which returns a new collection.',
            E_USER_DEPRECATED,
        );

        unset(
            $this->springs[$id],
            $this->positions[$id],
            $this->velocities[$id],
            $this->targets[$id]
        );
    }

    /**
     * Set the target for a specific spring (mutates in place).
     *
     * @deprecated Use {@see withTarget()}, which returns a new instance.
     *             Retained one release for backward compatibility.
     */
    public function setTarget(string $id, float $target): void
    {
        @trigger_error(
            'SpringCollection::setTarget() is deprecated; use withTarget() which returns a new collection.',
            E_USER_DEPRECATED,
        );

        $this->targets[$id] = $target;
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

        return $this->mutate([
            'positions' => $newPositions,
            'velocities' => $newVelocities,
        ]);
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
     * Get the target for a specific spring.
     */
    public function getTarget(string $id): float
    {
        return $this->targets[$id];
    }
}
