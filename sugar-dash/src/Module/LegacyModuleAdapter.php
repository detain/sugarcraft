<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\LegacyModule;

/**
 * Adapter that wraps a LegacyModule to satisfy the Module contract.
 *
 * Allows the Registry to host both new-style Modules and legacy
 * modules without breaking existing third-party code.
 *
 * @internal
 */
final class LegacyModuleAdapter implements Module
{
    /**
     * @param LegacyModule $legacy The wrapped legacy module
     * @param array<string, mixed> $state Current state accumulated across updates
     */
    public function __construct(
        private readonly LegacyModule $legacy,
        private array $state = [],
    ) {}

    /**
     * Factory to create from a legacy module constructor.
     *
     * @param callable(): LegacyModule $constructor
     */
    public static function from(callable $constructor): self
    {
        $legacy = $constructor();
        $meta = $legacy->init();
        return new self($legacy, []);
    }

    public function name(): string
    {
        return $this->legacy->name();
    }

    public function init(): ?\Closure
    {
        // Legacy init() returns array metadata; we return null since
        // the legacy pattern does not produce a startup Cmd.
        $this->legacy->init();
        return null;
    }

    public function update(Msg $msg): array
    {
        // Legacy update() takes array state, not a Msg.
        // We discard the Msg and feed our accumulated state instead.
        $this->state = $this->legacy->update($this->state);
        return [$this, null];
    }

    public function view(): string
    {
        // Legacy view() takes state + width + height; we pass stored state
        // and use a sensible default for dimensions. Callers that need
        // exact dimensions should use the LegacyModule interface directly.
        return $this->legacy->view($this->state, 80, 24);
    }

    public function minSize(): array
    {
        return $this->legacy->minSize();
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
