<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

/**
 * Single funnel for library diagnostics on the error stream.
 *
 * The CLI paths used to write straight to the STDERR constant. That is a
 * CLI-SAPI-only symbol: under an embedded runner (cgi, fpm, or a harness
 * that bootstraps Application outside a CLI process) a bare
 * `fwrite(STDERR, ...)` fatals at constant resolution before a single
 * diagnostic byte is emitted — and {@see \SugarCraft\ScriptUi\Application}
 * is an advertised embed surface (review 2026-11-13, finding
 * candy-scriptui-100).
 *
 * `php://stderr` is resolved by name, works under every SAPI (cli writes
 * to fd 2; cgi/fpm route through the SAPI error log), and the fallback
 * keeps the message on the error channel even if the wrapper stream cannot
 * be opened at all.
 */
final class Diagnostics
{
    /** Write bytes verbatim to the error stream — no newline is added. */
    public static function emit(string $bytes): void
    {
        $handle = @fopen('php://stderr', 'wb');
        if ($handle === false) {
            error_log(rtrim($bytes, "\n"));
            return;
        }

        fwrite($handle, $bytes);
        fclose($handle);
    }
}
