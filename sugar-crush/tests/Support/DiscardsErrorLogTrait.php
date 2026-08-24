<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * One implementation of `withErrorLogDiscarded()` for the guards that need to
 * read what `error_log()` wrote, and the union of the two justifications its
 * copies carried.
 *
 * WHY THIS EXISTS AS A TRAIT AND NOT AS A SECOND COPY. Round 49 merged two
 * lanes that had each written this helper into their own file —
 * `Diagnostics/RuntimeNoticeSinkDeliveryTest` and
 * `Providers/SglangProviderReachabilityTest` — with bodies identical except for
 * the `tempnam()` prefix. NEITHER LANE COULD SEE IT: the copies were both
 * lane b's, and the guard that found them was lane d's
 * {@see DuplicatedTestHelperDriftTest}, which lane b did not have. It reddened
 * on the merged tree and nowhere else. That is the case the drift guard was
 * built for, and it is the reason to extract rather than to accept the
 * divergence.
 *
 * WHY `tempnam()` AND NOT A HAND-BUILT NAME, carried across from the
 * `Providers` copy because it is the half that records a mechanism: five suites
 * share one uid-keyed TMPDIR during an audit round, and the argument-less
 * `uniqid` form is microtime-derived rather than process-unique — the
 * cross-process collision that `db90e768` swept out of `tests/`. The bare call
 * is deliberately not spelled here; that sweep ate the prose describing it,
 * which is the same hazard in a different coat.
 *
 * WHY THE PREFIX IS A PARAMETER. It is a debugging label and nothing else —
 * `tempnam()` supplies the uniqueness. Keeping it per-caller preserves what the
 * two copies used it for (telling whose scratch file is whose in a shared
 * TMPDIR) without giving the two bodies anything to drift on, since there is
 * now only one body.
 *
 * WHY THE DOC-BLOCK IS A UNION AND NOT A PICK, on the precedent
 * {@see FlattensSourceProseTrait} set for E196/E224 in this same round: a
 * consolidation that keeps one implementation and one of the two reasons
 * re-creates the asymmetry it was meant to remove, because the surviving copy
 * now looks canonical and the dropped reason is the one nobody goes looking
 * for.
 */
trait DiscardsErrorLogTrait
{
    /**
     * Run `$body` with `error_log()` diverted to a scratch file, and return
     * what it wrote there.
     *
     * Silences `error_log()`'s half for the duration; only the seam under test
     * is being read.
     */
    private static function withErrorLogDiscarded(callable $body, string $prefix = 'sc_error_log_'): string
    {
        $log = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            $body();
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        $contents = (string) file_get_contents($log);
        @unlink($log);

        return $contents;
    }
}
