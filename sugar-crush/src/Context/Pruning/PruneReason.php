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

    /**
     * A newer identical call — or, for a `Read`, a newer read of the whole
     * file — answered it again ({@see Strategies\DuplicateCallStrategy}).
     */
    case Duplicate = 'duplicate';

    /**
     * A `Read` older than a successful `Edit` or `Write` of the same file: the
     * file no longer says that ({@see Strategies\StaleReadStrategy}).
     */
    case Stale = 'stale';

    /**
     * A `Write`'s content superseded by a later write or whole-file read of
     * the same file ({@see Strategies\SupersededWriteInputStrategy}).
     */
    case Superseded = 'superseded';

    /**
     * The input of a call that failed, several user turns ago
     * ({@see Strategies\ErroredInputStrategy}).
     */
    case Errored = 'errored';
}
