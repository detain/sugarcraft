<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Registry;

use SugarCraft\Dashboard\Module\LegacyModule;
use SugarCraft\Dashboard\Module\LegacyModuleAdapter;
use SugarCraft\Dashboard\Module\Module;

/**
 * Instance registry for module constructors.
 *
 * Provides a registration point for module types that can be
 * instantiated by name. Used by the plugin system to dynamically
 * create modules. The host owns a Registry and hands it to whoever
 * needs to look modules up — the store used to be class-level statics,
 * so `reset()` was a global mutation and two test files (or two
 * dashboards in one process) silently shared one namespace with
 * order-dependent failures (finding sugar-dashboard-211).
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
    private array $constructors = [];

    /**
     * Register a module constructor.
     *
     * @param string $name Module name
     * @param callable(): Module|LegacyModule $constructor Closure that creates the module
     * @throws \RuntimeException If a module with this name is already registered
     */
    public function register(string $name, callable $constructor): void
    {
        if (isset($this->constructors[$name])) {
            throw new \RuntimeException("Module '{$name}' is already registered");
        }

        // Defer the legacy-vs-new decision to instantiation time: the
        // only moment a real module object exists anyway. (Registration
        // used to CALL the constructor purely to inspect its type,
        // which spawned plugin children that were immediately
        // discarded — leaked process plus file descriptors.)
        $this->constructors[$name] = static function () use ($constructor): Module {
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
    public function get(string $name): callable
    {
        if (!isset($this->constructors[$name])) {
            throw new \RuntimeException("Module '{$name}' is not registered");
        }
        return $this->constructors[$name];
    }

    /**
     * Check if a module is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->constructors[$name]);
    }

    /**
     * List all registered module names.
     *
     * @return list<string>
     */
    public function list(): array
    {
        return array_keys($this->constructors);
    }

    /**
     * Empty this registry (host teardown; scratch registries in tests).
     *
     * Instance-scoped: clearing one registry cannot disturb another,
     * which is the point of finding sugar-dashboard-211 — the old
     * class-level reset() coupled every caller in the process.
     */
    public function reset(): void
    {
        $this->constructors = [];
    }
}
