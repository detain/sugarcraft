<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\PerfSchema;

use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\Identifier;
use SugarCraft\Sql\Db\ServerVersion;

/**
 * Detects the current Performance Schema setup state via COUNT/SUM queries.
 *
 * Determines whether PS is:
 *   - 'fully': All instruments enabled AND timed, all consumers enabled
 *   - 'default': Matches the MySQL default setup for the detected version
 *   - 'custom': User customized setup
 *   - 'disabled': Performance Schema is disabled or inaccessible
 *
 * Uses COUNT queries against setup_instruments and setup_consumers to determine state.
 * Excludes memory/% instruments from all calculations.
 *
 * Detection logic (per MySQL Workbench spec):
 *   - fully:    COUNT(setup_consumers WHERE enabled='NO') == 0
 *               AND COUNT(setup_instruments WHERE NAME NOT LIKE 'memory/%'
 *                   AND (enabled='NO' OR timed='NO')) == 0
 *               Additionally verifies enabledPercentage == 100% as a guard —
 *               a server with <100% instruments enabled cannot be 'fully'.
 *   - disabled: isDisabled() returns true — PS tables are inaccessible
 *               (caught by PDOException on query; does NOT use isFullyDisabled())
 *   - default:  SUM(IF matches default profile)) == expected count
 *   - custom:   otherwise
 *
 * **TIMED-off is 'custom', not 'fully'**: The 'fully' state requires ALL instruments
 * to be both enabled AND timed. If any instrument has timed='NO', the setup is 'custom'.
 *
 * **isFullyDisabled() is available but not wired to detect()**: This method checks
 * whether no consumer is enabled AND no instrument is enabled or timed. It exists for
 * future use but is not called by detect(); detect() uses isDisabled() to check for
 * PS accessibility instead.
 *
 * Version-gated defaults (Appendix C):
 *   - MySQL 5.6 instruments: wait/io/file/%, wait/io/table/%, wait/lock/table/sql/handler, statement/%, idle
 *   - MySQL 5.7+ instruments: same five patterns (stage/% removed in 5.7)
 *   - MySQL 5.6 consumers: events_statements_current, events_transactions_current, global_instrumentation, thread_instrumentation
 *   - MySQL 5.7+ consumers: adds statements_digest
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema easy_setup_detector
 * @see Appendix C: Default instrument/consumer profiles for MySQL 5.6 / 5.7
 */
final class EasySetupDetector
{
    /** MySQL error codes for graceful degradation */
    private const ERR_PRIVILEGE = ['1142', '1227'];
    private const ERR_NOT_EXISTS = ['1146', '42S02'];
    private const ERR_CONNECTION = ['2002', '2003', '2013', '08000', '08006'];

    /**
     * Default instruments for MySQL 5.6 (per Appendix C).
     * Excludes memory/% which is handled separately.
     *
     * @var list<string>
     */
    public const DEFAULT_INSTRUMENTS_56 = [
        'wait/io/file/%',
        'wait/io/table/%',
        'wait/lock/table/sql/handler',
        'statement/%',
        'idle',
    ];

    /**
     * Default instruments for MySQL 5.7+ (per Appendix C).
     * stage/% was removed in 5.7 (it is not included here).
     *
     * @var list<string>
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
     * @var list<string>
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
     * @var list<string>
     */
    public const DEFAULT_CONSUMERS_57 = [
        'events_statements_current',
        'events_transactions_current',
        'global_instrumentation',
        'thread_instrumentation',
        'statements_digest',
    ];

    private readonly ServerVersion $version;

    /**
     * @param DatabaseInterface         $db      Database connection for querying PS tables
     * @param ServerVersion|null              $version Server version (if null, defaults to MySQL 5.6 profile)
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        ?ServerVersion $version = null,
    ) {
        $this->version = $version ?? ServerVersion::parse('5.6.0');
    }

    /**
     * Factory method to create a new detector.
     */
    public static function new(DatabaseInterface $db, ?ServerVersion $version = null): self
    {
        return new self($db, $version);
    }

    /**
     * Create a detector from a server context.
     */
    public static function fromContext(ServerContextInterface $context): self
    {
        return new self($context->connection(), $context->version());
    }

    /**
     * Detect the current PS setup state via server-side aggregates.
     *
     * SQL-P07 (adminpages-008) note: this ladder issues up to seven
     * full-table COUNT/SUM scans — the PerfSchema pane no longer calls it
     * per arrival; it classifies the rows it already loaded via
     * {@see classifyFromData()}, which mirrors these verdicts exactly.
     * detect() remains the direct/embedder utility (and the behavioural
     * oracle the classifier's pins quote against).
     *
     * @return string One of: 'fully', 'default', 'custom', 'disabled'
     */
    public function detect(): string
    {
        if ($this->isDisabled()) {
            return 'disabled';
        }

        if ($this->isFullyEnabled()) {
            return 'fully';
        }

        if ($this->isDefaultSetup()) {
            return 'default';
        }

        return 'custom';
    }

    /**
     * The zero-query twin of {@see detect()}: classify from row lists the
     * page has ALREADY paid for, instead of re-asking the server up to seven
     * aggregate scans (several recomputing exactly what the loaded
     * setup_instruments/setup_consumers rows carry).
     *
     * Verdict equivalence against the query ladder:
     *  - 'disabled' — no instrument rows: the page's loaders swallow the
     *    access/connection error into an empty list, the same evidence
     *    isDisabled() probes for;
     *  - 'fully' — enabled percentage 100 AND no disabled consumer AND no
     *    disabled-or-untimed instrument (isFullyEnabled()'s three counts);
     *  - 'default' — every default consumer enabled, no extra consumer
     *    enabled, every default instrument enabled+timed, no extra
     *    instrument enabled (the two SUM(IF) profiles; the escaped-LIKE
     *    default lists are exact literals, so set membership is the
     *    same test, and memory/% is excluded by the loaders just as both
     *    server-side IFs exclude it);
     *  - 'custom' — everything else.
     *
     * @param list<SetupInstruments> $instruments
     * @param list<SetupConsumers>   $consumers
     */
    public function classifyFromData(array $instruments, array $consumers): string
    {
        if ($instruments === []) {
            return 'disabled';
        }

        $enabledCount = 0;
        $disabledOrUntimed = 0;
        foreach ($instruments as $instrument) {
            if ($instrument->enabled) {
                $enabledCount++;
            }
            if (!$instrument->enabled || !$instrument->timed) {
                $disabledOrUntimed++;
            }
        }

        $disabledConsumers = 0;
        foreach ($consumers as $consumer) {
            if (!$consumer->enabled) {
                $disabledConsumers++;
            }
        }

        // isFullyEnabled(): enabledPercentage() === 100 (rounding included —
        // the same round() the percentage query's PHP-side twin performs) and
        // both detail counts zero.
        if (
            (int) round(($enabledCount / count($instruments)) * 100) === 100
            && $disabledConsumers === 0
            && $disabledOrUntimed === 0
        ) {
            return 'fully';
        }

        if (
            $this->consumersMatchDefaultData($consumers)
            && $this->instrumentsMatchDefaultData($instruments)
        ) {
            return 'default';
        }

        return 'custom';
    }

    /**
     * Client-side twin of consumersMatchDefault() over loaded rows.
     *
     * @param list<SetupConsumers> $consumers
     */
    private function consumersMatchDefaultData(array $consumers): bool
    {
        $defaults = array_flip($this->getDefaultConsumers());
        foreach ($consumers as $consumer) {
            if (isset($defaults[$consumer->name])) {
                if (!$consumer->enabled) {
                    return false;
                }
            } elseif ($consumer->enabled) {
                return false;
            }
        }
        return true;
    }

    /**
     * Client-side twin of instrumentsMatchDefault() over loaded rows.
     *
     * @param list<SetupInstruments> $instruments
     */
    private function instrumentsMatchDefaultData(array $instruments): bool
    {
        $defaults = array_flip($this->getDefaultInstruments());
        foreach ($instruments as $instrument) {
            if (isset($defaults[$instrument->name])) {
                if (!$instrument->enabled || !$instrument->timed) {
                    return false;
                }
            } elseif ($instrument->enabled) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if PS is disabled (no access to setup_instruments).
     *
     * @return bool True if PS is disabled or inaccessible
     */
    public function isDisabled(): bool
    {
        try {
            $sql = 'SELECT COUNT(*) FROM `performance_schema`.`setup_instruments` WHERE `NAME` NOT LIKE \'memory/%\'';
            $this->db->query($sql);
            return false;
        } catch (\PDOException $e) {
            return $this->isConnectionError($e) || $this->isAccessError($e);
        }
    }

    /**
     * Check if all instruments and consumers are fully enabled (enabled='YES' AND timed='YES').
     *
     * Per spec: fully means no consumer is disabled AND no instrument is disabled or untimed.
     * Note: First verifies that enabledPercentage is 100% before checking detailed counts,
     * because a server with <100% instruments enabled cannot be 'fully'.
     *
     * @return bool True if fully enabled
     */
    public function isFullyEnabled(): bool
    {
        try {
            // First verify that enabled percentage is 100%
            // A server with <100% instruments enabled cannot be 'fully', regardless of
            // what the disabled/untimed counts show (which may be ambiguous with limited data)
            if ($this->enabledPercentage() < 100) {
                return false;
            }

            // Check consumers: COUNT(setup_consumers WHERE enabled='NO') should be 0
            $disabledConsumers = $this->countDisabledConsumers();
            if ($disabledConsumers > 0) {
                return false;
            }

            // Check instruments: COUNT(setup_instruments WHERE NAME NOT LIKE 'memory/%'
            //                   AND (enabled='NO' OR timed='NO')) should be 0
            $disabledInstruments = $this->countDisabledOrUntimedInstruments();
            return $disabledInstruments === 0;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Check if PS is fully disabled.
     *
     * Per spec: disabled means no consumer is enabled AND no instrument is enabled or timed.
     *
     * @return bool True if fully disabled
     */
    public function isFullyDisabled(): bool
    {
        try {
            // Check consumers: COUNT(setup_consumers WHERE enabled='YES') should be 0
            $enabledConsumers = $this->countEnabledConsumers();
            if ($enabledConsumers > 0) {
                return false;
            }

            // Check instruments: COUNT(setup_instruments WHERE NAME NOT LIKE 'memory/%'
            //                   AND (enabled='YES' OR timed='YES')) should be 0
            $enabledOrTimedInstruments = $this->countEnabledOrTimedInstruments();
            return $enabledOrTimedInstruments === 0;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Check if the current setup matches the MySQL default profile for this version.
     *
     * Uses SUM(IF(...)) to compare current state against the expected default profile.
     *
     * @return bool True if setup matches defaults
     */
    public function isDefaultSetup(): bool
    {
        try {
            // Check consumers against default profile
            if (!$this->consumersMatchDefault()) {
                return false;
            }

            // Check instruments against default profile
            return $this->instrumentsMatchDefault();
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Get the percentage of fully-enabled (enabled AND timed) instruments (0-100).
     *
     * Excludes memory/% instruments from calculation.
     *
     * @return int Percentage (0-100)
     */
    public function enabledPercentage(): int
    {
        try {
            $result = $this->getInstrumentCounts();
            if ($result['total'] === 0) {
                return 0;
            }

            return (int) round(($result['enabled'] / $result['total']) * 100);
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /**
     * Get the total number of non-memory instruments.
     *
     * @return int Total count (excluding memory/% instruments)
     */
    public function totalCount(): int
    {
        try {
            return $this->getInstrumentCounts()['total'];
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /**
     * Get the number of enabled non-memory instruments.
     *
     * @return int Enabled count
     */
    public function enabledCount(): int
    {
        try {
            return $this->getInstrumentCounts()['enabled'];
        } catch (\PDOException $e) {
            return 0;
        }
    }

    // ─── Private Detection Helpers ───────────────────────────────────────────

    /**
     * Count consumers with enabled='NO'.
     *
     * @return int
     */
    private function countDisabledConsumers(): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) AS cnt
            FROM `performance_schema`.`setup_consumers`
            WHERE `ENABLED` = 'NO'
            SQL;

        $result = $this->db->query($sql);
        return (int) ($result[0]['cnt'] ?? 0);
    }

    /**
     * Count consumers with enabled='YES'.
     *
     * @return int
     */
    private function countEnabledConsumers(): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) AS cnt
            FROM `performance_schema`.`setup_consumers`
            WHERE `ENABLED` = 'YES'
            SQL;

        $result = $this->db->query($sql);
        return (int) ($result[0]['cnt'] ?? 0);
    }

    /**
     * Count instruments that are disabled or not timed (excluding memory/%).
     *
     * Per spec: COUNT(setup_instruments WHERE NAME NOT LIKE 'memory/%' AND (enabled='NO' OR timed='NO'))
     *
     * @return int
     */
    private function countDisabledOrUntimedInstruments(): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) AS cnt
            FROM `performance_schema`.`setup_instruments`
            WHERE `NAME` NOT LIKE 'memory/%'
              AND (`ENABLED` = 'NO' OR `TIMED` = 'NO')
            SQL;

        $result = $this->db->query($sql);
        return (int) ($result[0]['cnt'] ?? 0);
    }

    /**
     * Count instruments that are enabled or timed (excluding memory/%).
     *
     * @return int
     */
    private function countEnabledOrTimedInstruments(): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) AS cnt
            FROM `performance_schema`.`setup_instruments`
            WHERE `NAME` NOT LIKE 'memory/%'
              AND (`ENABLED` = 'YES' OR `TIMED` = 'YES')
            SQL;

        $result = $this->db->query($sql);
        return (int) ($result[0]['cnt'] ?? 0);
    }

    /**
     * Check if consumers match the default profile for this version.
     *
     * @return bool
     */
    private function consumersMatchDefault(): bool
    {
        $defaultConsumers = $this->getDefaultConsumers();

        // Build WHERE clause for default consumers
        $conditions = array_map(
            fn(string $c) => sprintf("`NAME` = '%s'", $c),
            $defaultConsumers
        );
        $whereClause = implode(' OR ', $conditions);

        // SUM(IF(NAME IN default consumers, enabled<>'YES', 0)) +
        // SUM(IF(NAME NOT IN default consumers, enabled=='YES', 0))
        // should both be 0 for default state

        $sql = <<<SQL
            SELECT
                SUM(IF(({$whereClause}), `ENABLED` != 'YES', 0)) AS default_disabled,
                SUM(IF(NOT ({$whereClause}), `ENABLED` = 'YES', 0)) AS non_default_enabled
            FROM `performance_schema`.`setup_consumers`
            SQL;

        $result = $this->db->query($sql);

        if ($result === [] || !isset($result[0])) {
            return false;
        }

        // Verify expected columns exist; if not, we can't confirm default setup
        if (!isset($result[0]['default_disabled']) || !isset($result[0]['non_default_enabled'])) {
            return false;
        }

        $defaultDisabled = (int) $result[0]['default_disabled'];
        $nonDefaultEnabled = (int) $result[0]['non_default_enabled'];

        return $defaultDisabled === 0 && $nonDefaultEnabled === 0;
    }

    /**
     * Check if instruments match the default profile for this version.
     *
     * @return bool
     */
    private function instrumentsMatchDefault(): bool
    {
        $defaultInstruments = $this->getDefaultInstruments();

        // Build LIKE conditions for default instruments
        $conditions = array_map(
            fn(string $p) => sprintf("`NAME` LIKE '%s'", str_replace(['%', '_'], ['\\%', '\\_'], $p)),
            $defaultInstruments
        );
        $defaultLikeClause = '(' . implode(' OR ', $conditions) . ')';

        // Check: default instruments should be enabled AND timed,
        //        non-default instruments should be disabled
        // We use the same SUM(IF(...)) approach
        $sql = <<<SQL
            SELECT
                SUM(IF(({$defaultLikeClause}) AND `NAME` NOT LIKE 'memory/%',
                    `ENABLED` != 'YES' OR `TIMED` != 'YES', 0)) AS default_disabled,
                SUM(IF(NOT ({$defaultLikeClause}) AND `NAME` NOT LIKE 'memory/%',
                    `ENABLED` = 'YES', 0)) AS non_default_enabled
            FROM `performance_schema`.`setup_instruments`
            SQL;

        $result = $this->db->query($sql);

        if ($result === [] || !isset($result[0])) {
            return false;
        }

        $defaultDisabled = (int) ($result[0]['default_disabled'] ?? 0);
        $nonDefaultEnabled = (int) ($result[0]['non_default_enabled'] ?? 0);

        return $defaultDisabled === 0 && $nonDefaultEnabled === 0;
    }

    /**
     * Get default instruments for the current version.
     *
     * @return list<string>
     */
    private function getDefaultInstruments(): array
    {
        return $this->version->isAtLeast(5, 7)
            ? self::DEFAULT_INSTRUMENTS_57
            : self::DEFAULT_INSTRUMENTS_56;
    }

    /**
     * Get default consumers for the current version.
     *
     * @return list<string>
     */
    private function getDefaultConsumers(): array
    {
        return $this->version->isAtLeast(5, 7)
            ? self::DEFAULT_CONSUMERS_57
            : self::DEFAULT_CONSUMERS_56;
    }

    /**
     * Get total and enabled counts in a single query.
     *
     * @return array{total: int, enabled: int}
     */
    private function getInstrumentCounts(): array
    {
        $sql = <<<'SQL'
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN `ENABLED` = 'YES' THEN 1 ELSE 0 END) AS enabled
            FROM `performance_schema`.`setup_instruments`
            WHERE `NAME` NOT LIKE 'memory/%'
            SQL;

        $result = $this->db->query($sql);

        if ($result === [] || !isset($result[0]['total'])) {
            return ['total' => 0, 'enabled' => 0];
        }

        return [
            'total' => (int) $result[0]['total'],
            'enabled' => (int) ($result[0]['enabled'] ?? 0),
        ];
    }

    /**
     * Check if exception indicates a connection error.
     */
    private function isConnectionError(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        return in_array($code, self::ERR_CONNECTION, true);
    }

    /**
     * Check if exception indicates an access/privilege error.
     */
    private function isAccessError(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        return in_array($code, self::ERR_PRIVILEGE, true) || in_array($code, self::ERR_NOT_EXISTS, true);
    }
}
