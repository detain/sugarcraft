<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Validation;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Validates that the database connection is alive and usable.
 */
final class ConnectionValidator extends Validator
{
    public function isValid(): bool
    {
        $db = $this->context->connection();

        if (!$db->ping()) {
            $this->setError('Database connection is not alive');
            return false;
        }

        try {
            $db->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            $this->setError('Cannot execute queries: ' . $e->getMessage());
            return false;
        }
    }
}
