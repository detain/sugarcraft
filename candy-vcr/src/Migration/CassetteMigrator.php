<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Migration;

use SugarCraft\Vcr\Cassette;

/**
 * Strategy interface for migrating a Cassette from one format version to
 * another. Implementations are discovered and wired by {@see MigrationRunner}.
 *
 * Design allows future versions (v2, v3, ...) to be added without modifying
 * the core migration infrastructure.
 */
interface CassetteMigrator
{
    /**
     * Returns true if this migrator can handle the given cassette.
     * Typically checks the header version.
     */
    public function canMigrate(Cassette $cassette): bool;

    /**
     * Migrate the cassette to the next version.
     *
     * @param Cassette $cassette The source cassette to migrate.
     * @param bool $dryRun If true, performs validation without persisting changes.
     * @return Cassette The migrated cassette.
     */
    public function migrate(Cassette $cassette, bool $dryRun = false): Cassette;

    /**
     * Get the source format version number this migrator reads.
     * Returns e.g. 1 for V1ToV2Migrator.
     */
    public function getSourceVersion(): int;

    /**
     * Get the target format version number this migrator writes.
     * Returns e.g. 2 for V1ToV2Migrator.
     */
    public function getTargetVersion(): int;

    /**
     * Get a human-readable description of what this migrator does.
     */
    public function describe(): string;
}
