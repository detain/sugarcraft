<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

/**
 * Package-local composition contract for embedding QuickPick in a
 * model–update–view application.
 *
 * Implement this to create a component that holds a QuickPick and responds
 * to messages by updating its state. NOTE: this intentionally differs from
 * sugar-core's house Model contract (init()/update()/view()/subscriptions()):
 * it is QuickPick-centric and ships no subscriptions plumbing.
 *
 * This library decodes NO input itself — $msg strings are HOST-DEFINED.
 * Whatever the host's event loop names a key (keysyms, "enter", a typed
 * rune) is what arrives here; the interface only standardises that it is a
 * string. See README “Wiring keyboard input” for the method each event
 * should be forwarded to.
 */
interface Model
{
    /**
     * Handle a message and return the updated model.
     *
     * @param QuickPick $model  The current QuickPick state
     * @param string $msg     A host-defined event string (this library ships
     *                        no key decoder — names like "enter" below are
     *                        the host's vocabulary, not recognised tokens)
     * @return Model  A new model instance (may be $this for same-state)
     */
    public function update(QuickPick $model, string $msg): Model;

    /**
     * Render the view given the current QuickPick state.
     *
     * @param QuickPick $model  The current QuickPick state
     * @return string  The rendered view string
     */
    public function view(QuickPick $model): string;
}
