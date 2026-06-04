<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

/**
 * Generates SQL statements to commit Performance Schema configuration changes.
 *
 * Each setup model tracks its own dirty state. This planner extracts the
 * necessary SQL statements to persist those changes to the database.
 *
 * All generated statements are fully parameterized — no value interpolation.
 * Instruments use anchored, regex-escaped RLIKE patterns. Consumers use IN().
 * Actors and objects use keyed INSERT/UPDATE/DELETE with bound parameters.
 *
 * Supported models:
 *   - SetupInstruments: Uses anchored RLIKE (^name$) for exact-name matching
 *   - SetupConsumers: Uses IN(?) for batch updates
 *   - SetupActors: Uses INSERT/UPDATE/DELETE keyed by HOST, USER, ROLE
 *   - SetupObjects: Uses INSERT/UPDATE/DELETE keyed by OBJECT_TYPE, OBJECT_SCHEMA, OBJECT_NAME
 *   - SetupThreads: Contributes INSTRUMENTED flag fragments to batch UPDATE (STEP 5.1)
 *   - SetupTimers: Generates UPDATE for setup_timers on MySQL <8.0; read-only on >=8.0 (STEP 5.1)
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
     * Generate parameterized SQL statements to commit all changes.
     *
     * Each returned array contains 'sql' (the parameterized SQL with ? placeholders)
     * and 'params' (the list of values to bind).
     *
     * @return list<array{sql: string, params: list<mixed>}> Parameterized statements
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
     * Generate parameterized SQL statements for instrument changes.
     *
     * Uses anchored RLIKE (^name$) to match exact instrument names.
     * The name is regex-escaped so metacharacters like . / ( ) are matched literally.
     *
     * @return list<array{sql: string, params: list<mixed>}> Parameterized statements
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

        // Group instruments by (enabled, timed) bucket
        // Each unique (enabled, timed) combination gets its own UPDATE
        $buckets = [];
        foreach ($dirty as $instrument) {
            $key = ($instrument->enabled ? '1' : '0') . '-' . ($instrument->timed ? '1' : '0');
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['enabled' => $instrument->enabled, 'timed' => $instrument->timed, 'names' => []];
            }
            $buckets[$key]['names'][] = $instrument->name;
        }

        // Generate one UPDATE per (enabled, timed) bucket
        foreach ($buckets as $bucket) {
            if ($bucket['names'] === []) {
                continue;
            }

            // Build pattern: ^name$ for single instrument, ^(name1|name2)$ for multiple
            // Each name is regex-escaped to handle metacharacters like . ( ) etc.
            // Forward slashes are NOT regex metacharacters and don't need escaping
            if (count($bucket['names']) === 1) {
                // Single instrument: just anchor it
                $pattern = '^' . preg_quote($bucket['names'][0]) . '$';
            } else {
                // Multiple instruments: alternation with capturing group
                $alternations = implode('|', array_map(
                    fn(string $n) => preg_quote($n),
                    $bucket['names']
                ));
                $pattern = '^(' . $alternations . ')$';
            }

            $statements[] = [
                'sql' => 'UPDATE `performance_schema`.`setup_instruments` SET `ENABLED` = ?, `TIMED` = ? WHERE `NAME` RLIKE ?',
                'params' => [
                    $bucket['enabled'] ? 'YES' : 'NO',
                    $bucket['timed'] ? 'YES' : 'NO',
                    $pattern,
                ],
            ];
        }

        return $statements;
    }

    /**
     * Generate parameterized SQL statements for consumer changes.
     *
     * Uses IN(?) clause for batch updates, all values bound as parameters.
     *
     * @return list<array{sql: string, params: list<mixed>}> Parameterized statements
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

        // Generate one UPDATE per enabled/disabled group, all names bound as params
        foreach ($groups as $enabled => $names) {
            if ($names === []) {
                continue;
            }

            // Build IN clause with ? placeholders, one per name
            $placeholders = implode(', ', array_fill(0, count($names), '?'));

            $statements[] = [
                'sql' => 'UPDATE `performance_schema`.`setup_consumers` SET `ENABLED` = ? WHERE `NAME` IN (' . $placeholders . ')',
                'params' => array_merge([$enabled === 'YES' ? 'YES' : 'NO'], $names),
            ];
        }

        return $statements;
    }

    /**
     * Generate parameterized SQL statements for actor changes.
     *
     * Uses INSERT/UPDATE/DELETE based on the type of change.
     * All string values are bound as parameters, not interpolated.
     *
     * @return list<array{sql: string, params: list<mixed>}> Parameterized statements
     */
    public function commitActors(): array
    {
        $statements = [];

        foreach ($this->actors as $actor) {
            $changeType = $actor->getChangeType();

            switch ($changeType) {
                case 'insert':
                    $statements[] = [
                        'sql' => 'INSERT INTO `performance_schema`.`setup_actors` (`HOST`, `USER`, `ROLE`, `ENABLED`) VALUES (?, ?, ?, ?)',
                        'params' => [
                            $actor->host,
                            $actor->user,
                            $actor->role,
                            $actor->enabled ? 'YES' : 'NO',
                        ],
                    ];
                    break;

                case 'update':
                    $statements[] = [
                        'sql' => 'UPDATE `performance_schema`.`setup_actors` SET `ENABLED` = ? WHERE `HOST` = ? AND `USER` = ? AND `ROLE` = ?',
                        'params' => [
                            $actor->enabled ? 'YES' : 'NO',
                            $actor->host,
                            $actor->user,
                            $actor->role,
                        ],
                    ];
                    break;

                case 'delete':
                    $statements[] = [
                        'sql' => 'DELETE FROM `performance_schema`.`setup_actors` WHERE `HOST` = ? AND `USER` = ? AND `ROLE` = ?',
                        'params' => [
                            $actor->host,
                            $actor->user,
                            $actor->role,
                        ],
                    ];
                    break;

                // case 'none' - no change, skip
            }

        }

        return $statements;
    }

    /**
     * Generate parameterized SQL statements for object changes.
     *
     * Uses INSERT/UPDATE/DELETE based on the type of change.
     * All string values are bound as parameters, not interpolated.
     *
     * @return list<array{sql: string, params: list<mixed>}> Parameterized statements
     */
    public function commitObjects(): array
    {
        $statements = [];

        foreach ($this->objects as $object) {
            $changeType = $object->getChangeType();

            switch ($changeType) {
                case 'insert':
                    $statements[] = [
                        'sql' => 'INSERT INTO `performance_schema`.`setup_objects` (`OBJECT_TYPE`, `OBJECT_SCHEMA`, `OBJECT_NAME`, `ENABLED`, `TIMED`) VALUES (?, ?, ?, ?, ?)',
                        'params' => [
                            $object->objectType,
                            $object->objectSchema,
                            $object->objectName,
                            $object->enabled ? 'YES' : 'NO',
                            $object->timed ? 'YES' : 'NO',
                        ],
                    ];
                    break;

                case 'update':
                    $statements[] = [
                        'sql' => 'UPDATE `performance_schema`.`setup_objects` SET `ENABLED` = ?, `TIMED` = ? WHERE `OBJECT_TYPE` = ? AND `OBJECT_SCHEMA` = ? AND `OBJECT_NAME` = ?',
                        'params' => [
                            $object->enabled ? 'YES' : 'NO',
                            $object->timed ? 'YES' : 'NO',
                            $object->objectType,
                            $object->objectSchema,
                            $object->objectName,
                        ],
                    ];
                    break;

                case 'delete':
                    $statements[] = [
                        'sql' => 'DELETE FROM `performance_schema`.`setup_objects` WHERE `OBJECT_TYPE` = ? AND `OBJECT_SCHEMA` = ? AND `OBJECT_NAME` = ?',
                        'params' => [
                            $object->objectType,
                            $object->objectSchema,
                            $object->objectName,
                        ],
                    ];
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
}
