<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use React\Promise\PromiseInterface;

/**
 * A {@see Field} that can also answer "is this value acceptable?" off the
 * event loop (plan 5.2, round 89 lane y3).
 *
 * `charmbracelet/huh` validates synchronously only; this is the SugarCraft
 * extension for validators that must ask something slow — a uniqueness
 * check against a database, a remote username lookup. The promise shape
 * mirrors the async-suggestions machinery already shipped on Input/Select
 * (E736-F2, round 85): a deferred resolved by the caller's own I/O, never
 * a field-owned timer (E646: the library adds no blanket timeouts — a
 * hanging validator hangs its promise, and only its promise).
 *
 * Implementations MUST consult the synchronous validators first and only
 * reach out when the cheap gate passes, so a locally-invalid value never
 * costs a round trip (pinned in AsyncValidationTest).
 */
interface AsyncValidatable extends Field
{
    /**
     * Resolve with the validation error message, or `null` when the value
     * is acceptable. Rejects when the attached async validator itself
     * fails — rejection means "could not answer", never "invalid".
     *
     * @return PromiseInterface<?string>
     */
    public function validateAsync(): PromiseInterface;
}
