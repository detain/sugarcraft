<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Variables;

/**
 * Catalog loader for MySQL variable metadata.
 *
 * Reads variable metadata from a JSON file and provides lookup methods
 * for querying variables by name, group, or editability.
 *
 * @see Mirrors mysql-workbench wb_admin_variable_list
 */
final class Catalog
{
    /** @var array<string, VariableMetadata>|null */
    private ?array $variables = null;

    /** @var list<string>|null */
    private ?array $groups = null;

    private function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Factory method to create a new Catalog instance.
     *
     * @param string $basePath Path to the data directory containing variable_metadata.json
     */
    public static function new(string $basePath = __DIR__ . '/../../../data'): self
    {
        return new self($basePath);
    }

    /**
     * Load and parse the variable metadata JSON file.
     *
     * @throws \JsonException If the JSON file cannot be parsed
     * @throws \RuntimeException If the metadata file cannot be read
     */
    public function load(): void
    {
        $filePath = $this->basePath . '/variable_metadata.json';

        if (!is_readable($filePath)) {
            throw new \RuntimeException(
                "Variable metadata file not found: " . htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8')
            );
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new \RuntimeException(
                "Failed to read variable metadata file: " . htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8')
            );
        }

        /** @var array<string, array{name:string,description:string,editable:bool,groups:array<string>}> $data */
        $data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $this->variables = [];
        foreach ($data as $name => $metadata) {
            $this->variables[$name] = new VariableMetadata(
                name: $metadata['name'],
                description: $metadata['description'],
                editable: $metadata['editable'],
                groups: $metadata['groups'],
            );
        }

        $this->groups = null;
    }

    /**
     * Get a variable by name.
     *
     * @param string $name The variable name to look up
     * @return VariableMetadata|null The variable metadata or null if not found
     */
    public function get(string $name): ?VariableMetadata
    {
        $this->ensureLoaded();

        return $this->variables[$name] ?? null;
    }

    /**
     * Get all variables.
     *
     * @return array<string, VariableMetadata> All variable metadata indexed by name
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->variables;
    }

    /**
     * Get all variables belonging to a specific group.
     *
     * @param string $group The group name to filter by
     * @return array<string, VariableMetadata> Matching variables indexed by name
     */
    public function byGroup(string $group): array
    {
        $this->ensureLoaded();

        $result = [];
        foreach ($this->variables as $name => $metadata) {
            if ($metadata->inGroup($group)) {
                $result[$name] = $metadata;
            }
        }

        return $result;
    }

    /**
     * List all available groups.
     *
     * @return list<string> Sorted list of unique group names
     */
    public function groups(): array
    {
        $this->ensureLoaded();

        if ($this->groups === null) {
            $groups = [];
            foreach ($this->variables as $metadata) {
                foreach ($metadata->groups as $group) {
                    $groups[$group] = true;
                }
            }
            $this->groups = array_keys($groups);
            sort($this->groups);
        }

        return $this->groups;
    }

    /**
     * Check if a variable is editable.
     *
     * @param string $name The variable name to check
     * @return bool True if the variable is editable, false if not found or not editable
     */
    public function isEditable(string $name): bool
    {
        $metadata = $this->get($name);

        return $metadata !== null && $metadata->editable;
    }

    /**
     * Ensure the metadata has been loaded.
     *
     * @throws \RuntimeException If load() has not been called
     */
    private function ensureLoaded(): void
    {
        if ($this->variables === null) {
            throw new \RuntimeException(
                'Catalog has not been loaded. Call load() first.'
            );
        }
    }
}
