<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Migration;

/**
 * Value object returned by {@see CassetteMigrator::migrate()} that carries
 * the migrated cassette plus metadata about what happened.
 */
final readonly class MigrationResult
{
    /**
     * @param bool $dryRun Whether this was a dry-run migration.
     * @param int $sourceVersion Original cassette format version.
     * @param int $targetVersion New cassette format version after migration.
     * @param list<string> $changes List of human-readable change descriptions.
     */
    public function __construct(
        public bool $dryRun,
        public int $sourceVersion,
        public int $targetVersion,
        public array $changes,
    ) {
    }
}
