<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Version;

/**
 * Performance Schema easy setup utility providing toggle statements and defaults.
 *
 * Provides SQL statements for enabling/disabling Performance Schema and
 * defines the default instrument/consumer sets recommended by MySQL documentation
 * (per Appendix C of the MySQL Workbench specification).
 *
 * Default instruments (MySQL 5.6 and 5.7 — same set):
 *   - wait/io/file/%                      : File I/O instruments
 *   - wait/io/table/%                     : Table I/O instruments
 *   - wait/lock/table/sql/handler         : Table lock instruments
 *   - statement/%                         : Statement instruments
 *   - idle                                : Idle instrument
 *
 * Default consumers (MySQL 5.6):
 *   - events_statements_current
 *   - events_transactions_current
 *   - global_instrumentation
 *   - thread_instrumentation
 *
 * Default consumers (MySQL 5.7+, adds):
 *   - statements_digest
 *
 * Note: memory/% instruments are excluded from all toggle operations
 * as they require special handling due to their dynamic nature.
 *
 * Version-aware: pass a Version to the constructor (or use fromContext()) and
 * the correct default set is returned automatically. Defaults to 5.6 profile
 * when no version is provided.
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema easy_setup
 * @see Appendix C: Default instrument/consumer profiles for MySQL 5.6 / 5.7
 */
final class EasySetup
{
    /**
     * Default instrument patterns for MySQL 5.6 (per Appendix C).
     *
     * @return list<string> SQL LIKE patterns for default instruments
     */
    public const DEFAULT_INSTRUMENTS_56 = [
        'wait/io/file/%',
        'wait/io/table/%',
        'wait/lock/table/sql/handler',
        'statement/%',
        'idle',
    ];

    /**
     * Default instrument patterns for MySQL 5.7+ (per Appendix C).
     * Same as 5.6 (stage/% was removed in 5.7 but not in our default list).
     *
     * @return list<string> SQL LIKE patterns for default instruments
     */
    public const DEFAULT_INSTRUMENTS_57 = [
        'wait/io/file/%',
        'wait/io/table/%',
        'wait/lock/table/sql/handler',
        'statement/%',
        'idle',
    ];

    /**
     * Default consumers for MySQL 5.6 (per Appendix C).
     *
     * @return list<string> Consumer names
     */
    public const DEFAULT_CONSUMERS_56 = [
        'events_statements_current',
        'events_transactions_current',
        'global_instrumentation',
        'thread_instrumentation',
    ];

    /**
     * Default consumers for MySQL 5.7+ (per Appendix C).
     * Adds statements_digest compared to 5.6.
     *
     * @return list<string> Consumer names
     */
    public const DEFAULT_CONSUMERS_57 = [
        'events_statements_current',
        'events_transactions_current',
        'global_instrumentation',
        'thread_instrumentation',
        'statements_digest',
    ];

    /**
     * Get default instruments for a given version.
     *
     * @return list<string>
     */
    public static function defaultInstrumentsForVersion(Version $version): array
    {
        return $version->isAtLeast(5, 7)
            ? self::DEFAULT_INSTRUMENTS_57
            : self::DEFAULT_INSTRUMENTS_56;
    }

    /**
     * Get default consumers for a given version.
     *
     * @return list<string>
     */
    public static function defaultConsumersForVersion(Version $version): array
    {
        return $version->isAtLeast(5, 7)
            ? self::DEFAULT_CONSUMERS_57
            : self::DEFAULT_CONSUMERS_56;
    }

    private readonly Version $version;

    /**
     * @param Version|null $version Server version (defaults to 5.6 profile if null)
     */
    public function __construct(?Version $version = null)
    {
        $this->version = $version ?? Version::parse('5.6.0');
    }

    /**
     * Factory method to create a new EasySetup instance.
     */
    public static function new(?Version $version = null): self
    {
        return new self($version);
    }

    /**
     * Factory method to create from a server context.
     */
    public static function fromContext(ServerContextInterface $context): self
    {
        return new self($context->version());
    }

    /**
     * Generate statements to enable Performance Schema (fully enabled).
     *
     * Enables all instruments and consumers to maximum setup.
     * Uses UPDATE statements with LIKE patterns for instruments.
     *
     * @return list<string> SQL statements to execute
     */
    public function enableStatements(): array
    {
        $statements = [];

        // Enable all instruments (except memory/%) with both ENABLED and TIMED
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
     * Generate statements to disable Performance Schema (fully disabled).
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
     * Resets instruments to the default MySQL setup for this version
     * and enables default consumers.
     *
     * @return list<string> SQL statements to execute
     */
    public function resetToDefaultStatements(): array
    {
        $statements = [];
        $defaultInstruments = $this->defaultInstruments();
        $defaultConsumers = $this->defaultConsumers();

        // First disable all non-default instruments
        $nonDefaultPatterns = array_map(
            fn(string $p) => sprintf("`NAME` NOT LIKE '%s'", str_replace(['%', '_'], ['\\%', '\\_'], $p)),
            $defaultInstruments
        );

        $statements[] = sprintf(
            "UPDATE `performance_schema`.`setup_instruments`\n            SET `ENABLED` = 'NO', `TIMED` = 'NO'\n            WHERE `NAME` NOT LIKE 'memory/%%'\n              AND %s",
            implode("\n              AND ", $nonDefaultPatterns)
        );

        // Then enable default instruments
        foreach ($defaultInstruments as $pattern) {
            $escapedPattern = str_replace(['%', '_'], ['\\%', '\\_'], $pattern);
            $statements[] = sprintf(
                "UPDATE `performance_schema`.`setup_instruments`\n            SET `ENABLED` = 'YES', `TIMED` = 'YES'\n            WHERE `NAME` LIKE '%s' AND `NAME` NOT LIKE 'memory/%%'",
                $escapedPattern
            );
        }

        // Reset consumers to defaults - first disable all
        $statements[] = <<<'SQL'
            UPDATE `performance_schema`.`setup_consumers`
            SET `ENABLED` = 'NO'
            SQL;

        // Then enable default consumers
        $quotedConsumers = array_map(
            fn(string $c) => '`' . str_replace('`', '``', $c) . '`',
            $defaultConsumers
        );
        $statements[] = sprintf(
            'UPDATE `performance_schema`.`setup_consumers` SET `ENABLED` = \'YES\' WHERE `NAME` IN (%s)',
            implode(', ', $quotedConsumers)
        );

        return $statements;
    }

    /**
     * Get the default instrument patterns for this version.
     *
     * @return list<string> LIKE patterns for default instruments
     */
    public function defaultInstruments(): array
    {
        return $this->version->isAtLeast(5, 7)
            ? self::DEFAULT_INSTRUMENTS_57
            : self::DEFAULT_INSTRUMENTS_56;
    }

    /**
     * Get the default consumer names for this version.
     *
     * @return list<string> Consumer names
     */
    public function defaultConsumers(): array
    {
        return $this->version->isAtLeast(5, 7)
            ? self::DEFAULT_CONSUMERS_57
            : self::DEFAULT_CONSUMERS_56;
    }
}
