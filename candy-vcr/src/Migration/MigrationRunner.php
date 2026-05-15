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
    /** @var list<CassetteMigrator> */
    private array $migrators;

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
        $current = $cassette;
        $iterations = 0;
        $maxIterations = 10; // safety guard against misconfigured migrators

        while ($iterations < $maxIterations) {
            $found = false;
            foreach ($this->migrators as $migrator) {
                if ($migrator->canMigrate($current)) {
                    if ($maxVersion !== null && $migrator->getTargetVersion() > $maxVersion) {
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

        return $current;
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
            fn(CassetteMigrator $m) => [
                'source' => $m->getSourceVersion(),
                'target' => $m->getTargetVersion(),
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
        usort($this->migrators, fn($a, $b) => $a->getSourceVersion() <=> $b->getSourceVersion());
    }
}
