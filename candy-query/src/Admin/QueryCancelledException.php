<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Rejection reason for an async query aborted via a cancellation token.
 *
 * Distinct from the timeout RuntimeException so callers can tell "the user
 * cancelled" apart from "the deadline fired" — the former should be silent,
 * the latter usually surfaces in the UI.
 */
final class QueryCancelledException extends \RuntimeException
{
}
