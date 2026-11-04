<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Migration\MigrationRunner;

/**
 * `candy-vcr migrate <input.cas> [output.cas] [--dry-run]`
 *
 * Migrate a cassette to the current format version. If output.cas is
 * omitted the file is migrated in-place after creating a .bak backup.
 * With --dry-run the cassette is validated but no files are written.
 * Input is a JSONL cassette; the other formats the loader knows (cast,
 * YAML, tape, gzip) are refused rather than fed to the JSON parser.
 */
final class MigrateCommand implements DocumentedCommand
{
    private const BACKUP_SUFFIX = '.bak';

    public function summary(): string
    {
        return 'Migrate a cassette to the current format version';
    }

    public function usage(): string
    {
        return implode("\n", [
            'usage: candy-vcr migrate <input.cas> [output.cas] [--dry-run] [--info]',
            '  --dry-run  Validate migration without writing files',
            '  --info     Show registered migrators and exit',
            '  Input: JSONL cassettes only (.cas/.jsonl); .cast/.yaml/.tape/.gz inputs are refused',
        ]) . "\n";
    }

    public function run(array $args, $stdout, $stderr): int
    {
        $inputPath = null;
        $outputPath = null;
        $dryRun = false;
        $showInfo = false;

        foreach ($args as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
            } elseif ($arg === '--info') {
                $showInfo = true;
            } elseif (str_starts_with($arg, '--')) {
                fwrite($stderr, "candy-vcr migrate: unknown option {$arg}\n");
                return 2;
            } elseif ($inputPath === null) {
                $inputPath = $arg;
            } elseif ($outputPath === null) {
                $outputPath = $arg;
            } else {
                fwrite($stderr, "candy-vcr migrate: too many arguments\n");
                return 2;
            }
        }

        $runner = new MigrationRunner();

        if ($showInfo) {
            return $this->showInfo($runner, $stdout);
        }

        if ($inputPath === null) {
            fwrite($stderr, $this->usage());
            return 2;
        }

        // Validate input file is readable
        if (!is_readable($inputPath)) {
            fwrite($stderr, "candy-vcr migrate: cannot read {$inputPath}\n");
            return 1;
        }

        // The reader below is a plain JsonlFormat, so feeding it a cast,
        // YAML, tape or gzip file produced a raw JSON parse error that read
        // like a corrupt cassette. Name the file kind instead and refuse at
        // the usage door: migrate has exactly one supported input.
        $kind = self::refusedInputKind($inputPath);
        if ($kind !== null) {
            fwrite($stderr, "candy-vcr migrate: {$inputPath} is a {$kind} file; migrate reads JSONL cassettes (.cas/.jsonl) only\n");
            return 2;
        }

        try {
            $format = new JsonlFormat();
            $cassette = $format->read($inputPath);
        } catch (\Throwable $e) {
            fwrite($stderr, "candy-vcr migrate: {$e->getMessage()}\n");
            return 1;
        }

        $originalVersion = $cassette->header->version;

        if (!$runner->canMigrate($cassette)) {
            fwrite($stdout, "cassette is already at the latest format version (v{$originalVersion})\n");
            return 0;
        }

        $migrated = $runner->migrate($cassette, $dryRun);
        $targetVersion = $migrated->header->version;

        if ($dryRun) {
            fwrite($stdout, "dry-run: would migrate v{$originalVersion} → v{$targetVersion}\n");
            foreach ($runner->describeMigrators() as $m) {
                if ($m['source'] >= $originalVersion && $m['target'] <= $targetVersion) {
                    fwrite($stdout, "  {$m['source']} → {$m['target']}: {$m['description']}\n");
                }
            }
            return 0;
        }

        // Symmetric honesty with the input door and `record --output`: the
        // writer emits plain JSONL bytes, so an explicit .gz target would be
        // an artifact gunzip refuses. Checked before the in-place backup so
        // the refusal has zero side effects.
        if ($outputPath !== null && str_ends_with(strtolower($outputPath), '.gz')) {
            fwrite($stderr, "candy-vcr migrate: output cannot be a .gz path; migrate writes plain JSONL\n");
            return 2;
        }

        // Determine output path
        if ($outputPath === null) {
            $outputPath = $inputPath;
            $backupPath = $inputPath . self::BACKUP_SUFFIX;

            // Backup original
            if (!@copy($inputPath, $backupPath)) {
                fwrite($stderr, "candy-vcr migrate: could not create backup at {$backupPath}\n");
                return 1;
            }
            fwrite($stdout, "backed up original to {$backupPath}\n");
        }

        try {
            $format->write($migrated, $outputPath);
        } catch (\Throwable $e) {
            fwrite($stderr, "candy-vcr migrate: could not write {$outputPath}: {$e->getMessage()}\n");
            return 1;
        }

        fwrite($stdout, "migrated v{$originalVersion} → v{$targetVersion} ({$cassette->eventCount()} events)\n");
        return 0;
    }

    /**
     * @param resource $stdout
     */
    private function showInfo(MigrationRunner $runner, $stdout): int
    {
        fwrite($stdout, "candy-vcr migration system\n\n");
        $descs = $runner->describeMigrators();
        if ($descs === []) {
            fwrite($stdout, "no migrators registered\n");
            return 0;
        }
        foreach ($descs as $d) {
            fwrite($stdout, "v{$d['source']} → v{$d['target']}: {$d['description']}\n");
        }
        return 0;
    }

    /**
     * Name the format a file extension promises, when that format is one
     * migrate does not read. Null means "extension gives no objection" —
     * bare and .cas/.jsonl paths still fall through to the content reader.
     */
    private static function refusedInputKind(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'cast' => 'asciinema cast',
            'yaml', 'yml' => 'YAML',
            'tape' => 'tape script',
            'gz' => 'gzip',
            default => null,
        };
    }
}
