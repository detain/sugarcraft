<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

/**
 * Performance Schema easy setup utility providing toggle statements and defaults.
 *
 * Provides SQL statements for enabling/disabling Performance Schema and
 * defines the default instrument/consumer sets recommended by MySQL documentation.
 *
 * Default instruments (stage/%, statement/%, wait/%):
 *   These categories are enabled by default in MySQL as they provide
 *   the most useful performance monitoring without excessive overhead.
 *
 * Default consumers:
 *   - events_statements_history
 *   - events_statements_history_long
 *   - events_waits_history
 *   - events_waits_history_long
 *
 * Note: memory/% instruments are excluded from standard toggle operations
 * as they require special handling due to their dynamic nature.
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema easy_setup
 */
final class EasySetup
{
    /**
     * Default instrument patterns enabled by MySQL.
     *
     * @return list<string> SQL LIKE patterns for default instruments
     */
    public const DEFAULT_INSTRUMENTS = [
        'stage/%',
        'statement/%',
        'wait/%',
    ];

    /**
     * Default consumers enabled by MySQL.
     *
     * @return list<string> Consumer names
     */
    public const DEFAULT_CONSUMERS = [
        'events_statements_history',
        'events_statements_history_long',
        'events_waits_history',
        'events_waits_history_long',
    ];

    /**
     * Factory method to create a new EasySetup instance.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Generate statements to enable Performance Schema.
     *
     * Enables all instruments and consumers to full/default setup.
     * Uses UPDATE statements with LIKE patterns for instruments.
     *
     * @return list<string> SQL statements to execute
     */
    public function enableStatements(): array
    {
        $statements = [];

        // Enable all instruments (except memory/%)
        $statements[] = <<<'SQL'
            UPDATE `performance_schema`.`setup_instruments`
            SET `ENABLED` = 'YES', `TIMED` = 'YES'
            WHERE `NAME` NOT LIKE 'memory/%'
            SQL;

        // Enable all consumers
        $statements[] = <<<'SQL'
            UPDATE `performance_schema`.`setup_consumers`
            SET `ENABLED` = 'YES'
            SQL;

        return $statements;
    }

    /**
     * Generate statements to disable Performance Schema.
     *
     * Disables all instruments and consumers.
     *
     * @return list<string> SQL statements to execute
     */
    public function disableStatements(): array
    {
        $statements = [];

        // Disable all instruments
        $statements[] = <<<'SQL'
            UPDATE `performance_schema`.`setup_instruments`
            SET `ENABLED` = 'NO', `TIMED` = 'NO'
            WHERE `NAME` NOT LIKE 'memory/%'
            SQL;

        // Disable all consumers
        $statements[] = <<<'SQL'
            UPDATE `performance_schema`.`setup_consumers`
            SET `ENABLED` = 'NO'
            SQL;

        return $statements;
    }

    /**
     * Generate statements to reset Performance Schema to defaults.
     *
     * Resets instruments to default MySQL setup (stage/%, statement/%, wait/%)
     * and enables default consumers.
     *
     * @return list<string> SQL statements to execute
     */
    public function resetToDefaultStatements(): array
    {
        $statements = [];

        // First disable all non-default instruments
        $statements[] = <<<'SQL'
            UPDATE `performance_schema`.`setup_instruments`
            SET `ENABLED` = 'NO', `TIMED` = 'NO'
            WHERE `NAME` NOT LIKE 'memory/%'
              AND `NAME` NOT LIKE 'stage/%'
              AND `NAME` NOT LIKE 'statement/%'
              AND `NAME` NOT LIKE 'wait/%'
            SQL;

        // Then enable default instruments
        foreach (self::DEFAULT_INSTRUMENTS as $pattern) {
            $escapedPattern = $this->escapePattern($pattern);
            $statements[] = sprintf(
                'UPDATE `performance_schema`.`setup_instruments` SET `ENABLED` = \'YES\', `TIMED` = \'YES\' WHERE `NAME` LIKE \'%s\' AND `NAME` NOT LIKE \'memory/%%\'',
                $escapedPattern
            );
        }

        // Reset consumers to defaults
        $statements[] = <<<'SQL'
            UPDATE `performance_schema`.`setup_consumers`
            SET `ENABLED` = 'NO'
            SQL;

        // Enable default consumers
        $quotedConsumers = array_map(
            fn(string $c) => '`' . str_replace('`', '``', $c) . '`',
            self::DEFAULT_CONSUMERS
        );
        $statements[] = sprintf(
            'UPDATE `performance_schema`.`setup_consumers` SET `ENABLED` = \'YES\' WHERE `NAME` IN (%s)',
            implode(', ', $quotedConsumers)
        );

        return $statements;
    }

    /**
     * Get the default instrument patterns.
     *
     * @return list<string> LIKE patterns for default instruments
     */
    public function defaultInstruments(): array
    {
        return self::DEFAULT_INSTRUMENTS;
    }

    /**
     * Get the default consumer names.
     *
     * @return list<string> Consumer names
     */
    public function defaultConsumers(): array
    {
        return self::DEFAULT_CONSUMERS;
    }

    /**
     * Escape a LIKE pattern for safe SQL inclusion.
     *
     * @param string $pattern The LIKE pattern to escape
     * @return string Escaped pattern safe for SQL
     */
    private function escapePattern(string $pattern): string
    {
        // Escape special LIKE characters
        return str_replace(
            ['%', '_', "'"],
            ["\\%", "\\_", "\\'"],
            $pattern
        );
    }
}
