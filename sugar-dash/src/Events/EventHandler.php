<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Events;

/**
 * Event handler function type.
 *
 * @template T of Event
 * @param T $event
 * @return void
 */
type EventHandler = callable;
