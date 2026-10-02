<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * A provider that may talk to a model OTHER than the id it was configured
 * with, and can say which (audit 15b-35).
 *
 * {@see SglangProvider} built on its default id adopts whatever model the
 * server reports serving (audit A26), so the wire carries the served name
 * while every label in the TUI — read off the configured id — kept showing
 * the static default. The served name is discovered lazily, and on the TUI's
 * path usually inside the `pcntl_fork()`ed turn child, whose memory dies with
 * it; this is the seam that lets the parent both ASK for the name and be TOLD
 * it by the child ({@see \SugarCraft\Crush\Backend\EngineBackend} carries it
 * home on the turn's result frame).
 */
interface ReportsServedModel
{
    /**
     * The model the server reports serving, when this provider addresses
     * requests to it in place of its configured id and has learned the name —
     * from its own discovery or from {@see noteServedModel()}. Null when it
     * adopts nothing, or has not learned the name yet.
     *
     * NEVER performs I/O: a label renders every frame, and a status line
     * must not be the thing that probes a server.
     */
    public function servedModel(): ?string;

    /**
     * Record a served model name learned elsewhere — the forked turn child's
     * discovery, carried back across the fork. Ignored by a provider that
     * adopts no served model, and for an empty name.
     */
    public function noteServedModel(string $servedModel): void;
}
