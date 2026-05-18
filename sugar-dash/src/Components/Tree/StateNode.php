<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * A state in a state machine diagram.
 */
final class StateNode
{
    /** @var list<string> */
    public array $entryActions = [];

    /** @var list<string> */
    public array $exitActions = [];

    /** @var list<string> */
    public array $internalActions = [];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $isInitial = false,
        public readonly bool $isFinal = false,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Add an entry action.
     */
    public function withEntry(string $action): self
    {
        $clone = clone $this;
        $clone->entryActions[] = $action;
        return $clone;
    }

    /**
     * Add an exit action.
     */
    public function withExit(string $action): self
    {
        $clone = clone $this;
        $clone->exitActions[] = $action;
        return $clone;
    }

    /**
     * Add an internal action.
     */
    public function withInternal(string $action): self
    {
        $clone = clone $this;
        $clone->internalActions[] = $action;
        return $clone;
    }

    /**
     * Create an initial state.
     */
    public static function initial(string $id, string $label): self
    {
        return new self($id, $label, true, false);
    }

    /**
     * Create a final state.
     */
    public static function final(string $id, string $label): self
    {
        return new self($id, $label, false, true);
    }

    /**
     * Create a normal state.
     */
    public static function state(string $id, string $label): self
    {
        return new self($id, $label, false, false);
    }
}
