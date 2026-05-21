<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Cli;

use SugarCraft\Skate\Import\JsonImporter;
use SugarCraft\Skate\Import\YamlImporter;
use SugarCraft\Skate\Store;

/**
 * CLI command handler for `skate import`.
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
     * @param string $format 'json' or 'yaml'
     * @param string $path   File path to import from (use '-' for STDIN)
     * @param bool   $atomic Whether to use an atomic transaction.
     * @return int Exit code (0 = success, 1 = failure).
     */
    public function run(string $format, string $path, bool $atomic = true): int
    {
        $importer = match ($format) {
            'json' => new JsonImporter($this->store),
            'yaml', 'yml' => new YamlImporter($this->store),
            default => null,
        };

        if ($importer === null) {
            \fwrite(STDERR, "Unknown import format: {$format}. Use 'json' or 'yaml'.\n");
            return 1;
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
                    \fwrite(STDERR, "File not found: {$path}\n");
                    return 1;
                }
                if ($format === 'yaml' || $format === 'yml') {
                    $count = (new YamlImporter($this->store))->importFromFile($path, $atomic);
                } else {
                    $count = (new JsonImporter($this->store))->importFromFile($path, $atomic);
                }
            }

            \fwrite(STDOUT, "Imported {$count} entries.\n");
            return 0;
        } catch (\Throwable $e) {
            \fwrite(STDERR, "Import failed: {$e->getMessage()}\n");
            return 1;
        }
    }
}
