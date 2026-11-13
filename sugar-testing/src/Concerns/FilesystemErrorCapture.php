<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Concerns;

/**
 * Runs a filesystem call and captures the diagnostic that call itself
 * raised, instead of reading the process-global error_get_last().
 *
 * The error_get_last() idiom is unsafe in a shared PHP process: it returns
 * whichever diagnostic fired most recently anywhere, so a prior @-silenced
 * warning from unrelated code (a fixture, another lib, an earlier skipped
 * leg) could be attributed to a write that never failed — or a real failure
 * reported with a stale message. This swaps the site's own error handler in
 * around the call, so the reason is provably the one the call produced.
 *
 * Consumes diagnostics (returns true from the handler) rather than letting
 * them reach PHPUnit, because the whole point is that the caller expects
 * the failure and will surface it as a typed error — failOnWarning must not
 * trip on the E_WARNING a checked @-call already accounts for.
 */
trait FilesystemErrorCapture
{
    /**
     * Invoke $attempt with a scoped error handler.
     *
     * @template T
     *
     * @param callable():T $attempt the suppressed filesystem call
     *
     * @return array{T|null, string|null} the call's return value and the
     *                                     first diagnostic message it
     *                                     raised (null when it was clean)
     */
    private static function captureFilesystemError(callable $attempt): array
    {
        $diagnostic = null;

        set_error_handler(
            /**
             * @return bool true consumes the diagnostic so it never reaches
             *             PHPUnit; only the first one for this call is kept.
             */
            static function (int $severity, string $message) use (&$diagnostic): bool {
                if ($diagnostic === null) {
                    $diagnostic = $message;
                }

                return true;
            },
        );

        try {
            $result = $attempt();
        } finally {
            restore_error_handler();
        }

        return [$result, $diagnostic];
    }
}
