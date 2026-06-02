<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

/**
 * Generates SQL statements to commit Performance Schema configuration changes.
 *
 * Each setup model tracks its own dirty state. This planner extracts the
 * necessary SQL statements to persist those changes to the database.
 *
 * Supported models:
 *   - SetupInstruments: Uses RLIKE for prefix-based bucket updates
 *   - SetupConsumers: Uses IN(...) for consumer updates
 *   - SetupActors: Uses INSERT/UPDATE/DELETE keyed by HOST, USER, ROLE
 *   - SetupObjects: Uses INSERT/UPDATE/DELETE keyed by OBJECT_TYPE, OBJECT_SCHEMA, OBJECT_NAME
 *   - SetupThreads: Read-only, no statements generated
 *   - SetupTimers: Read-only, no statements generated
 *
 * Error handling for MySQL errors:
 *   - 1142 = SELECT/INSERT/UPDATE/DELETE denied
 *   - 1227 = access denied
 *   - 1146 = table doesn't exist (PS not enabled)
 *   - 2002 = connection refused
 *   - 2003 = connection timeout
 *   - 2013 = lost connection
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema commit_statements
 */
final class CommitPlanner
{
    /**
     * @param list<SetupInstruments> $instruments
     * @param list<SetupConsumers> $consumers
     * @param list<SetupActors> $actors
     * @param list<SetupObjects> $objects
     */
    public function __construct(
        private readonly array $instruments = [],
        private readonly array $consumers = [],
        private readonly array $actors = [],
        private readonly array $objects = [],
    ) {}

    /**
     * Factory method to create a new CommitPlanner.
     */
    public static function new(
        array $instruments = [],
        array $consumers = [],
        array $actors = [],
        array $objects = [],
    ): self {
        return new self($instruments, $consumers, $actors, $objects);
    }

    /**
     * Generate SQL statements to commit all changes.
     *
     * @return list<string> SQL statements to execute
     */
    public function commitAll(): array
    {
        $statements = [];

        $statements = array_merge($statements, $this->commitInstruments());
        $statements = array_merge($statements, $this->commitConsumers());
        $statements = array_merge($statements, $this->commitActors());
        $statements = array_merge($statements, $this->commitObjects());

        return $statements;
    }

    /**
     * Generate SQL statements for instrument changes.
     *
     * Groups instruments by prefix and uses RLIKE for efficient batch updates.
     * Only generates statements for instruments that have been modified.
     *
     * @return list<string> SQL statements
     */
    public function commitInstruments(): array
    {
        $statements = [];

        // Collect dirty instruments
        $dirty = [];
        foreach ($this->instruments as $instrument) {
            if ($instrument->isDirty()) {
                $dirty[] = $instrument;
            }
        }

        if ($dirty === []) {
            return [];
        }

        // Group by prefix bucket (first path segment)
        $buckets = [];
        foreach ($dirty as $instrument) {
            $bucket = $this->getInstrumentBucket($instrument->name);
            if (!isset($buckets[$bucket])) {
                $buckets[$bucket] = ['enabled' => null, 'timed' => null, 'names' => []];
            }
            $buckets[$bucket]['names'][] = $instrument->name;
            // Track the values - all instruments in a bucket share same enabled/timed in practice
            // but we aggregate them for the UPDATE statement
            $buckets[$bucket]['enabled'] = $instrument->enabled;
            $buckets[$bucket]['timed'] = $instrument->timed;
        }

        // Generate UPDATE statements per bucket using RLIKE
        foreach ($buckets as $bucket => $data) {
            if ($data['names'] === []) {
                continue;
            }

            // Create a pattern matching all names in this bucket
            // Since we use RLIKE, we need a pattern that matches all instruments in the bucket
            // We use a prefix pattern like "^(wait/|statement/|etc)"
            $pattern = '^(' . implode('|', array_map(
                fn(string $n) => preg_quote($n, '/'),
                $data['names']
            )) . ')';

            // For simplicity, generate one statement per instrument name using RLIKE
            // This is less efficient but more precise
            foreach ($data['names'] as $name) {
                // Find the original instrument to get its target values
                $targetEnabled = null;
                $targetTimed = null;
                foreach ($dirty as $di) {
                    if ($di->name === $name) {
                        $targetEnabled = $di->enabled;
                        $targetTimed = $di->timed;
                        break;
                    }
                }

                if ($targetEnabled !== null && $targetTimed !== null) {
                    $statements[] = sprintf(
                        'UPDATE `performance_schema`.`setup_instruments` SET `ENABLED` = %s, `TIMED` = %s WHERE `NAME` RLIKE %s',
                        $targetEnabled ? "'YES'" : "'NO'",
                        $targetTimed ? "'YES'" : "'NO'",
                        $this->quote($name)
                    );
                }
            }
        }

        return $statements;
    }

    /**
     * Generate SQL statements for consumer changes.
     *
     * Uses IN(...) clause for batch updates.
     *
     * @return list<string> SQL statements
     */
    public function commitConsumers(): array
    {
        $statements = [];

        // Collect dirty consumers
        $dirty = [];
        foreach ($this->consumers as $consumer) {
            if ($consumer->isDirty()) {
                $dirty[] = $consumer;
            }
        }

        if ($dirty === []) {
            return [];
        }

        // Group by enabled state
        $groups = ['YES' => [], 'NO' => []];
        foreach ($dirty as $consumer) {
            $groups[$consumer->enabled ? 'YES' : 'NO'][] = $consumer->name;
        }

        // Generate UPDATE statements per group
        foreach ($groups as $enabled => $names) {
            if ($names === []) {
                continue;
            }

            $quoted = array_map(fn(string $n) => $this->quote($n), $names);
            $statements[] = sprintf(
                'UPDATE `performance_schema`.`setup_consumers` SET `ENABLED` = %s WHERE `NAME` IN (%s)',
                $enabled === 'YES' ? "'YES'" : "'NO'",
                implode(', ', $quoted)
            );
        }

        return $statements;
    }

    /**
     * Generate SQL statements for actor changes.
     *
     * Uses INSERT/UPDATE/DELETE based on the type of change.
     *
     * @return list<string> SQL statements
     */
    public function commitActors(): array
    {
        $statements = [];

        foreach ($this->actors as $actor) {
            $changeType = $actor->getChangeType();

            switch ($changeType) {
                case 'insert':
                    $statements[] = sprintf(
                        'INSERT INTO `performance_schema`.`setup_actors` (`HOST`, `USER`, `ROLE`, `ENABLED`) VALUES (%s, %s, %s, %s)',
                        $this->quote($actor->host),
                        $this->quote($actor->user),
                        $this->quote($actor->role),
                        $actor->enabled ? "'YES'" : "'NO'"
                    );
                    break;

                case 'update':
                    $statements[] = sprintf(
                        'UPDATE `performance_schema`.`setup_actors` SET `ENABLED` = %s WHERE `HOST` = %s AND `USER` = %s AND `ROLE` = %s',
                        $actor->enabled ? "'YES'" : "'NO'",
                        $this->quote($actor->host),
                        $this->quote($actor->user),
                        $this->quote($actor->role)
                    );
                    break;

                case 'delete':
                    $statements[] = sprintf(
                        'DELETE FROM `performance_schema`.`setup_actors` WHERE `HOST` = %s AND `USER` = %s AND `ROLE` = %s',
                        $this->quote($actor->host),
                        $this->quote($actor->user),
                        $this->quote($actor->role)
                    );
                    break;

                // case 'none' - no change, skip
            }

        }

        return $statements;
    }

    /**
     * Generate SQL statements for object changes.
     *
     * Uses INSERT/UPDATE/DELETE based on the type of change.
     *
     * @return list<string> SQL statements
     */
    public function commitObjects(): array
    {
        $statements = [];

        foreach ($this->objects as $object) {
            $changeType = $object->getChangeType();

            switch ($changeType) {
                case 'insert':
                    $statements[] = sprintf(
                        'INSERT INTO `performance_schema`.`setup_objects` (`OBJECT_TYPE`, `OBJECT_SCHEMA`, `OBJECT_NAME`, `ENABLED`, `TIMED`) VALUES (%s, %s, %s, %s, %s)',
                        $this->quote($object->objectType),
                        $this->quote($object->objectSchema),
                        $this->quote($object->objectName),
                        $object->enabled ? "'YES'" : "'NO'",
                        $object->timed ? "'YES'" : "'NO'"
                    );
                    break;

                case 'update':
                    $statements[] = sprintf(
                        'UPDATE `performance_schema`.`setup_objects` SET `ENABLED` = %s, `TIMED` = %s WHERE `OBJECT_TYPE` = %s AND `OBJECT_SCHEMA` = %s AND `OBJECT_NAME` = %s',
                        $object->enabled ? "'YES'" : "'NO'",
                        $object->timed ? "'YES'" : "'NO'",
                        $this->quote($object->objectType),
                        $this->quote($object->objectSchema),
                        $this->quote($object->objectName)
                    );
                    break;

                case 'delete':
                    $statements[] = sprintf(
                        'DELETE FROM `performance_schema`.`setup_objects` WHERE `OBJECT_TYPE` = %s AND `OBJECT_SCHEMA` = %s AND `OBJECT_NAME` = %s',
                        $this->quote($object->objectType),
                        $this->quote($object->objectSchema),
                        $this->quote($object->objectName)
                    );
                    break;

                // case 'none' - no change, skip
            }

        }

        return $statements;
    }

    /**
     * Check if any tracked models have changes.
     */
    public function isDirty(): bool
    {
        foreach ($this->instruments as $instrument) {
            if ($instrument->isDirty()) {
                return true;
            }
        }

        foreach ($this->consumers as $consumer) {
            if ($consumer->isDirty()) {
                return true;
            }
        }

        foreach ($this->actors as $actor) {
            if ($actor->isDirty()) {
                return true;
            }
        }

        foreach ($this->objects as $object) {
            if ($object->isDirty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the instrument bucket (prefix category) for grouping.
     */
    private function getInstrumentBucket(string $name): string
    {
        $parts = explode('/', $name);
        return $parts[0] ?? '';
    }

    /**
     * Quote a string value for SQL.
     */
    private function quote(string $value): string
    {
        // Escape single quotes by doubling them
        $escaped = str_replace("'", "''", $value);
        return "'" . $escaped . "'";
    }
}
