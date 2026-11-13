<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Module;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Subscriptions;

/**
 * Abstract base class for modules with default behaviour.
 *
 * Provides sensible defaults for all Module interface methods.
 * Subclasses only need to implement name(), update(), and view().
 *
 * State is kept as a private array. Use withState(...) to produce
 * a clone with updated fields — the immutable with*() pattern.
 *
 * ## Tick cadence (sugar-dashboard-007)
 *
 * A module that refreshes on a timer declares its cadence through the
 * two protected hooks {@see self::tickIntervalSeconds()} and
 * {@see self::tickProducer()}; the inherited {@see self::subscriptions()}
 * then exposes it as a core Tick subscription, which a
 * \SugarCraft\Core\Program honours directly (its reconciler arms a
 * periodic timer for every subscription declared in the model tree).
 *
 * Hosts that only execute returned Cmds and never consult
 * subscriptions() — the hand-rolled example drivers — keep the legacy
 * `Cmd::tick(...)` self-rearming chain: {@see self::selfTickCmd()}
 * yields it while subscriptions() has not been consulted on this very
 * instance, and yields null the moment a subscription host reads it, so
 * a Program-driven module converges to a single tick source (at most
 * one extra legacy tick may race in during the first cycle).
 */
abstract class BaseModule implements Module
{
    /** @var array<string, mixed> */
    private array $state = [];

    /**
     * Memoised tick subscription. Clones share the handle (PHP clones
     * copy object references), so what the core reconciler diffs keeps a
     * stable producer object id across the clone lineage — a rebuilt
     * closure on every cycle would stop-and-rearm the timer endlessly
     * and the cadence would never fire.
     */
    private ?Subscriptions $tickSubscriptionCache = null;

    /**
     * True once a host consulted subscriptions() on THIS instance — the
     * authority signal for {@see self::selfTickCmd()}. Fresh clones start
     * unchecked: only an instance the host actually holds subscriptions()
     * on retires its legacy chain, so cmd-only drivers (examples, plain
     * harnesses) keep the self-rearming tick exactly as before.
     */
    private bool $tickSubscriptionConsulted = false;

    /**
     * {@inheritdoc}
     */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * {@inheritdoc}
     *
     * Base implementation returns [self, null] — subclasses override
     * to return a new instance with updated state via withState(...).
     */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    /**
     * {@inheritdoc}
     *
     * Base implementation returns empty string — subclasses override
     * to render their current state.
     */
    public function view(): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function minSize(): array
    {
        return [30, 4];
    }

    /**
     * The module's declared subscriptions, memoised per clone lineage.
     *
     * Consulted-by-a-host doubles as the authority signal: once any host
     * reads subscriptions() for this class, {@see self::selfTickCmd()}
     * stops producing the legacy one-shot chain so the periodic
     * subscription timer becomes the single tick source.
     */
    public function subscriptions(): ?Subscriptions
    {
        $this->tickSubscriptionConsulted = true;

        if ($this->tickSubscriptionCache === null) {
            $seconds = $this->tickIntervalSeconds();
            $produce = $this->tickProducer();
            if ($seconds !== null && $produce !== null) {
                $this->tickSubscriptionCache = (new Subscriptions())->withTick(
                    $this->tickSubscriptionId(),
                    $seconds,
                    $produce,
                );
            }
        }

        return $this->tickSubscriptionCache;
    }

    /**
     * Refresh cadence in seconds for the tick subscription, or null when
     * this module does not tick on a timer. Override to opt in.
     */
    protected function tickIntervalSeconds(): ?float
    {
        return null;
    }

    /**
     * Producer that mints this module's tick marker message. Override
     * together with {@see self::tickIntervalSeconds()}; memoise the
     * closure in a function-local `static` so every call returns the
     * same object (cadence stability — see the class docblock).
     *
     * @return null|\Closure(): Msg
     */
    protected function tickProducer(): ?\Closure
    {
        return null;
    }

    /**
     * Subscription id for the tick arm. Unique per module name — the
     * core reconciler refuses duplicate ids across the model tree, and
     * module names are already a registry-unique identifier.
     */
    protected function tickSubscriptionId(): string
    {
        return 'module.' . $this->name() . '.tick';
    }

    /**
     * Legacy fallback tick command for hosts that execute returned Cmds
     * but never consult subscriptions(). Null once a subscription host
     * has taken over the cadence (or when the module does not tick).
     *
     * @return null|\Closure(): Msg
     */
    protected function selfTickCmd(): ?\Closure
    {
        if ($this->tickSubscriptionConsulted) {
            return null;
        }

        $seconds = $this->tickIntervalSeconds();
        $produce = $this->tickProducer();
        if ($seconds === null || $produce === null) {
            return null;
        }

        return Cmd::tick($seconds, $produce);
    }

    /**
     * Get current module state.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::state()} instead. REMOVED-IN: 2.0.0.
     */
    public function getState(): array { return $this->state(); }

    /**
     * Create a clone of this module with the given state merged in.
     *
     * @param array<string, mixed> $overrides State fields to merge
     * @return static A new instance with merged state
     */
    protected function withState(array $overrides): static
    {
        $clone = clone $this;
        $clone->state = array_merge($this->state, $overrides);
        return $clone;
    }
}
