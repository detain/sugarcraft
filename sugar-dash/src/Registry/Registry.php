<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Registry;

/**
 * Static registry for module constructors.
 *
 * Provides a central registration point for module types that can be
 * instantiated by name. Used by the plugin system to dynamically
 * create modules.
 *
 * Mirrors the lattice registry pattern.
 */
final class Registry
{
    /** @var array<string, Constructor> */
    private static array $registry = [];

    /**
     * Register a module constructor.
     *
     * @param string $name Module name
     * @param Constructor $constructor Closure that creates the module
     * @throws \RuntimeException If a module with this name is already registered
     */
    public static function register(string $name, Constructor $constructor): void
    {
        if (isset(self::$registry[$name])) {
            throw new \RuntimeException("Module '{$name}' is already registered");
        }
        self::$registry[$name] = $constructor;
    }

    /**
     * Get a module constructor by name.
     *
     * @param string $name Module name
     * @return Constructor
     * @throws \RuntimeException If no module with this name is registered
     */
    public static function get(string $name): Constructor
    {
        if (!isset(self::$registry[$name])) {
            throw new \RuntimeException("Module '{$name}' is not registered");
        }
        return self::$registry[$name];
    }

    /**
     * Check if a module is registered.
     */
    public static function has(string $name): bool
    {
        return isset(self::$registry[$name]);
    }

    /**
     * List all registered module names.
     *
     * @return list<string>
     */
    public static function list(): array
    {
        return array_keys(self::$registry);
    }

    /**
     * Reset the registry (useful for testing).
     */
    public static function reset(): void
    {
        self::$registry = [];
    }
}
