<?php

declare(strict_types=1);

/**
 * scripts/gen-protocol-schema.php — regenerates what is derived from the
 * `sugarcrush.v1` protocol code (roadmap O-3c, Appendix O §6.11):
 *
 *   docs/protocol/sugarcrush.v1.schema.json   the JSON Schema of every method's
 *                                             params and result and every
 *                                             event's data
 *   docs/SERVER.md                            the marked method and event tables
 *
 * Both come from SugarCraft\Crush\Protocol\Schema\ProtocolSchema. Never
 * hand-edit them — change the method, its row in MethodSchemas / EventSchemas,
 * or the catalogue, and re-run this.
 *
 * Usage (from sugar-crush/ or anywhere):
 *   php scripts/gen-protocol-schema.php --write   Rewrite whatever is stale.
 *   php scripts/gen-protocol-schema.php --check   Exit 1 naming each stale file; write nothing.
 *   php scripts/gen-protocol-schema.php --help    This text.
 *
 * --check is what tests/Protocol/ProtocolSchemaDriftTest enforces.
 */

use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;

$package = dirname(__DIR__);
require $package . '/vendor/autoload.php';

$mode = $argv[1] ?? '--help';
$schema = ProtocolSchema::new();

$docPath = $package . '/' . ProtocolSchema::SERVER_DOC;
$doc = @file_get_contents($docPath);
if ($doc === false) {
    fwrite(STDERR, 'protocol schema: cannot read ' . ProtocolSchema::SERVER_DOC . "\n");
    exit(2);
}

try {
    $wanted = [
        ProtocolSchema::SCHEMA_FILE => $schema->json(),
        ProtocolSchema::SERVER_DOC => $schema->renderServerDoc($doc),
    ];
} catch (\Throwable $e) {
    fwrite(STDERR, 'protocol schema: ' . $e->getMessage() . "\n");
    exit(2);
}

$stale = [];
foreach ($wanted as $file => $text) {
    $current = @file_get_contents($package . '/' . $file);
    if ($current !== $text) {
        $stale[] = $file;
    }
}

switch ($mode) {
    case '--write':
        foreach ($stale as $file) {
            $path = $package . '/' . $file;
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o755, true) && !is_dir(dirname($path))) {
                fwrite(STDERR, "protocol schema: cannot create the directory of {$file}\n");
                exit(2);
            }
            if (file_put_contents($path, $wanted[$file]) === false) {
                fwrite(STDERR, "protocol schema: cannot write {$file}\n");
                exit(2);
            }
        }
        fwrite(STDOUT, $stale === [] ? "protocol schema: up to date\n" : 'protocol schema: rewrote ' . implode(', ', $stale) . "\n");
        exit(0);
    case '--check':
        if ($stale === []) {
            fwrite(STDOUT, "protocol schema: up to date\n");
            exit(0);
        }
        fwrite(STDERR, 'protocol schema: stale ' . implode(', ', $stale) . " — run php scripts/gen-protocol-schema.php --write\n");
        exit(1);
    case '--help':
    case '-h':
        fwrite(STDOUT, "usage: php scripts/gen-protocol-schema.php --write|--check|--help\n");
        exit(0);
    default:
        fwrite(STDERR, "unknown option {$mode}; usage: php scripts/gen-protocol-schema.php --write|--check|--help\n");
        exit(2);
}
