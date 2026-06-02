<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Validation;

use SugarCraft\Query\Db\Flavor;

/**
 * Validates that performance_schema or equivalent is accessible.
 *
 * MySQL 5.6+ has performance_schema. MariaDB 10.0+ has it too.
 * This checks if SHOW GLOBAL STATUS works (proxies for PS availability).
 */
final class PsUsableValidator extends Validator
{
    public function isValid(): bool
    {
        $flavor = $this->context->flavor();

        if ($flavor === Flavor::Sqlite) {
            $this->setError('Performance metrics not available for SQLite');
            return false;
        }

        try {
            $status = $this->context->statusVariables();
            if ($status === []) {
                $this->setError('Cannot read global status variables');
                return false;
            }
            return true;
        } catch (\PDOException $e) {
            $this->setError('Performance schema not accessible: ' . $e->getMessage());
            return false;
        }
    }
}
