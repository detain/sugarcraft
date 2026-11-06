<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Cli;

use SugarCraft\Kv\Import\JsonImporter;
use SugarCraft\Kv\Import\YamlImporter;
use SugarCraft\Kv\Lang;
use SugarCraft\Kv\Store;

/**
 * CLI command handler for `candy-kv import`.
 *
 * i18n scope (intentional): this class straddles both surfaces. The ONE
 * catalogued emitter is the success line — `Lang::t('cli.imported_one')` /
 * `Lang::t('cli.imported_n')` on stdout — joining the usage lines that
 * `bin/candy-kv` routes through `Lang::t('cli.usage_*')`. Everything else
 * stays plain English by design: the usage- and failure-class stderr
 * diagnostics (unknown format, failed STDIN read, missing file, and the
 * "Import failed:" refusal wrapper) are operator-facing command feedback,
 * not catalogued messages, and importer failure texts (RuntimeException
 * messages) are programmer-facing exception details. See
 * CALIBER_LEARNINGS.md "i18n scope".
 */
final class ImportCommand
{
    public function __construct(
        private readonly Store $store,
    ) {
    }

    /**
     * Run the import command.
     *
     * @param string $format 'json', 'yaml' or 'yml'
     * @param string $path   File path to import from (use '-' for STDIN)
     * @param bool   $atomic Whether to use an atomic transaction.
     * @return int Exit code (0 = success, 1 = failure, 2 = bad usage).
     */
    public function run(string $format, string $path, bool $atomic = true): int
    {
        $importer = match ($format) {
            'json' => new JsonImporter($this->store),
            'yaml', 'yml' => new YamlImporter($this->store),
            default => null,
        };

        if ($importer === null) {
            // Usage-class error: the format the caller asked for is not a format at all.
            // Sanitize-on-egress: $format is argv-chosen and may carry terminal escapes.
            \fwrite(STDERR, "Unknown import format: " . Store::sanitizeForTty($format) . ". Use 'json', 'yaml' or 'yml'.\n");
            return 2;
        }

        try {
            if ($path === '-' || $path === '/dev/stdin') {
                $json = \file_get_contents('php://stdin');
                if ($json === false) {
                    \fwrite(STDERR, "Failed to read from STDIN.\n");
                    return 1;
                }
                if ($format === 'yaml' || $format === 'yml') {
                    $count = (new YamlImporter($this->store))->importFromString($json, $atomic);
                } else {
                    $count = (new JsonImporter($this->store))->importFromString($json, $atomic);
                }
            } else {
                if (!\file_exists($path)) {
                    // Sanitize-on-egress: the path is caller-chosen argv bytes.
                    \fwrite(STDERR, "File not found: " . Store::sanitizeForTty($path) . "\n");
                    return 1;
                }
                if ($format === 'yaml' || $format === 'yml') {
                    $count = (new YamlImporter($this->store))->importFromFile($path, $atomic);
                } else {
                    $count = (new JsonImporter($this->store))->importFromFile($path, $atomic);
                }
            }

            \fwrite(STDOUT, Lang::t($count === 1 ? 'cli.imported_one' : 'cli.imported_n', ['count' => $count]) . "\n");
            return 0;
        } catch (\Throwable $e) {
            // Sanitize-on-egress: importer failure texts name file-chosen keys
            // (JsonImporter 'Entry "%s" has a %s value', YamlDialect binary/base64
            // refusals) — attacker-chosen bytes must not reach the TTY raw.
            \fwrite(STDERR, "Import failed: " . Store::sanitizeForTty($e->getMessage()) . "\n");
            return 1;
        }
    }
}
