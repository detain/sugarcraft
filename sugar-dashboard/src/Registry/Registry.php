<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Registry;

use SugarCraft\Dashboard\Module\LegacyModule;
use SugarCraft\Dashboard\Module\LegacyModuleAdapter;
use SugarCraft\Dashboard\Module\Module;

/**
 * Static registry for module constructors.
 *
 * Provides a central registration point for module types that can be
 * instantiated by name. Used by the plugin system to dynamically
 * create modules.
 *
 * Accepts both new-style Module constructors and legacy LegacyModule
 * constructors. A legacy constructor is wrapped at instantiation time
 * with LegacyModuleAdapter, so get() always yields a Module factory.
 * Registration itself performs no instantiation — probing the
 * constructor eagerly would run real plugin side effects (a child
 * spawn) at boot and throw the result away.
 */
final class Registry
{
    /** @var array<string, callable(): Module> */
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

        // Defer the legacy-vs-new decision to instantiation time: the
        // only moment a real module object exists anyway. (Registration
        // used to CALL the constructor purely to inspect its type,
        // which spawned plugin children that were immediately
        // discarded — leaked process plus file descriptors.)
        self::$registry[$name] = static function () use ($constructor): Module {
            $module = $constructor();

            return $module instanceof Module
                ? $module
                : new LegacyModuleAdapter($module);
        };
    }

    /**
     * Get a module factory by name.
     *
     * @param string $name Module name
     * @return callable(): Module
     * @throws \RuntimeException If no module with this name is registered
     */
    public static function get(string $name): callable
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
