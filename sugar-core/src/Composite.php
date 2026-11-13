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
 * A Composite is itself a {@see Component}, so composites nest: a
 * subtree parked under one id participates in the parent's lifecycle,
 * and {@see drainPendingCmds()} walks the whole tree so a Cmd queued
 * at any depth still runs (see sugar-core-010).
 *
 * @readonly
 */
final class Composite implements Component
{
    use SubscriptionCapable;

    /**
     * A Composite has no mount Cmd of its own: its children mount and
     * unmount through {@see reconcileAdd()}/{@see reconcileRemove()}
     * as they enter and leave, so the tree-level hooks are the no-ops.
     */
    public function onMount(): ?\Closure
    {
        return null;
    }

    /**
     * @see onMount() — removal of a whole subtree releases no extra
     * Cmd beyond what the tree walk already queued per child.
     */
    public function onUnmount(): ?\Closure
    {
        return null;
    }

    /**
     * @param array<string, Component> $children
     * @param list<\Closure> $pendingCmds Lifecycle Cmds accumulated during reconcile
     */
    public function __construct(
        private readonly array $children = [],
        private readonly array $pendingCmds = [],
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * Handle a Msg.
     *
     * Internal message subtypes:
     *   - {@see AddComponentMsg}    — add (mount) or replace (unmount
     *     the outgoing, mount the incoming) a child Component by id
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
                // update() returns Model; child slots must be Component.
                // Practically children always return their own type, but guard
                // for robustness: if the new model isn't a Component, retain
                // the old child instance.
                if ($child instanceof Component === false) {
                    return [$this, $cmd];
                }
                $children = $this->children;
                $children[$childId] = $child;
                // Carry this node's queued lifecycle Cmds across the
                // rebuild: dropping them here made a Cmd queued earlier
                // in the cycle evaporate before the drain at its end.
                return [new self($children, $this->pendingCmds), $cmd];
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
     * Existing children with the same id are replaced (the incoming
     * map wins on collisions, like array_union). Lifecycle Cmds this
     * node has already queued are carried across untouched — they
     * belong to the same tree, and the end-of-cycle drain still owns
     * running them.
     *
     * @param array<string, Component> $children
     */
    public function withChildren(array $children): self
    {
        return new self([...$this->children, ...$children], $this->pendingCmds);
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
     * Collect the lifecycle Cmds queued in this node and in every
     * nested Composite, returning the tree rebuilt with all queues
     * cleared.
     *
     * This is the single home of pending-queue execution: Program's
     * end-of-cycle drain walks the whole tree through here, so a Cmd
     * queued by a nested subtree's mount — a Cmd::quit(), exec, or
     * worker three levels down — runs just like one queued at the
     * root. Queues come out in tree order: this node's first, then
     * each child subtree in child order.
     *
     * When nothing in the tree holds a queued Cmd the node returns
     * itself, identity unchanged: the every-tick path through a
     * settled tree allocates nothing.
     *
     * @return array{0: Composite, 1: list<\Closure>}
     */
    public function drainPendingCmds(): array
    {
        $cmds = $this->pendingCmds;
        /** @var ?array<string, Component> $rebuilt lazily-copied child map */
        $rebuilt = null;

        foreach ($this->children as $id => $child) {
            if (!$child instanceof self) {
                continue;
            }
            [$kept, $nested] = $child->drainPendingCmds();
            if ($nested === []) {
                continue;
            }
            $cmds = [...$cmds, ...$nested];
            $rebuilt ??= $this->children;
            $rebuilt[$id] = $kept;
        }

        if ($cmds === []) {
            return [$this, []];
        }
        return [new self($rebuilt ?? $this->children, []), $cmds];
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
     * and queue any returned Cmds. Replacing an existing id runs the
     * outgoing child's onUnmount() and the incoming child's onMount()
     * — lifecycle parity with the push/pop paths; re-adding the very
     * same instance stays a no-op.
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
        } elseif ($this->children[$id] !== $component) {
            // sugar-core-016: a replacement used to swap children in
            // silence — the outgoing component never released its
            // resources and the incoming one never mounted.
            $outCmd = $this->children[$id]->onUnmount();
            if ($outCmd !== null) {
                $cmds[] = $outCmd;
            }
            $inCmd = $component->onMount();
            if ($inCmd !== null) {
                $cmds[] = $inCmd;
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
