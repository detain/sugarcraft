<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Support;

/**
 * The quote-aware trailing-comment strip shared by both config readers.
 *
 * Single home for the byte-identical private copies that Config and
 * SshConfigParser each carried (finding candy-ssh-launcher-065): every
 * F-18-class fix had to land twice in lockstep, and an earlier duplicated
 * `~` expansion already diverged once through exactly this shape.
 */
final class CommentStrip
{
    private function __construct()
    {
    }

    /**
     * Strip a trailing '#' comment from a config line — quote-aware (F-18).
     *
     * A '#' begins a comment only at line start or after whitespace AND
     * outside quotes. The previous blind regex truncated quoted values that
     * carried hashes (`description: "fix #1"`) and mangled commands
     * (`Command="echo # hi"`). Backslash escapes are honored inside double
     * quotes; single quotes follow YAML's doubled '' escape. This mirrors
     * the minimal comment rule of the documented YAML subset — it is NOT a
     * full YAML scanner.
     */
    public static function strip(string $line): string
    {
        $quote = null; // null | '"' | "'"
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $ch = $line[$i];
            if ($quote === '"') {
                if ($ch === '\\') {
                    $i++; // consume the escaped pair
                } elseif ($ch === '"') {
                    $quote = null;
                }
                continue;
            }
            if ($quote === "'") {
                if ($ch === "'") {
                    if ($i + 1 < $length && $line[$i + 1] === "'") {
                        $i++; // doubled '' — an escaped quote, stays inside
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($ch === '#' && ($i === 0 || $line[$i - 1] === ' ' || $line[$i - 1] === "\t")) {
                return rtrim(substr($line, 0, $i));
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            }
        }
        return rtrim($line);
    }
}
