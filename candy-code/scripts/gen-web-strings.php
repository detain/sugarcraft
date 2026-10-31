<?php

declare(strict_types=1);

/**
 * scripts/gen-web-strings.php — regenerates the English chrome bundle the
 * web UI's i18n is built from (C-TUI-6.f):
 *
 *   docs/protocol/web-strings.en.json   the `web.`-prefixed keys of
 *                                       lang/en.php, sorted and pretty
 *
 * It comes from SugarCraft\Code\Protocol\WebStrings::englishBundleJson(),
 * whose single derivation WebStringsDriftTest enforces. Never hand-edit —
 * change the catalogue and re-run this; the web side mirrors these bytes
 * into src-web/lib/strings.en.generated.ts via its own gen-web-strings.mjs.
 *
 * Usage (from candy-code/ or anywhere):
 *   php scripts/gen-web-strings.php --write   Rewrite whatever is stale.
 *   php scripts/gen-web-strings.php --check   Exit 1 naming each stale file; write nothing.
 *   php scripts/gen-web-strings.php --help    This text.
 */

use SugarCraft\Code\Protocol\WebStrings;

$package = dirname(__DIR__);
require $package . '/vendor/autoload.php';

$mode = $argv[1] ?? '--help';
$file = 'docs/protocol/web-strings.en.json';
$wanted = WebStrings::englishBundleJson();

$current = @file_get_contents($package . '/' . $file);

switch ($mode) {
    case '--write':
        if ($current === $wanted) {
            fwrite(STDOUT, "protocol web strings: unchanged {$file}\n");
            exit(0);
        }
        if (@file_put_contents($package . '/' . $file, $wanted) === false) {
            fwrite(STDERR, "protocol web strings: cannot write {$file}\n");
            exit(2);
        }
        fwrite(STDOUT, "protocol web strings: wrote {$file}\n");
        exit(0);

    case '--check':
        if ($current === $wanted) {
            exit(0);
        }
        fwrite(STDERR, "protocol web strings: stale {$file} — run php scripts/gen-web-strings.php --write\n");
        exit(1);

    case '--help':
        fwrite(STDOUT, "usage: php scripts/gen-web-strings.php --write|--check|--help\n");
        exit(0);

    default:
        fwrite(STDERR, "unknown option {$mode}; usage: php scripts/gen-web-strings.php --write|--check|--help\n");
        exit(2);
}
