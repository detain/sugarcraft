<?php

declare(strict_types=1);

/**
 * tools/gen-command-docs.php — regenerates the command documentation derived
 * from the spec files under src/Commands/Specs/ (DH-CMDS).
 *
 * What this rewrites is exactly what CommandDocGenerator names: the marked
 * `<!-- commands:<name>:begin -->` / `:end -->` blocks — README's slash roster
 * and docs/COMMANDS.md's built-in table. Never hand-edit those regions — add or
 * change a spec file and re-run this.
 *
 * Usage (from sugar-crush/ or anywhere):
 *   php tools/gen-command-docs.php --write   Rewrite every stale page.
 *   php tools/gen-command-docs.php --check   Exit 1 naming each stale page; write nothing.
 *   php tools/gen-command-docs.php --help    This text.
 *
 * --check is what tests/Commands/CommandDocsDriftTest enforces.
 */

use SugarCraft\Crush\Commands\Specs\CommandDocGenerator;

$package = dirname(__DIR__);
require $package . '/vendor/autoload.php';

$mode = $argv[1] ?? '--help';
$generator = CommandDocGenerator::new();

$pages = [];
foreach ($generator->targets() as $file) {
    $text = @file_get_contents($package . '/' . $file);
    if ($text === false) {
        fwrite(STDERR, "command docs: cannot read {$file}\n");
        exit(2);
    }

    $pages[$file] = $text;
}

switch ($mode) {
    case '--write':
        $written = [];
        foreach ($generator->rendered($pages) as $file => $text) {
            if ($text === $pages[$file]) {
                continue;
            }

            if (file_put_contents($package . '/' . $file, $text) === false) {
                fwrite(STDERR, "command docs: cannot write {$file}\n");
                exit(2);
            }

            $written[] = $file;
        }

        fwrite(STDOUT, $written === [] ? "command docs: up to date\n" : 'command docs: rewrote ' . implode(', ', $written) . "\n");
        exit(0);
    case '--check':
        $stale = $generator->drift($pages);
        if ($stale === []) {
            fwrite(STDOUT, "command docs: up to date\n");
            exit(0);
        }

        fwrite(STDERR, 'command docs: stale ' . implode(', ', $stale) . " — run php tools/gen-command-docs.php --write\n");
        exit(1);
    case '--help':
    case '-h':
        fwrite(STDOUT, "usage: php tools/gen-command-docs.php --write|--check|--help\n");
        exit(0);
    default:
        fwrite(STDERR, "unknown option {$mode}; usage: php tools/gen-command-docs.php --write|--check|--help\n");
        exit(2);
}
