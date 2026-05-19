<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * The Elm-architecture Model contract.
 *
 *   - {@see init()} is invoked once on startup and may return an initial Cmd.
 *   - {@see update()} receives every Msg, returning the next model and an
 *     optional Cmd to run asynchronously.
 *   - {@see view()} renders the current model to a string the renderer
 *     prints to the terminal.
 *
 * Update return type is the tuple `[Model, ?Cmd]` where `Cmd` is a
 * `Closure(): ?Msg`. PHP lacks tuples; destructure with
 * `[$model, $cmd] = $model->update($msg)`.
 *
 * @property list<\SugarCraft\Core\Msg> $log RecordingModel message log
 *
 * @method list<\SugarCraft\Core\Pane> panes() Panes implementation: list of panes
 * @method int activeIndex() Panes implementation: index of active pane
 * @method int count() Panes implementation: number of panes
 */
interface Model
{
    /**
     * Return an initial Cmd to run on startup, or null.
     *
     * @return null|\Closure
     */
    public function init(): ?\Closure;

    /**
     * Handle a Msg, returning `[nextModel, optionalCmd]`.
     *
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array;

    /**
     * Render the model. Return a plain `string` for the common case,
     * or a {@see View} when you need per-frame control over cursor
     * shape / position, window title, etc. — matches Bubble Tea v2's
     * `tea.View` struct.
     *
     * @return string|View
     */
    public function view(): string|View;

    /**
     * Declare the subscriptions this model wants.
     *
     * The runtime calls this after each {@see update()} cycle and
     * reconciles the active subscription set: new subscriptions are
     * started, dropped ones are cancelled, stable ones are kept.
     *
     * Return null when the model needs no subscriptions.
     */
    public function subscriptions(): ?Subscriptions;
}
