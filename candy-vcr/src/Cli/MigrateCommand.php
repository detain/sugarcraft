<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Lang;
use SugarCraft\Vcr\Migration\MigrationRunner;
use SugarCraft\Vcr\Migration\V1ToV2Migrator;

/**
 * `candy-vcr migrate <input.cas> [output.cas] [--dry-run] [--force]`
 *
 * Migrate a cassette to the current format version. If output.cas is
 * omitted the file is migrated in-place after creating a .bak backup.
 * Naming output.cas explicitly never creates a backup — so an output
 * that REALPATHS to the input is folded back into the in-place form
 * (same door, same backup), and an existing distinct output is refused
 * unless --force names the destruction (finding 058).
 * With --dry-run the cassette is validated but no files are written.
 * Input is a JSONL cassette; the other formats the loader knows (cast,
 * YAML, tape, gzip) are refused rather than fed to the JSON parser, and
 * relative-mode (`dt`) cassettes — which the loader happily reads via
 * RelativeFormat — are refused by name because the migrators only speak
 * the absolute-t wire (until the dt format decision lands).
 */
final class MigrateCommand implements DocumentedCommand
{
    private const BACKUP_SUFFIX = '.bak';

    public function summary(): string
    {
        return Lang::t('summary.migrate');
    }

    public function usage(): string
    {
        return implode("\n", [
            Lang::t('cli.usage_label') . ' candy-vcr migrate <input.cas> [output.cas] [--dry-run] [--force] [--info]',
            '  --dry-run  ' . Lang::t('migrate.help_dry_run'),
            '  --force    ' . Lang::t('migrate.help_force'),
            '  --info     ' . Lang::t('migrate.help_info'),
            '  ' . Lang::t('migrate.help_input'),
        ]) . "\n";
    }

    public function run(array $args, $stdout, $stderr): int
    {
        $inputPath = null;
        $outputPath = null;
        $dryRun = false;
        $force = false;
        $showInfo = false;

        foreach ($args as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
            } elseif ($arg === '--force') {
                $force = true;
            } elseif ($arg === '--info') {
                $showInfo = true;
            } elseif (str_starts_with($arg, '--')) {
                fwrite($stderr, Lang::t('cli.unknown_option', ['verb' => 'migrate', 'option' => $arg]) . "\n");
                return 2;
            } elseif ($inputPath === null) {
                $inputPath = $arg;
            } elseif ($outputPath === null) {
                $outputPath = $arg;
            } else {
                fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => Lang::t('migrate.too_many')]) . "\n");
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
            fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => Lang::t('migrate.cannot_read', ['path' => $inputPath])]) . "\n");
            return 1;
        }

        // The reader below is a plain JsonlFormat, so feeding it a cast,
        // YAML, tape or gzip file produced a raw JSON parse error that read
        // like a corrupt cassette. Name the file kind instead and refuse at
        // the usage door: migrate has exactly one supported input.
        $kind = self::refusedInputKind($inputPath);
        if ($kind !== null) {
            fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => Lang::t('migrate.refused_kind', ['path' => $inputPath, 'kind' => $kind])]) . "\n");
            return 2;
        }

        // Relative-mode (`dt`) cassettes are the loader's RelativeFormat
        // family — perfectly valid files the plain JsonlFormat reader below
        // used to report as "missing 't' or 'k'", corruption wording for
        // healthy tape (finding 060). The migrators speak absolute wire, so
        // refuse the mode by name until the dt format decision ships.
        if (self::looksRelative($inputPath)) {
            fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => Lang::t('migrate.relative_unsupported')]) . "\n");
            return 2;
        }

        try {
            $format = new JsonlFormat();
            $cassette = $format->read($inputPath);
        } catch (\Throwable $e) {
            fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => $e->getMessage()]) . "\n");
            return 1;
        }

        $originalVersion = $cassette->header->version;

        // Reserved-key honesty (finding 108): below the v2 target every
        // payload `_id`/`_enc` the stamp overwrites is user data destroyed.
        // The overwrite is load-bearing for a valid v2 (skip would emit
        // non-sequential ids), so it stays — but it is named BEFORE the
        // dry-run branch, so even a validation-only pass hears about it.
        if ($originalVersion < V1ToV2Migrator::TARGET_VERSION) {
            $collisions = V1ToV2Migrator::reservedKeyCollisions($cassette);
            if ($collisions > 0) {
                fwrite($stderr, Lang::t('migrate.reserved_keys', ['count' => $collisions]) . "\n");
            }
        }

        if (!$runner->canMigrate($cassette)) {
            // Wording truth (finding 093): reaching this door means the
            // cassette passed the header's read-cap, so its version is at
            // or below what this build knows; with the default chain the
            // only no-step state IS the cap itself (v2), making "already
            // at the latest" the true statement. A cassette NEWER than the
            // build is refused one layer up by CassetteHeader's ctor with
            // a named "newer than this build can read" error - the cap
            // transparency this finding asked for already lives there,
            // pinned by MigrateCommandTest.
            fwrite($stdout, Lang::t('migrate.already_latest', ['version' => $originalVersion]) . "\n");
            return 0;
        }

        $result = $runner->migrateWithStatus($cassette, $dryRun);
        $migrated = $result['cassette'];
        $truncated = $result['truncated'];
        $targetVersion = $migrated->header->version;

        if ($dryRun) {
            fwrite($stdout, Lang::t('migrate.dry_run', ['from' => $originalVersion, 'to' => $targetVersion]) . "\n");
            foreach ($runner->describeMigrators() as $m) {
                if ($m['source'] >= $originalVersion && $m['target'] <= $targetVersion) {
                    fwrite($stdout, "  {$m['source']} → {$m['target']}: {$m['description']}\n");
                }
            }
            if ($truncated) {
                fwrite($stderr, Lang::t('migrate.truncated', ['to' => $targetVersion]) . "\n");
            }
            return 0;
        }

        // Symmetric honesty with the input door and `record --output`: the
        // writer emits plain JSONL bytes, so an explicit .gz target would be
        // an artifact gunzip refuses. Checked before the in-place backup so
        // the refusal has zero side effects.
        if ($outputPath !== null && str_ends_with(strtolower($outputPath), '.gz')) {
            fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => Lang::t('migrate.output_gz')]) . "\n");
            return 2;
        }

        // Same-path door (finding 058): `migrate a.cas a.cas` is an in-place
        // migrate wearing an explicit disguise — realpath() folding names the
        // match so both spellings (`./a.cas`, `a.cas`) take the backup door
        // below, never a clobber-without-backup.
        $samePath = $outputPath !== null
            && realpath($outputPath) !== false
            && realpath($outputPath) === realpath($inputPath);

        if ($outputPath !== null && !$samePath) {
            // Clobber gate (finding 058): an existing distinct output is
            // destroyed by the write, so it must be named — refused without
            // --force. The write lands through AtomicFileWriter's
            // entry-replacing rename, so even --force never writes through a
            // planted symlink at the output name (CWE-59 stays closed).
            if (file_exists($outputPath) && !$force) {
                fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => Lang::t('migrate.clobber', ['path' => $outputPath])]) . "\n");
                return 2;
            }
        } else {
            $outputPath = $inputPath;
            $backupPath = $inputPath . self::BACKUP_SUFFIX;

            // Backup door (findings 017/059). The pre-fix `@copy()` was
            // three findings deep: it clobbered any existing .bak (the
            // second migrate silently destroyed the only copy of the
            // pre-migration bytes), it followed a planted symlink at the
            // backup name (CWE-59), and it re-stamped the copy at the
            // ambient umask — a 0600 source produced a 0664 .bak holding
            // every keystroke of the session. An explicit is_link refusal
            // plus `fopen('xb')` exclusive creation close both halves
            // (see note below on why 'x' alone is not enough); chmod
            // lands the 0600 policy BEFORE any content, matching the
            // RestrictsCassettePermissions contract every format writer
            // already keeps; the bytes then stream-copy from the source.
            // is_link first, not just 'xb': PHP's fopen('x') probes with
            // stat(), which FOLLOWS symlinks — a dangling link stats as
            // "does not exist", so 'x' happily creates its target and
            // writes straight through the planted name. The link check
            // closes the hole 'x' leaves.
            if (is_link($backupPath)) {
                fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => Lang::t('migrate.backup_symlink', ['path' => $backupPath])]) . "\n");
                return 1;
            }
            $backupHandle = @fopen($backupPath, 'xb');
            if ($backupHandle === false) {
                $reason = file_exists($backupPath)
                    ? Lang::t('migrate.backup_exists', ['path' => $backupPath])
                    : Lang::t('migrate.backup_create_failed', ['path' => $backupPath]);
                fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => $reason]) . "\n");
                return 1;
            }
            if (!@chmod($backupPath, 0600)) {
                fclose($backupHandle);
                @unlink($backupPath);
                fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => Lang::t('migrate.chmod_failed', ['path' => $backupPath])]) . "\n");
                return 1;
            }
            $sourceHandle = @fopen($inputPath, 'rb');
            if ($sourceHandle === false || @stream_copy_to_stream($sourceHandle, $backupHandle) === false) {
                if (is_resource($sourceHandle)) {
                    fclose($sourceHandle);
                }
                fclose($backupHandle);
                @unlink($backupPath);
                fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => Lang::t('migrate.copy_failed', ['input' => $inputPath, 'backup' => $backupPath])]) . "\n");
                return 1;
            }
            fclose($sourceHandle);
            fclose($backupHandle);
            fwrite($stdout, Lang::t('migrate.backed_up', ['path' => $backupPath]) . "\n");
        }

        try {
            $format->write($migrated, $outputPath);
        } catch (\Throwable $e) {
            fwrite($stderr, Lang::t('cli.err', ['verb' => 'migrate', 'message' => Lang::t('migrate.write_failed', ['path' => $outputPath, 'reason' => $e->getMessage()])]) . "\n");
            return 1;
        }

        fwrite($stdout, Lang::t('migrate.done', ['from' => $originalVersion, 'to' => $targetVersion, 'count' => $cassette->eventCount()]) . "\n");
        if ($truncated) {
            // Distinguishable half-work (finding 118): the output written
            // is NOT at the newest version the chain could have reached.
            fwrite($stderr, Lang::t('migrate.truncated', ['to' => $targetVersion]) . "\n");
        }
        return 0;
    }

    /**
     * The `"dt"` content sniff {@see \SugarCraft\Vcr\Format\CassetteLoader::loadJsonl()}
     * uses, mirrored at the migrate door: skip the header line, ask whether
     * the first event row carries a dt field. Unreadable input says no —
     * the reader below names that failure properly.
     */
    private static function looksRelative(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $sawHeader = false;
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                if (!$sawHeader) {
                    $sawHeader = true;
                    continue;
                }
                return str_contains($line, '"dt"');
            }
        } finally {
            fclose($handle);
        }
        return false;
    }

    /**
     * @param resource $stdout
     */
    private function showInfo(MigrationRunner $runner, $stdout): int
    {
        fwrite($stdout, Lang::t('migrate.info_title') . "\n\n");
        $descs = $runner->describeMigrators();
        if ($descs === []) {
            fwrite($stdout, Lang::t('migrate.info_none') . "\n");
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
            'cast' => Lang::t('migrate.kind_cast'),
            'yaml', 'yml' => 'YAML',
            'tape' => Lang::t('migrate.kind_tape'),
            'gz' => 'gzip',
            default => null,
        };
    }
}
