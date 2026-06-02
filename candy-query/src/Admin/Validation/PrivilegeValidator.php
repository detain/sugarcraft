<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Validation;

use SugarCraft\Query\Db\Flavor;

/**
 * Validates that the current user has required privileges for admin pages.
 *
 * Required: PROCESS privilege (for SHOW PROCESSLIST) and
 * SUPER or SERVICE_CONNECTION_ADMIN (for some status variables).
 */
final class PrivilegeValidator extends Validator
{
    /** @var list<string> */
    private array $grantedPrivileges = [];

    public function isValid(): bool
    {
        $flavor = $this->context->flavor();

        if ($flavor === Flavor::Sqlite) {
            return true;
        }

        try {
            $rows = $this->context->connection()->query('SHOW PRIVILEGES');
            foreach ($rows as $row) {
                if (isset($row['Privilege'])) {
                    $this->grantedPrivileges[] = strtolower($row['Privilege']);
                }
            }
        } catch (\PDOException) {
        }

        $hasProcess = in_array('process', $this->grantedPrivileges, true);
        $hasSuper = in_array('super', $this->grantedPrivileges, true);
        $hasServiceConnection = in_array('service_connection_admin', $this->grantedPrivileges, true);

        if (!$hasProcess && !$hasSuper && !$hasServiceConnection) {
            $this->setError('Missing PROCESS or SUPER privilege for full admin access');
            return false;
        }

        return true;
    }

    /**
     * Check if a specific privilege is granted.
     */
    public function hasPrivilege(string $privilege): bool
    {
        return in_array(strtolower($privilege), $this->grantedPrivileges, true);
    }

    /**
     * Get all granted privileges.
     *
     * @return list<string>
     */
    public function grantedPrivileges(): array
    {
        return $this->grantedPrivileges;
    }
}
