<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

/**
 * Passive observer over the typed event DTOs in {@see \SugarCraft\Code\Events}.
 *
 * The shape copies {@see \SugarCraft\Code\Diagnostics\RuntimeNoticeSink}: the
 * producer hands every object it folds and the sink decides what (if
 * anything) to keep. Unlike notices there is no drain step — a sink is an
 * egress, not a queue for the UI.
 *
 * Contract for implementations:
 * - `consume()` NEVER throws and NEVER blocks an unbounded wait: fold sites
 *   run inside the model's `update()` and have no error channel for a
 *   misbehaving observer.
 * - Anything the sink cannot classify is dropped, not remembered.
 */
interface EventSink
{
    /**
     * Accepts one folded event DTO (or any object a producer chooses to hand
     * over). Unrecognised shapes are ignored.
     */
    public function consume(object $event): void;

    /**
     * Best-effort, time-bounded flush of anything still buffered. Called at
     * shutdown; must never raise.
     */
    public function flush(): void;
}
