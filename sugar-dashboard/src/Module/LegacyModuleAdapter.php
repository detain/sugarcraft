<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Module;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Dashboard\Msg\AdapterTickMsg;

/**
 * Adapter letting a legacy plugin implement the {@see Module} contract.
 *
 * The legacy view(state, width, height) API is preserved; the adapter
 * satisfies the new contract by carrying the wrapped module's state in
 * an array and remembering the most recent window size it observed.
 *
 * @param array<string, mixed> $state
 */
final class LegacyModuleAdapter implements Module
{
    /**
     * Panel width/height last observed from a {@see WindowSizeMsg}.
     * The legacy contract wants dimensions on view(), but the framework
     * delivers them through update(); a cold start before any resize
     * message uses the documented 80×24 default.
     *
     * @var array{0:int,1:int}
     */
    private array $size = [80, 24];

    /**
     * Refresh interval advertised by the init() handshake; drives the
     * tick the adapter subscribes to so the plugin panel actually moves
     * on its own cadence.
     */
    private float $refreshSeconds = 30.0;

    /**
     * Whether the init() handshake has already run.
     */
    private bool $handshaked = false;

    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        private readonly LegacyModule $legacy,
        private array $state = [],
    ) {
    }

    /**
     * Create an adapter from a legacy-module constructor.
     *
     * Construction performs no handshake: the plugin's init() runs
     * exactly once, when the framework drives {@see self::init()}.
     * (This entry point used to run the handshake itself and throw the
     * result away, and then init() ran it again — spawning the plugin
     * child twice and orphaning the first.)
     *
     * @param callable(): LegacyModule $constructor
     */
    public static function from(callable $constructor): self
    {
        return new self($constructor());
    }

    public function name(): string
    {
        return $this->legacy->name();
    }

    public function init(): ?\Closure
    {
        // The legacy handshake returns metadata, not a startup command.
        // Run it exactly once and capture the refresh cadence it
        // advertises; the cadence used to be read and then silently
        // dropped, so plugin panels only advanced when an unrelated
        // source woke them.
        if ($this->handshaked) {
            return null;
        }
        $this->handshaked = true;

        $meta = $this->legacy->init();

        $interval = $meta['interval'] ?? null;
        if (is_numeric($interval)) {
            $this->refreshSeconds = max(1.0, (float) $interval);
        }

        return null;
    }

    /**
     * @return array{0:Module,1:?Cmd}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            $this->size = [max(1, $msg->cols), max(1, $msg->rows)];
        }

        // Only the adapter's own refresh tick and resizes advance the
        // legacy round-trip; every other routed message — keys
        // included — returns the same instance. (The wrapper used to
        // forward every message into a synchronous plugin round-trip,
        // making each keystroke block the frame on a child-process
        // write+read.)
        if (!$msg instanceof AdapterTickMsg && !$msg instanceof WindowSizeMsg) {
            return [$this, null];
        }

        $this->state = $this->legacy->update($this->state);

        // Returned in place: the wrapped child is itself stateful, so a
        // shallow clone would share it anyway — pretending snapshot
        // semantics here would be a lie the type cannot keep.
        return [$this, null];
    }

    public function view(): string
    {
        return $this->legacy->view($this->state, $this->size[0], $this->size[1]);
    }

    public function minSize(): array
    {
        return $this->legacy->minSize();
    }

    /**
     * Schedule the plugin's advertised refresh interval.
     */
    public function subscriptions(): ?Subscriptions
    {
        return (new Subscriptions())->withTick(
            'plugin-refresh',
            $this->refreshSeconds,
            static fn (): Msg => new AdapterTickMsg(),
        );
    }
}
