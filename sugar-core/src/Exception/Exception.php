<?php

declare(strict_types=1);

namespace SugarCraft\Core\Exception;

/**
 * Base exception for SugarCraft domain errors.
 *
 * Library-specific *domain* exceptions extend this class; SPL-shaped
 * mirrors (`RuntimeException`, `InvalidArgumentException`,
 * `ProgramException`) extend their SPL counterparts instead, on the
 * law `SemaphoreClosedException` documents: promoting a type out of
 * the `\RuntimeException` lineage silently drops it out of every
 * caller's existing `catch (\RuntimeException)`. An `instanceof
 * Exception` check therefore identifies a domain-classified fault,
 * not "some SugarCraft error" — use the SPL catch idiom (or
 * `\Throwable`) for transport-level discrimination.
 *
 * Factory exemption (sc-26 / finding sugar-core-039): the upstream is Go,
 * where errors are values, not a constructed class family — there is no
 * `NewX()` counterpart to mirror, so `::new()` factories are exempt across
 * `src/Exception` by this dated note; `fromKey()` is the SugarCraft-side
 * i18n constructor instead.
 */
class Exception extends \Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
