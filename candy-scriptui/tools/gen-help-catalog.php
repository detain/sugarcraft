<?php

declare(strict_types=1);

/**
 * Derive the `help.*` rows of lang/en.php from the command classes
 * (scriptui-59). The English literal in each command is the source of truth;
 * this prints the catalog block for it.
 *
 *   php tools/gen-help-catalog.php            # print the en block
 *   php tools/gen-help-catalog.php --json     # key => English, as JSON
 *
 * HelpDriftTest re-derives the same rows and fails when lang/en.php differs,
 * so a new flag or description without a catalog row cannot ship.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ScriptUi\Help\HelpCatalog;

SugarCraft\Core\I18n\T::setLocale('en');
$rows = HelpCatalog::derive(new SugarCraft\ScriptUi\Application());

if (in_array('--json', $argv, true)) {
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

echo "    // Help surface (scriptui-59) — derived from the command classes by\n";
echo "    // tools/gen-help-catalog.php; HelpDriftTest pins it. Keep {placeholders}\n";
echo "    // and the {{VAR}} template token verbatim in translations.\n";
foreach ($rows as $key => $text) {
    echo '    ', var_export($key, true), ' => ', var_export($text, true), ",\n";
}
