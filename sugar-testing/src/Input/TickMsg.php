<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Input;

use SugarCraft\Core\Msg;

/**
 * The harness's own tick message.
 *
 * Upstream fires ticks on a runtime timer; this harness has no timer, so a
 * tick is just a message a script pushes into the queue. Match accordingly:
 * a model is only advanced by the exact class it checks for, and every
 * fleet lib ships its OWN tick class (SugarCraft\Dash\TickMsg, ...). This
 * one exists for tests and models that choose to speak the harness
 * directly — feeding it to a lib with its own class matches nothing, so
 * {@see \SugarCraft\Testing\Input\ScriptedInput::ticks()}'s factory
 * parameter is the way to deliver YOUR lib's tick.
 */
final readonly class TickMsg implements Msg
{
    /**
     * @param float $seconds The interval this tick represents
     */
    public function __construct(
        public float $seconds,
    ) {}
}
