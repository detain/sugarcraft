<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Migration;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;

/**
 * Orchestrates a chain of {@see CassetteMigrator} instances to bring a
 * cassette to the current version or a specified target version.
 *
 * Migrators are tried in order from lowest to highest source version until
 * the target version is reached. If no migrator can handle a step, the
 * cassette is returned unchanged.
 */
final class MigrationRunner
{
    /** Safety guard against misconfigured migrators looping the chain forever. */
    private const MAX_ITERATIONS = 10;

    /** @var list<CassetteMigrator> */
    private array $migrators;

    /**
     * @param list<CassetteMigrator>|null $migrators Custom chain; null selects the built-in default set.
     */
    public function __construct(?array $migrators = null)
    {
        $this->migrators = $migrators ?? [
            new V1ToV2Migrator(),
        ];
    }

    /**
     * Migrate a cassette to the current version (highest target version
     * registered) or up to (but not past) $maxVersion if specified.
     *
     * @param Cassette $cassette The cassette to migrate.
     * @param bool $dryRun Perform validation without persisting changes.
     * @param int|null $maxVersion Optional ceiling on target version.
     * @return Cassette The (possibly unchanged) migrated cassette.
     */
    public function migrate(Cassette $cassette, bool $dryRun = false, ?int $maxVersion = null): Cassette
    {
        return $this->migrateWithStatus($cassette, $dryRun, $maxVersion)['cassette'];
    }

    /**
     * Migrate and report whether the chain actually reached its cap.
     *
     * A chain that stops early — no migrator handles the next step, or the
     * iteration guard trips — used to look like a complete migration from
     * the caller's seat (finding 118). `truncated` makes the difference
     * observable: the result still returned "a migrated cassette", which
     * is true but not the whole truth.
     *
     * @return array{cassette: Cassette, truncated: bool}
     */
    public function migrateWithStatus(Cassette $cassette, bool $dryRun = false, ?int $maxVersion = null): array
    {
        $current = $cassette;
        $iterations = 0;

        while ($iterations < self::MAX_ITERATIONS) {
            $found = false;
            foreach ($this->migrators as $migrator) {
                if ($migrator->canMigrate($current)) {
                    if ($maxVersion !== null && $migrator->targetVersion() > $maxVersion) {
                        continue;
                    }
                    $current = $migrator->migrate($current, $dryRun);
                    $found = true;
                    break; // restart chain from lowest migrator after each step
                }
            }
            if (!$found) {
                break;
            }
            $iterations++;
        }

        $cap = $maxVersion ?? $this->highestTargetVersion();
        // Truncated = the result sits below the cap it was aimed at: a
        // missing step in the chain, or the iteration guard tripping mid
        // climb. A cassette that lands exactly on the cap (or starts above
        // it — nothing to do) is complete, and an explicit maxVersion the
        // chain reaches is a deliberate ceiling, not a stall.
        $truncated = $current->header->version < $cap;

        return ['cassette' => $current, 'truncated' => $truncated];
    }

    /**
     * Highest target version any registered migrator reaches — the cap
     * this build can migrate TO (finding 093: callers must be able to say
     * "this build migrates up to v{max}" instead of guessing "latest").
     */
    public function highestTargetVersion(): int
    {
        $max = 0;
        foreach ($this->migrators as $migrator) {
            $max = max($max, $migrator->targetVersion());
        }
        return $max;
    }

    /**
     * Returns true if the given cassette can be migrated further.
     */
    public function canMigrate(Cassette $cassette): bool
    {
        foreach ($this->migrators as $migrator) {
            if ($migrator->canMigrate($cassette)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a description of all registered migrators.
     *
     * @return list<array{source: int, target: int, description: string}>
     */
    public function describeMigrators(): array
    {
        return array_map(
            fn (CassetteMigrator $m) => [
                'source' => $m->sourceVersion(),
                'target' => $m->targetVersion(),
                'description' => $m->describe(),
            ],
            $this->migrators,
        );
    }

    /**
     * Register a new migrator. Must be added in source-version order.
     */
    public function register(CassetteMigrator $migrator): void
    {
        $this->migrators[] = $migrator;
        usort($this->migrators, fn ($a, $b) => $a->sourceVersion() <=> $b->sourceVersion());
    }
}
