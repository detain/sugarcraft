<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Manages multiple named spring instances.
 *
 * Immutable: {@see withSpring()}, {@see without()} and {@see withTarget()}
 * each return a NEW collection, matching {@see tick()}'s copy-on-write
 * contract. The legacy mutator spellings — add()/remove()/setTarget() — are
 * deprecated shims (REMOVED-IN: 2.0.0) that now delegate to their with-*()
 * twins: copy-on-write like everything else, notice-emitting like
 * {@see getTarget()}.
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
     *
     * @throws \InvalidArgumentException when a plane holds a wrong-typed or
     *         non-finite value, or the four arrays are misaligned (an id with
     *         no spring, or a spring with no state). The four-array shape is
     *         easy to pass unaligned; the failure names the offending id and
     *         plane instead of surfacing as an internals-flavoured TypeError
     *         inside tick() frames later.
     */
    public function __construct(
        array $springs = [],
        array $positions = [],
        array $velocities = [],
        array $targets = []
    ) {
        foreach ($springs as $id => $spring) {
            if (!$spring instanceof Spring) {
                throw new \InvalidArgumentException(Lang::t('spring.collection_bad_spring', ['id' => (string) $id]));
            }
        }

        $planes = ['positions' => $positions, 'velocities' => $velocities, 'targets' => $targets];
        foreach ($planes as $plane => $values) {
            foreach ($values as $id => $value) {
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    throw new \InvalidArgumentException(Lang::t('spring.collection_bad_value', [
                        'plane' => $plane,
                        'id' => (string) $id,
                    ]));
                }
            }
        }

        foreach (array_keys($springs) as $id) {
            foreach ($planes as $plane => $values) {
                if (!array_key_exists($id, $values)) {
                    throw new \InvalidArgumentException(Lang::t('spring.collection_misaligned', [
                        'id' => (string) $id,
                        'plane' => $plane,
                    ]));
                }
            }
        }
        foreach ($planes as $plane => $values) {
            foreach (array_keys($values) as $id) {
                if (!array_key_exists($id, $springs)) {
                    throw new \InvalidArgumentException(Lang::t('spring.collection_orphan', [
                        'id' => (string) $id,
                        'plane' => $plane,
                    ]));
                }
            }
        }

        $floatPlane = static fn (array $values): array => array_map('floatval', $values);
        $this->springs = $springs;
        $this->positions = $floatPlane($positions);
        $this->velocities = $floatPlane($velocities);
        $this->targets = $floatPlane($targets);
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
     *
     * @throws \OutOfBoundsException for an unknown id — the old unguarded
     *         write created a targetless orphan that survived without() and
     *         then crashed tick() on its missing spring.
     */
    public function withTarget(string $id, float $target): self
    {
        $this->requireSpring($id);

        $targets = $this->targets;
        $targets[$id] = $target;

        return $this->mutate(['targets' => $targets]);
    }

    /**
     * Add a spring to the collection.
     *
     * @deprecated Use {@see withSpring()}, which returns a new instance.
     *             REMOVED-IN: 2.0.0. Since this release the shim is also
     *             copy-on-write: it returns the NEW collection and no longer
     *             mutates the receiver (finding 019 — the old mixed contract
     *             made `$c = $c->add(...)` assign null while a plain
     *             `$c->add(...)` mutated; one footgun at a time).
     */
    public function add(string $id, Spring $spring, float $position = 0.0, float $velocity = 0.0, float $target = 0.0): self
    {
        $this->emitShimDeprecation('add()', 'withSpring()');

        return $this->withSpring($id, $spring, $position, $velocity, $target);
    }

    /**
     * Remove a spring from the collection by id.
     *
     * @deprecated Use {@see without()}, which returns a new instance.
     *             REMOVED-IN: 2.0.0. Copy-on-write like {@see add()}.
     */
    public function remove(string $id): self
    {
        $this->emitShimDeprecation('remove()', 'without()');

        return $this->without($id);
    }

    /**
     * Set the target for a specific spring.
     *
     * @deprecated Use {@see withTarget()}, which returns a new instance.
     *             REMOVED-IN: 2.0.0. Copy-on-write like {@see add()};
     *             unknown ids still throw OutOfBoundsException.
     */
    public function setTarget(string $id, float $target): self
    {
        $this->emitShimDeprecation('setTarget()', 'withTarget()');

        return $this->withTarget($id, $target);
    }

    /**
     * The ONE deprecation-notice shape for every deprecated surface here.
     *
     * These trigger_error strings are deliberately NOT routed through
     * Lang::t (finding 055): the i18n law's scope is USER-facing runtime
     * text; a deprecation notice is a developer-facing compiler-era message
     * read in stack traces — translating it would scatter migration
     * instructions across 16 files while making them harder to grep.
     */
    private function emitShimDeprecation(string $legacy, string $replacement): void
    {
        trigger_error(
            sprintf(
                'SpringCollection::%s is deprecated; use %s instead. REMOVED-IN: 2.0.0.',
                $legacy,
                $replacement,
            ),
            E_USER_DEPRECATED,
        );
    }

    /**
     * Advance every UNSETTLED spring by one step; settled members keep their
     * floats untouched (finding 006 — cost follows motion, not collection
     * size). Returns a NEW SpringCollection when anything advanced, leaving
     * the original instance unchanged; a fully-settled collection returns
     * `$this` identity.
     *
     * The rebuild bypasses the validating constructor via {@see fromParsed()}
     * (finding 004): this hot path only ever sees (a) plane values that
     * already passed constructor validation, and (b) Spring::update()
     * outputs — whose non-finite INPUT door plus finite coefficient belt
     * make re-checking them redundant. No foreign input can ride through
     * unexamined: every value reaches the planes via the constructor or the
     * with-prefix mutators (full validation) — and the deprecated shims
     * delegate to those, so the door covers them too.
     *
     * @return self New collection when motion happened; the same collection
     *              once nothing can move.
     */
    public function tick(): self
    {
        $movedPositions = null;
        $movedVelocities = null;

        foreach ($this->springs as $id => $spring) {
            $pos = $this->positions[$id];
            $vel = $this->velocities[$id];
            $target = $this->targets[$id];

            if (Spring::settled($pos, $vel, $target)) {
                continue; // settled is the documented stop predicate — the
                          // same test the driving loop makes, applied per member
            }

            $state = $spring->update($pos, $vel, $target);

            if ($movedPositions === null) {
                $movedPositions = $this->positions;
                $movedVelocities = $this->velocities;
            }
            $movedPositions[$id] = $state['position'];
            $movedVelocities[$id] = $state['velocity'];
        }

        if ($movedPositions === null) {
            return $this;
        }

        return self::fromParsed($this->springs, $movedPositions, $movedVelocities, $this->targets);
    }

    /**
     * Pre-validated rebuild for {@see tick()} — fields land raw, with no
     * second pass of constructor checks, BECAUSE the only caller feeds
     * already-validated planes and finite-by-construction update() outputs.
     * Cold paths (constructor, with*()) keep the full door; nothing outside
     * this class can reach this one.
     *
     * @param array<string, Spring> $springs
     * @param array<string, float> $positions
     * @param array<string, float> $velocities
     * @param array<string, float> $targets
     */
    private static function fromParsed(
        array $springs,
        array $positions,
        array $velocities,
        array $targets,
    ): self {
        $collection = new self();
        $collection->springs = $springs;
        $collection->positions = $positions;
        $collection->velocities = $velocities;
        $collection->targets = $targets;

        return $collection;
    }

    /**
     * The current position of a spring by id — the house bare accessor.
     *
     * @throws \OutOfBoundsException naming the unknown id (the raw array
     *         read used to surface a warning-only null then a TypeError in
     *         the renderer three lines later).
     */
    public function position(string $id): float
    {
        if (!array_key_exists($id, $this->positions)) {
            throw new \OutOfBoundsException(Lang::t('spring.unknown_id', ['id' => $id]));
        }
        return $this->positions[$id];
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *             convention (finding 020 — the last surviving `get`
     *             accessor here). Use {@see self::position()} instead.
     *             REMOVED-IN: 2.0.0, loud like the other shims.
     */
    public function get(string $id): float
    {
        $this->emitShimDeprecation('get()', 'position()');

        return $this->position($id);
    }

    /**
     * Get the current velocity of a spring by id.
     *
     * Positions alone cannot answer "has it stopped?" — an under-damped
     * spring coasts through its target with full speed. Completion checks
     * need both, hence this twin of {@see position()} (and {@see settled()},
     * which folds the pair).
     *
     * @throws \OutOfBoundsException naming the unknown id.
     */
    public function velocity(string $id): float
    {
        if (!array_key_exists($id, $this->velocities)) {
            throw new \OutOfBoundsException(Lang::t('spring.unknown_id', ['id' => $id]));
        }
        return $this->velocities[$id];
    }

    /**
     * Get all current velocities, keyed by spring id.
     *
     * @return array<string, float>
     */
    public function allVelocities(): array
    {
        return $this->velocities;
    }

    /**
     * Whether EVERY spring in the collection has settled at its target.
     *
     * The stop predicate the house tick-loop needs — comparing rounded
     * positions alone repaints a 60 Hz loop long after an under-damped
     * spring has passed through its target. Feed this to your
     * `while (!$collection->settled())` loop instead (see the README
     * completion contract section for the per-frame output-coalescing
     * companion rule).
     */
    public function settled(float $threshold = Spring::SETTLING_THRESHOLD): bool
    {
        foreach ($this->springs as $id => $spring) {
            if (!Spring::settled($this->positions[$id], $this->velocities[$id], $this->targets[$id], $threshold)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if a spring exists in the collection.
     */
    public function has(string $id): bool
    {
        return isset($this->springs[$id]);
    }

    private function requireSpring(string $id): void
    {
        if (!isset($this->springs[$id])) {
            throw new \OutOfBoundsException(Lang::t('spring.unknown_id', ['id' => $id]));
        }
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
     *
     * @throws \OutOfBoundsException naming the unknown id.
     */
    public function target(string $id): float
    {
        if (!array_key_exists($id, $this->targets)) {
            throw new \OutOfBoundsException(Lang::t('spring.unknown_id', ['id' => $id]));
        }
        return $this->targets[$id];
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *             convention. Use {@see self::target()} instead.
     *             REMOVED-IN: 2.0.0. Emits E_USER_DEPRECATED like the mutator
     *             shims (finding 051) so the read-side migration can no
     *             longer linger silently.
     */
    public function getTarget(string $id): float
    {
        $this->emitShimDeprecation('getTarget()', 'target()');

        return $this->target($id);
    }
}
