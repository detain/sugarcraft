<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * Why a {@see PruneEntry} was written — carried for the record a later
 * transcript badge or `/context` reads (DCP §13.2 B), never for the
 * projection, which depends only on {@see PruneKind}.
 */
enum PruneReason: string
{
    /**
     * Older than the protected window of the age rule
     * ({@see Strategies\ToolOutputAgeStrategy}): the result lies outside the
     * last user turns and behind the newest tool output the policy keeps.
     */
    case Aged = 'aged';
}
