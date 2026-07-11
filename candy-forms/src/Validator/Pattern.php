<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Validator;

use SugarCraft\Forms\Lang;

/**
 * Validates that input matches a given regex pattern.
 *
 * SECURITY (ReDoS): the pattern is caller-supplied arbitrary PCRE and is run
 * against attacker-influenced input, so a "catastrophic backtracking" pattern
 * (e.g. `/^(a+)+$/` against `"aaaa…aaaX"`) could otherwise burn CPU — on the
 * TEA event loop that stalls the whole UI. To fail safe rather than hang, the
 * match is run under a bounded PCRE backtrack / recursion budget (applied via
 * a scoped `ini_set()` that is always restored). If the budget is exhausted
 * the input is treated as INVALID (the pattern message is returned) instead of
 * looping unbounded. The defaults ({@see DEFAULT_BACKTRACK_LIMIT} /
 * {@see DEFAULT_RECURSION_LIMIT}) are ample for ordinary patterns on the
 * bounded inputs candy-forms fields accept; callers with unusual needs can
 * override them via the constructor. Prefer anchored, linear patterns and a
 * field `charLimit` over relying on the guard.
 */
final class Pattern implements Validator
{
    /** Default PCRE backtrack budget applied around the match (see class docs). */
    public const DEFAULT_BACKTRACK_LIMIT = 200_000;

    /** Default PCRE recursion budget applied around the match (see class docs). */
    public const DEFAULT_RECURSION_LIMIT = 100_000;

    public readonly int $backtrackLimit;
    public readonly int $recursionLimit;

    public function __construct(
        public readonly string $pattern,
        public readonly ?string $message = null,
        ?int $backtrackLimit = null,
        ?int $recursionLimit = null,
    ) {
        if (@preg_match($this->pattern, '') === false) {
            throw new \InvalidArgumentException('Invalid regex pattern: ' . $this->pattern);
        }
        $this->backtrackLimit = $backtrackLimit ?? self::DEFAULT_BACKTRACK_LIMIT;
        $this->recursionLimit = $recursionLimit ?? self::DEFAULT_RECURSION_LIMIT;
    }

    public function validate(string $input): true|string
    {
        if ($input === '') {
            return true;
        }

        // Run the (arbitrary, caller-supplied) PCRE under a bounded backtrack /
        // recursion budget so a catastrophic pattern fails safe instead of
        // hanging. ini_set is scoped and restored in the finally block.
        $origBacktrack = ini_get('pcre.backtrack_limit');
        $origRecursion = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', (string) $this->backtrackLimit);
        ini_set('pcre.recursion_limit', (string) $this->recursionLimit);
        try {
            $matched = preg_match($this->pattern, $input);
            $error   = preg_last_error();
        } finally {
            if ($origBacktrack !== false) {
                ini_set('pcre.backtrack_limit', $origBacktrack);
            }
            if ($origRecursion !== false) {
                ini_set('pcre.recursion_limit', $origRecursion);
            }
        }

        // A budget exhaustion (PREG_BACKTRACK_LIMIT_ERROR / recursion) makes
        // preg_match return false and sets preg_last_error(); treat any error
        // or non-match as INVALID so the guard fails closed.
        if ($matched !== 1 || $error !== PREG_NO_ERROR) {
            return $this->message ?? Lang::t('validator.pattern');
        }
        return true;
    }
}