<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Detects the current Performance Schema setup state via COUNT/SUM queries.
 *
 * Determines whether PS is:
 *   - 'fully': All instruments enabled (100%)
 *   - 'default': Default MySQL setup (typical instrument set)
 *   - 'custom': User customized setup
 *   - 'disabled': Performance Schema is disabled
 *
 * Uses COUNT/SUM queries against setup_instruments to determine state.
 * Excludes memory/% instruments from calculations (handled separately).
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema easy_setup_detector
 */
final class EasySetupDetector
{
    /** MySQL error codes for graceful degradation */
    private const ERR_PRIVILEGE = ['1142', '1227'];
    private const ERR_NOT_EXISTS = ['1146', '42S02'];
    private const ERR_CONNECTION = ['2002', '2003', '2013', '08000', '08006'];

    /**
     * @param DatabaseInterface $db Database connection for querying PS tables
     */
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Factory method to create a new detector.
     */
    public static function new(DatabaseInterface $db): self
    {
        return new self($db);
    }

    /**
     * Detect the current PS setup state.
     *
     * @return string One of: 'fully', 'default', 'custom', 'disabled'
     */
    public function detect(): string
    {
        if ($this->isDisabled()) {
            return 'disabled';
        }

        $percentage = $this->enabledPercentage();

        if ($percentage === 100) {
            return 'fully';
        }

        // Check if it matches default MySQL setup
        if ($this->isDefaultSetup()) {
            return 'default';
        }

        return 'custom';
    }

    /**
     * Get the percentage of enabled instruments (0-100).
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

    /**
     * Check if PS is disabled (no access to setup_instruments).
     *
     * @return bool True if PS is disabled or inaccessible
     */
    public function isDisabled(): bool
    {
        try {
            // Try to query the setup_instruments table
            $sql = 'SELECT COUNT(*) FROM `performance_schema`.`setup_instruments` WHERE `NAME` NOT LIKE \'memory/%\'';
            $this->db->query($sql);
            return false;
        } catch (\PDOException $e) {
            return $this->isConnectionError($e) || $this->isAccessError($e);
        }
    }

    /**
     * Count total non-memory instruments.
     *
     * @return int Count of instruments excluding memory/%
     */
    private function countNonMemoryInstruments(): int
    {
        return $this->getInstrumentCounts()['total'];
    }

    /**
     * Count enabled non-memory instruments.
     *
     * @return int Count of enabled instruments excluding memory/%
     */
    private function countEnabledNonMemoryInstruments(): int
    {
        return $this->getInstrumentCounts()['enabled'];
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
     * Check if the current setup matches MySQL defaults.
     *
     * Uses SUM of ENABLED to determine if default set is active.
     * Default MySQL enables stage/%, statement/%, and wait/% instruments.
     *
     * @return bool True if setup matches defaults
     */
    private function isDefaultSetup(): bool
    {
        try {
            // Get sum of enabled status for each instrument category
            $sql = <<<SQL
                SELECT
                    `NAME` LIKE 'stage/%' OR `NAME` LIKE 'statement/%' OR `NAME` LIKE 'wait/%' AS is_default_category,
                    `ENABLED` = 'YES' AS is_enabled
                FROM `performance_schema`.`setup_instruments`
                WHERE `NAME` NOT LIKE 'memory/%'
                SQL;

            $result = $this->db->query($sql);

            if ($result === []) {
                return false;
            }

            // Default setup means default-category instruments are enabled
            // and non-default category instruments are typically disabled
            $defaultEnabled = 0;
            $defaultTotal = 0;
            $nonDefaultEnabled = 0;
            $nonDefaultTotal = 0;

            foreach ($result as $row) {
                if (!isset($row['is_default_category']) || !isset($row['is_enabled'])) {
                    continue;
                }

                if ((bool) $row['is_default_category']) {
                    $defaultTotal++;
                    if ((bool) $row['is_enabled']) {
                        $defaultEnabled++;
                    }
                } else {
                    $nonDefaultTotal++;
                    if ((bool) $row['is_enabled']) {
                        $nonDefaultEnabled++;
                    }
                }
            }

            // Default setup: default instruments are enabled, non-default are disabled
            if ($defaultTotal > 0 && $defaultEnabled > 0 && $nonDefaultTotal > 0) {
                return $defaultEnabled === $defaultTotal && $nonDefaultEnabled === 0;
            }

            // If we have default instruments and they're all enabled, likely default setup
            if ($defaultTotal > 0 && $defaultEnabled === $defaultTotal) {
                return true;
            }

            return false;
        } catch (\PDOException $e) {
            return false;
        }
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
