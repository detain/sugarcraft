<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Support;

/**
 * The lib's single client-token neutralizer for the operator log (027).
 *
 * Two faces write client-claimed bytes into STDERR: the SSH server logs
 * claimed usernames, commands, and repo names, and the HTTP listener
 * logs the peer, method, and path straight out of the request line.
 * Both shapes arrived over the wire, so both need the same treatment —
 * keeping it private in SSHServer left the access log interpolating raw
 * bytes, where one embedded LF forges a complete additional entry.
 */
final class LogSafety
{
    /** Byte ceiling for one logged token: long enough for any real path
     *  segment or account name, short enough that a hostile flood cannot
     *  bloat a line past what an operator tail can absorb. */
    public const MAX_TOKEN_BYTES = 64;

    /**
     * Neutralize a client-claimed token for the operator log. The token
     * arrives straight from the peer: a CR/LF inside it forges additional
     * STDERR lines, and an unbounded one floods the log. Strip C0
     * controls and DEL, collapse whitespace runs, and clip. Identity on
     * any legitimate name — lookups and dispatch still run on the RAW
     * token, so sanitizing here cannot alias a real account or rewrite
     * which route was served.
     */
    public static function token(string $token): string
    {
        $clean = \preg_replace('/[\x00-\x1F\x7F]+/', ' ', $token) ?? '';
        $clean = \trim($clean);
        if (\strlen($clean) > self::MAX_TOKEN_BYTES) {
            // Byte clip, no marker: ext-mbstring is not a declared
            // dependency, and appending an ellipsis could split a
            // multi-byte sequence already inside the kept prefix.
            $clean = \substr($clean, 0, self::MAX_TOKEN_BYTES);
        }

        return $clean === '' ? '<empty>' : $clean;
    }
}
