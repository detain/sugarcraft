<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * A Composite is a Model that owns a set of child {@see Component}s.
 *
 * It dispatches messages to children by id and calls onMount/onUnmount
 * lifecycle hooks as the child set evolves across update cycles.
 *
 * Children are stored as an immutable array keyed by string id.
 *
 * @readonly
 */
final class Composite implements Model
{
    use SubscriptionCapable;

    /**
     * @param array<string, Component> $children
     * @param list<\Closure> $pendingCmds Lifecycle Cmds accumulated during reconcile
     */
    public function __construct(
        private readonly array $children = [],
        private readonly array $pendingCmds = [],
    ) {}

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * Handle a Msg.
     *
     * Internal message subtypes:
     *   - {@see AddComponentMsg}    — add or replace a child Component by id
     *   - {@see RemoveComponentMsg} — remove a child Component by id
     *
     * All other messages are forwarded to the matching child via its
     * id, or silently dropped if the child is not found.
     *
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof AddComponentMsg) {
            return $this->reconcileAdd($msg);
        }
        if ($msg instanceof RemoveComponentMsg) {
            return $this->reconcileRemove($msg);
        }

        $childId = $this->childIdForMsg($msg);
        if ($childId !== null && isset($this->children[$childId])) {
            [$child, $cmd] = $this->children[$childId]->update($msg);
            if ($child !== $this->children[$childId]) {
                $children = $this->children;
                $children[$childId] = $child;
                return [new self($children), $cmd];
            }
            return [$this, $cmd];
        }

        return [$this, null];
    }

    public function view(): string
    {
        $out = '';
        foreach ($this->children as $child) {
            $out .= $child->view();
        }
        return $out;
    }

    /**
     * Return a new Composite with the given children merged in.
     * Existing children with the same id are replaced.
     *
     * @param array<string, Component> $children
     */
    public function withChildren(array $children): self
    {
        return new self($children);
    }

    /**
     * @return array<string, Component>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * @return list<\Closure>
     */
    public function pendingCmds(): array
    {
        return $this->pendingCmds;
    }

    /**
     * Determine which child id a message belongs to, if any.
     * Override in subclasses to customise routing.
     */
    protected function childIdForMsg(Msg $msg): ?string
    {
        if ($msg instanceof ComponentAddressedMsg) {
            return $msg->componentId();
        }
        return null;
    }

    /**
     * Reconcile an AddComponentMsg: call onMount() for new children
     * and queue any returned Cmds.
     *
     * @return array{0: Composite, 1: ?\Closure}
     */
    private function reconcileAdd(AddComponentMsg $msg): array
    {
        $id = $msg->id;
        $component = $msg->component;

        $children = $this->children;
        $children[$id] = $component;

        $wasPresent = isset($this->children[$id]);
        $cmds = $this->pendingCmds;

        if (!$wasPresent) {
            $mountCmd = $component->onMount();
            if ($mountCmd !== null) {
                $cmds[] = $mountCmd;
            }
        }

        return [new self($children, $cmds), null];
    }

    /**
     * Reconcile a RemoveComponentMsg: call onUnmount() for removed
     * children and queue any returned Cmds.
     *
     * @return array{0: Composite, 1: ?\Closure}
     */
    private function reconcileRemove(RemoveComponentMsg $msg): array
    {
        $id = $msg->id;

        if (!isset($this->children[$id])) {
            return [$this, null];
        }

        $children = $this->children;
        unset($children[$id]);

        $cmds = $this->pendingCmds;
        $unmountCmd = $this->children[$id]->onUnmount();
        if ($unmountCmd !== null) {
            $cmds[] = $unmountCmd;
        }

        return [new self($children, $cmds), null];
    }
}

/**
 * Messages that carry a component id for routing within a Composite.
 */
interface ComponentAddressedMsg extends Msg
{
    public function componentId(): ?string;
}

/**
 * @internal
 */
final class AddComponentMsg implements ComponentAddressedMsg
{
    public function __construct(
        public readonly string $id,
        public readonly Component $component,
    ) {}

    public function componentId(): ?string
    {
        return $this->id;
    }
}

/**
 * @internal
 */
final class RemoveComponentMsg implements ComponentAddressedMsg
{
    public function __construct(public readonly string $id) {}

    public function componentId(): ?string
    {
        return $this->id;
    }
}
