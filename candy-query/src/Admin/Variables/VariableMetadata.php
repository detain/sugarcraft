<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Variables;

/**
 * Immutable metadata descriptor for a MySQL system variable.
 *
 * Encapsulates name, description, editability, runtime-dynamic flag, and
 * group membership for a single configuration variable.
 *
 * @see Mirrors mysql-workbench wb_admin_variable_list metadata
 */
final readonly class VariableMetadata
{
    /**
     * @param string $name The variable name as it appears in SHOW VARIABLES
     * @param string $description Human-readable description of the variable's purpose
     * @param bool $editable Whether the variable can be modified at all (SET GLOBAL / SET PERSIST)
     * @param bool $dynamic Whether the variable can be changed at runtime without a server restart.
     *        Static (non-dynamic) variables like innodb_log_file_size accept SET GLOBAL but
     *        fail with error 1238 and require a restart. Default true — most vars are dynamic.
     * @param list<string> $groups Categorical groups the variable belongs to (e.g., connection, buffer, log)
     */
    public function __construct(
        public string $name,
        public string $description,
        public bool $editable,
        public bool $dynamic = true,
        public array $groups = [],
    ) {}

    /**
     * Check if the variable belongs to a given group.
     */
    public function inGroup(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }

    /**
     * Check if the variable is runtime-dynamic (can be changed without restart).
     *
     * Static vars accept SET GLOBAL but error with 1238 (requires restart).
     * Use this to gate inline SET GLOBAL; use editable for SET PERSIST checks.
     */
    public function isDynamic(): bool
    {
        return $this->dynamic;
    }
}
