<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

/**
 * Package-local composition contract for embedding QuickPick in a
 * Model–Update–View application.
 *
 * Implement this to create a component that drives a QuickPick from
 * host-defined messages and renders the composite. NOTE: this intentionally
 * differs from sugar-core's house Model contract
 * (init()/update()/view()/subscriptions()): it is QuickPick-centric and
 * ships no subscriptions plumbing.
 *
 * This library decodes NO input itself — $msg strings are HOST-DEFINED.
 * Whatever the host's event loop names a key (keysyms, "enter", a typed
 * rune) is what arrives here; the interface only standardises that it is a
 * string. See README “Wiring keyboard input” for the method each event
 * should be forwarded to.
 *
 * The update returns a PAIR — [this host, the updated QuickPick] (-006):
 * every QuickPick keystroke yields a fresh fluent clone, and the old shape
 * (`update(): Model`) had no channel to carry it back, so implementations
 * were forced to stash the new picker in a mutable property — misuse by
 * design. The tuple threads state the way QuickPick itself does: through
 * return values, hosts staying immutable if they wish.
 */
interface QuickPickHost
{
    /**
     * Handle a message and return the updated pair.
     *
     * @param QuickPick $model  The current QuickPick state
     * @param string $msg     A host-defined event string (this library ships
     *                        no key decoder — names like "enter" below are
     *                        the host's vocabulary, not recognised tokens)
     * @return array{0: QuickPickHost, 1: QuickPick}
     *         [this host, the post-message QuickPick] — the picker is
     *         threaded out here, never smuggled through host state
     *         (may be [$this, $model] for same-state)
     */
    public function update(QuickPick $model, string $msg): array;

    /**
     * Render the view given the current QuickPick state.
     *
     * @param QuickPick $model  The current QuickPick state
     * @return string  The rendered view string
     */
    public function view(QuickPick $model): string;
}
