<?php

declare(strict_types=1);

namespace CandyCore\Core;

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

    public function view(): string;
}
