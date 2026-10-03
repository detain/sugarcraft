<?php

declare(strict_types=1);

/**
 * Child process for DefaultThemeTest: closes STDOUT, then renders every
 * no-theme presenter fallback (DefaultThemeTest::presenters()) and the
 * Theme::plain() equivalent.
 *
 * STDOUT is gone, so the result goes to the file named by argv[1] as JSON:
 * {"ok": true, "rendered": {...}, "plain": {...}} or
 * {"ok": false, "error": "<class>: <message>"}.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Kit\Tests\DefaultThemeTest;
use SugarCraft\Kit\Theme;

$resultFile = $argv[1] ?? '';
if ($resultFile === '') {
    fwrite(STDERR, "usage: closed-stdout-probe.php <result-file>\n");
    exit(2);
}

fclose(STDOUT);

// The same 14 entry points DefaultThemeTest covers in-process.
$renderAll = static function (?Theme $t): array {
    $out = [];
    foreach (DefaultThemeTest::presenters() as $name => [$render]) {
        $out[$name] = $render($t);
    }
    return $out;
};

try {
    $result = ['ok' => true, 'rendered' => $renderAll(null), 'plain' => $renderAll(Theme::plain())];
} catch (\Throwable $e) {
    $result = ['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()];
}

file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR));
