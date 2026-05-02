<?php

declare(strict_types=1);

namespace CandyCore\Core;

/**
 * Marker interface for messages flowing through the {@see Program} loop.
 *
 * A Msg is any event the user's {@see Model::update()} should react to —
 * keypresses, window-size changes, ticks, custom application messages, etc.
 * Implementations are typically immutable readonly value objects.
 */
interface Msg
{
}
