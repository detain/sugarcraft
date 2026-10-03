<?php

declare(strict_types=1);

/**
 * tools/gen-settings-doc.php — regenerates the settings documentation that is
 * derived from SugarCraft\Crush\Config\Settings\SettingsSchema.
 *
 * The schema owns the key tables; the pages own everything else. What this
 * rewrites is exactly what SettingsDocGenerator names: the marked
 * `<!-- settings…:begin -->` / `:end -->` blocks and the "Settings key" column
 * of docs/ENVIRONMENT.md's app-variable table. Never hand-edit those regions —
 * add or change a SettingDefinition and re-run this.
 *
 * Usage (from sugar-crush/ or anywhere):
 *   php tools/gen-settings-doc.php --write   Rewrite every stale page.
 *   php tools/gen-settings-doc.php --check   Exit 1 naming each stale page; write nothing.
 *   php tools/gen-settings-doc.php --help    This text.
 *
 * --check is what tests/Config/Settings/SettingsSchemaDocDriftTest enforces.
 */

use SugarCraft\Crush\Config\Settings\SettingsDocGenerator;

$package = dirname(__DIR__);
require $package . '/vendor/autoload.php';

$mode = $argv[1] ?? '--help';
$generator = SettingsDocGenerator::new();

$pages = [];
foreach ($generator->targets() as $file) {
    $text = @file_get_contents($package . '/' . $file);
    if ($text === false) {
        fwrite(STDERR, "settings docs: cannot read {$file}\n");
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
                fwrite(STDERR, "settings docs: cannot write {$file}\n");
                exit(2);
            }

            $written[] = $file;
        }

        fwrite(STDOUT, $written === [] ? "settings docs: up to date\n" : 'settings docs: rewrote ' . implode(', ', $written) . "\n");
        exit(0);
    case '--check':
        $stale = $generator->drift($pages);
        if ($stale === []) {
            fwrite(STDOUT, "settings docs: up to date\n");
            exit(0);
        }

        fwrite(STDERR, 'settings docs: stale ' . implode(', ', $stale) . " — run php tools/gen-settings-doc.php --write\n");
        exit(1);
    case '--help':
    case '-h':
        fwrite(STDOUT, "usage: php tools/gen-settings-doc.php --write|--check|--help\n");
        exit(0);
    default:
        fwrite(STDERR, "unknown option {$mode}; usage: php tools/gen-settings-doc.php --write|--check|--help\n");
        exit(2);
}
