<?php

declare(strict_types=1);

/**
 * tools/gen-tool-docs.php — regenerates the documentation derived from the
 * built-in tool catalog (SugarCraft\Crush\Tools\Catalog\ToolCatalog).
 *
 * Every class under src/Tools/BuiltIn/ declares its wire name, permission
 * class and wire position in a #[BuiltInTool] attribute. What this rewrites is
 * exactly what ToolDocGenerator names: the marked `<!-- tools:…:begin -->` /
 * `:end -->` blocks, the spelled tool counts at its count anchors, and the
 * fenced (disabledTools) launch-report samples. Never hand-edit those regions —
 * change a tool's declaration and re-run this.
 *
 * Usage (from sugar-crush/ or anywhere):
 *   php tools/gen-tool-docs.php --write   Rewrite every stale page.
 *   php tools/gen-tool-docs.php --check   Exit 1 naming each stale page; write nothing.
 *   php tools/gen-tool-docs.php --help    This text.
 *
 * --check is what tests/Tools/Catalog/ToolDocDriftTest enforces.
 */

use SugarCraft\Crush\Tools\Catalog\ToolDocGenerator;

$package = dirname(__DIR__);
require $package . '/vendor/autoload.php';

$mode = $argv[1] ?? '--help';
$generator = ToolDocGenerator::new();

$pages = [];
foreach ($generator->targets() as $file) {
    $text = @file_get_contents($package . '/' . $file);
    if ($text === false) {
        fwrite(STDERR, "tool docs: cannot read {$file}\n");
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
                fwrite(STDERR, "tool docs: cannot write {$file}\n");
                exit(2);
            }

            $written[] = $file;
        }

        fwrite(STDOUT, $written === [] ? "tool docs: up to date\n" : 'tool docs: rewrote ' . implode(', ', $written) . "\n");
        exit(0);
    case '--check':
        $stale = $generator->drift($pages);
        if ($stale === []) {
            fwrite(STDOUT, "tool docs: up to date\n");
            exit(0);
        }

        fwrite(STDERR, 'tool docs: stale ' . implode(', ', $stale) . " — run php tools/gen-tool-docs.php --write\n");
        exit(1);
    case '--help':
    case '-h':
        fwrite(STDOUT, "usage: php tools/gen-tool-docs.php --write|--check|--help\n");
        exit(0);
    default:
        fwrite(STDERR, "unknown option {$mode}; usage: php tools/gen-tool-docs.php --write|--check|--help\n");
        exit(2);
}
