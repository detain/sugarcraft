<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Registry;

use SugarCraft\Dash\Module\LegacyModule;
use SugarCraft\Dash\Module\LegacyModuleAdapter;
use SugarCraft\Dash\Module\Module;

/**
 * Static registry for module constructors.
 *
 * Provides a central registration point for module types that can be
 * instantiated by name. Used by the plugin system to dynamically
 * create modules.
 *
 * Accepts both new-style Module instances and legacy LegacyModule
 * instances. Legacy modules are automatically wrapped with
 * LegacyModuleAdapter so the registry always returns Module.
 *
 * Mirrors the lattice registry pattern.
 */
final class Registry
{
    /** @var array<string, array{0: callable, 1: bool}> */
    private static array $registry = [];

    /**
     * Register a module constructor.
     *
     * @param string $name Module name
     * @param callable(): Module|LegacyModule $constructor Closure that creates the module
     * @throws \RuntimeException If a module with this name is already registered
     */
    public static function register(string $name, callable $constructor): void
    {
        if (isset(self::$registry[$name])) {
            throw new \RuntimeException("Module '{$name}' is already registered");
        }

        // Probe to determine if this is a legacy or new-style constructor.
        // We call it once at registration time to avoid repeated type checks.
        $isLegacy = false;
        $module = $constructor();
        if ($module instanceof LegacyModule) {
            $isLegacy = true;
            // For legacy modules, re-wrap with adapter so get() always
            // returns Module regardless of the underlying type.
            self::$registry[$name] = [
                fn(): Module => new LegacyModuleAdapter($constructor()),
                true,
            ];
        } else {
            self::$registry[$name] = [$constructor, false];
        }
    }

    /**
     * Get a module constructor by name.
     *
     * @param string $name Module name
     * @return Constructor(): Module
     * @throws \RuntimeException If no module with this name is registered
     */
    public static function get(string $name): callable
    {
        if (!isset(self::$registry[$name])) {
            throw new \RuntimeException("Module '{$name}' is not registered");
        }
        return self::$registry[$name][0];
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
