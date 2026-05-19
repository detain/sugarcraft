<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * A Component is a Model that participates in a component tree with
 * explicit mount/unmount lifecycle hooks.
 *
 * When a Component is first added to a {@see Composite} parent, the
 * runtime calls {@see onMount()}. When it is removed, {@see onUnmount()}
 * is called. This allows components to acquire/release resources
 * (subscriptions, file handles, etc.) exactly when they enter or
 * leave the tree.
 */
interface Component extends Model
{
    /**
     * Fired once when the component is first mounted into a tree.
     * Return a Cmd to run immediately (e.g. to start a subscription),
     * or null for no-op.
     *
     * @return ?Closure
     */
    public function onMount(): ?\Closure;

    /**
     * Fired once when the component is removed from a tree.
     * Return a Cmd to run immediately (e.g. to cancel a subscription),
     * or null for no-op.
     *
     * @return ?Closure
     */
    public function onUnmount(): ?\Closure;
}
