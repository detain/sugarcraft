<?php

declare(strict_types=1);

namespace SugarCraft\Top\Cli;

use SugarCraft\Core\Util\Sanitize;

/**
 * The single stderr choke for every startup diagnostic.
 *
 * Pre-TUI streams have no Surface, so nothing else strips control characters
 * between a planted value and the user's terminal: ConfigReader warnings embed
 * the offending VALUE verbatim, and its token splitter refuses only `"`/CR/LF
 * — ESC and BEL ride straight through, so a copied-over btop.conf carrying
 * `graph_symbol = \x1b]0;pwned\x07` would write a live OSC (window title,
 * OSC 52 clipboard) that outlives the process (audit M6). Paths derived from
 * $HOME/$XDG_* are the same class: untrusted by definition. Every message —
 * config warnings, the m27 ConfigFile refusal, flag usages, the box-reset
 * notice — is therefore run through {@see Sanitize::untrustedForDisplay()}
 * here, in one place, behind the `candy-top: ` prefix the CLI law keeps.
 *
 * This is the sole sanitizer for the class; the autoload-probe and no-tty
 * refusal literals in bin/candy-top carry no external text and write raw on
 * purpose, pinned as the only carve-outs by the BinTest structural law and
 * behaviourally by tests/Cli/ReporterChokeTest.php (S-X11).
 */
final class Reporter
{
    /** Write one sanitized diagnostic line to stderr, prefixed with the program name. */
    public static function report(string $message): void
    {
        @\fwrite(STDERR, 'candy-top: ' . Sanitize::untrustedForDisplay($message) . "\n");
    }
}
