<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Cli;

use SugarCraft\Kv\Import\JsonImporter;
use SugarCraft\Kv\Import\YamlImporter;
use SugarCraft\Kv\Lang;
use SugarCraft\Kv\Store;
use SugarCraft\Kv\Support\InputPath;

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
    /**
     * @param Store       $store receiving store
     * @param resource    $stdout sink for the success line (tests pass a
     *                    memory stream; real runs keep STDOUT)
     * @param resource    $stderr sink for every diagnostic (candy-kv-081:
     *                    writing to the bare constants bypassed PHPUnit's
     *                    buffering and leaked lines onto the runner's real
     *                    STDOUT/STDERR — the sinks are now injectable)
     */
    public function __construct(
        private readonly Store $store,
        private readonly mixed $stdout = \STDOUT,
        private readonly mixed $stderr = \STDERR,
    ) {
        if (!\is_resource($this->stdout) || !\is_resource($this->stderr)) {
            throw new \InvalidArgumentException('ImportCommand sinks must be writable stream resources.');
        }
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
            \fwrite($this->stderr, "Unknown import format: " . Store::sanitizeForTty($format) . ". Use 'json', 'yaml' or 'yml'.\n");
            return 2;
        }

        try {
            // kv-065: the validated importer IS the format decision — the
            // read/dispatch branches below carry zero format knowledge, so
            // adding a fourth format means touching the single match above,
            // not five coordinated edits that can drift apart. Both
            // importers expose importFromString/importFromFile; PHP's
            // duck-typing dispatches on the object we already built.
            if ($path === '-' || $path === '/dev/stdin') {
                $document = \file_get_contents('php://stdin');
                if ($document === false) {
                    \fwrite($this->stderr, "Failed to read from STDIN.\n");
                    return 1;
                }
                $count = $importer->importFromString($document, $atomic);
            } else {
                // kv-019: refuse wrapper URLs BEFORE file_exists — the stat
                // itself deserializes phar archive metadata inside us.
                InputPath::refuseStreamWrapper($path);
                if (!\file_exists($path)) {
                    // Sanitize-on-egress: the path is caller-chosen argv bytes.
                    \fwrite($this->stderr, "File not found: " . Store::sanitizeForTty($path) . "\n");
                    return 1;
                }
                $count = $importer->importFromFile($path, $atomic);
            }

            \fwrite($this->stdout, Lang::t($count === 1 ? 'cli.imported_one' : 'cli.imported_n', ['count' => $count]) . "\n");
            return 0;
        } catch (\Throwable $e) {
            // Sanitize-on-egress: importer failure texts name file-chosen keys
            // (JsonImporter 'Entry "%s" has a %s value', YamlDialect binary/base64
            // refusals) — attacker-chosen bytes must not reach the TTY raw.
            \fwrite($this->stderr, "Import failed: " . Store::sanitizeForTty($e->getMessage()) . "\n");
            return 1;
        }
    }
}
