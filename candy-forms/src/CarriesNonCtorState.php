<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

/**
 * Carrier mixin for {@see Field} implementations that rebuild themselves
 * through `new self(...)`: copies the state that lives OUTSIDE the private
 * constructor — the {@see HasHideFunc} / {@see HasDynamicLabels} closures —
 * onto the freshly constructed instance.
 *
 * Mirrors E736-F1 Confirm fix (E741, round 85): Confirm proved the recipe
 * (its private `carryNonCtorState()`), while every other trait-using Field
 * rebuilt through `new self(...)` with the three trait slots silently
 * re-initialised to null — so a dynamic title/hide closure vanished on the
 * first focus, blur, keystroke or validation recompute. This trait makes
 * the carrier a single copy instead of seven divergent ones (the Duplicated
 * Test-Helper discipline applied on the src side).
 *
 * Contract for adopters: EVERY instance-side `new self(...)` / sibling
 * rebuild must funnel its result through {@see carryNonCtorState()}.
 * `Input::new()`-style static factories have no source state to carry and
 * must NOT call it. The TraitStateCarryFamilyTest census fails if a class
 * adopts the label traits without adopting this carrier.
 *
 * Requires the using class to also use {@see HasHideFunc},
 * {@see HasDynamicLabels} and {@see HasReadonly} (their private slots are
 * written here — legal because trait methods compose into the class scope).
 *
 * The trait also OWNS the plan-5.11 per-error help slot ($errorHelp,
 * storage only — the public API lives in {@see HasErrorHelp}): the render
 * value must survive every rebuild exactly like the closures do, and this
 * carrier is the single funnel that guarantees it.
 */
trait CarriesNonCtorState
{
    /**
     * Plan 5.11 storage: null = no help line. Declared here (not in
     * HasErrorHelp) so one carrier leg serves every field; the three
     * fields with the public setter use {@see HasErrorHelp}.
     */
    private ?string $errorHelp = null;

    /**
     * Plan 5.2 storage: the async validator closure, null = field is
     * synchronous-only. Declared here (not in HasAsyncValidation) for the
     * same single-funnel reason as $errorHelp; the two async-capable
     * fields (Input, Text) use {@see HasAsyncValidation}.
     *
     * @var (\Closure(mixed):\React\Promise\PromiseInterface<?string>)|null
     */
    private $asyncValidator = null;

    /**
     * @param static $next  a freshly `new self(...)`-built sibling instance
     * @return static  $next with the hide/dynamic-label closures, the
     *                 read-only flag and the two post-ctor feature slots carried
     */
    private function carryNonCtorState(self $next): self
    {
        $next->hideFunc        = $this->hideFunc;
        $next->titleFunc       = $this->titleFunc;
        $next->descriptionFunc = $this->descriptionFunc;
        $next->readonly        = $this->readonly;
        $next->errorHelp       = $this->errorHelp;
        $next->asyncValidator  = $this->asyncValidator;
        return $next;
    }
}
