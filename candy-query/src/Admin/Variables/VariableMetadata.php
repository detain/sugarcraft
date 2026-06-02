<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Variables;

/**
 * Immutable metadata descriptor for a MySQL system variable.
 *
 * Encapsulates name, description, editability, and group membership
 * for a single configuration variable.
 *
 * @see Mirrors mysql-workbench wb_admin_variable_list metadata
 */
final readonly class VariableMetadata
{
    /**
     * @param string $name The variable name as it appears in SHOW VARIABLES
     * @param string $description Human-readable description of the variable's purpose
     * @param bool $editable Whether the variable can be modified at runtime
     * @param list<string> $groups Categorical groups the variable belongs to (e.g., connection, buffer, log)
     */
    public function __construct(
        public string $name,
        public string $description,
        public bool $editable,
        public array $groups,
    ) {}

    /**
     * Check if the variable belongs to a given group.
     */
    public function inGroup(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }
}
