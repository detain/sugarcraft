<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * A provider subprocess failure carrying the child's exit code as STRUCTURE
 * instead of prose (E27(a)).
 *
 * WHY IT EXTENDS \RuntimeException
 * --------------------------------
 * Callers around the providers catch `\RuntimeException` broadly. Narrowing
 * the type here would silently turn caught failures into process-killing
 * fatals, so this class IS a `\RuntimeException` and the two
 * `ClaudeCodeProvider` throw sites keep their exact messages.
 *
 * WHY THE EXIT CODE IS A PROPERTY AND NOT THE EXCEPTION CODE
 * ----------------------------------------------------------
 * {@see TransientFailure::statusCode()} reads `getStatusCode()` when a
 * throwable has it, treating the number as an HTTP status. A subprocess exit
 * code is a different dimension entirely (and a shell exit of 429 or a
 * 128+signal death would otherwise be misread as "rate limited"), so the code
 * rides on {@see $exitCode} alone and the parent's `getCode()` stays 0.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * ----------------------------------------
 * It does not make anything retryable. E27's prescription is to carry the
 * code FIRST and decide per code SECOND (a spawn failure is transient, a
 * non-zero exit usually is not); that decision belongs to
 * {@see TransientFailure}'s allow-list, which still does not recognise this
 * class, so both shapes keep their current not-retried verdict. The pinned
 * decision in `TransientFailureTest` records the open half.
 */
final class ProviderException extends \RuntimeException
{
    /**
     * @param int|null $exitCode null means the process never started (spawn
     *                           failure); an int means the child ran and
     *                           exited with that code.
     */
    public function __construct(
        string $message,
        public readonly ?int $exitCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
