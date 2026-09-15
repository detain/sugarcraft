<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests\Concerns;

/**
 * Captures what error_log() writes during a callback by pointing the
 * error_log ini at a temp file for exactly the duration of the action.
 *
 * Several decoders report ignored HTTP headers and SSRF advisories through
 * error_log rather than throwing — this proves the notice fires (and what it
 * does NOT contain, e.g. a signed URL's query) without racing the suite's own
 * error output. The ini is restored and the temp file removed even when the
 * action throws, so a failing assertion cannot leak the redirect into later
 * tests.
 */
trait CapturesErrorLog
{
    /**
     * Run $action with error_log() redirected to a temp file and return
     * everything that was logged while it ran.
     */
    private function captureErrorLog(callable $action): string
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'reellog');
        $previous = ini_set('error_log', $logFile);

        try {
            $action();

            return (string) file_get_contents($logFile);
        } finally {
            if ($previous === false) {
                ini_restore('error_log');
            } else {
                ini_set('error_log', $previous);
            }
            @unlink($logFile);
        }
    }
}
