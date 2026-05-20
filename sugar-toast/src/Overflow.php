<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

/**
 * Overflow strategy when the toast queue exceeds maxConcurrent.
 */
enum Overflow
{
    /** Remove the oldest alert to make room for the new one. */
    case DropOldest;

    /** Discard the new alert rather than adding it to the queue. */
    case DropNewest;

    /** Allow the queue to temporarily exceed maxConcurrent. */
    case Enqueue;
}
