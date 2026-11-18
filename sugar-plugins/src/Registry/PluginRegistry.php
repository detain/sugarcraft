<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Registry;

use SugarCraft\Plugins\Plugin;
use SugarCraft\Plugins\Support\ErrorLine;

/**
 * The register, start-order and dispose half of a plugin's lifecycle.
 *
 * Keying is `bundle|name`, the granularity a per-plugin policy layer addresses,
 * while the lookup index is the bare name, because names are unique ACROSS
 * plugins: a plugin may never shadow a built-in or another accepted plugin.
 * Collision refusal happens here at register time, and the registry never
 * overrides. The host passes the names it reserves for itself.
 *
 * The registry holds instances and answers lookups. It owns no rule state, and
 * it does not start plugins: the host registers, starts, then calls
 * {@see markStarted()}, so a plugin the registry refuses never starts.
 */
final class PluginRegistry
{
    /** @var array<string, Plugin> keyed "bundle|name" */
    private array $plugins = [];

    /** @var array<string, string> name => key, the uniqueness and lookup index */
    private array $byName = [];

    /** @var list<Plugin> started plugins, start order, for reverse-order dispose */
    private array $started = [];

    private bool $disposed = false;

    /** @param list<string> $reservedBundles bundle names that may never register a plugin */
    private function __construct(
        private readonly array $reservedBundles,
    ) {
    }

    /** @param list<string> $reservedBundles */
    public static function new(array $reservedBundles = []): self
    {
        return new self($reservedBundles);
    }

    /**
     * Accept one plugin under $bundleName. A collision (a reserved bundle, or a
     * name another accepted plugin already owns) is refused, never overridden.
     * Returns false and names the reason word in $reason when it refuses.
     */
    public function register(string $bundleName, object $plugin, ?string &$reason = null): bool
    {
        $reason = null;

        if (!$plugin instanceof Plugin) {
            $reason = 'not-a-plugin';

            return false;
        }

        if (\in_array($bundleName, $this->reservedBundles, true)) {
            $reason = 'reserved-namespace';

            return false;
        }

        $name = \trim($plugin->name());

        if (isset($this->byName[$name])) {
            $reason = 'collision';

            return false;
        }

        $key = $bundleName . '|' . $name;
        $this->plugins[$key] = $plugin;
        $this->byName[$name] = $key;

        return true;
    }

    /** Remove a registration, for a plugin the host refused to start. */
    public function unregister(string $bundleName, string $name): void
    {
        $key = $bundleName . '|' . $name;

        unset($this->plugins[$key]);

        // The bare name belongs to whichever bundle registered it first; another
        // bundle's unregister must not free a name it never owned.
        if (($this->byName[$name] ?? null) === $key) {
            unset($this->byName[$name]);
        }
    }

    /** Note that a registered plugin started, so it is disposed in reverse start order. */
    public function markStarted(Plugin $plugin): void
    {
        $this->started[] = $plugin;
    }

    /** @return list<string> started plugin names, start order */
    public function startedNames(): array
    {
        return \array_map(static fn (Plugin $plugin): string => $plugin->name(), $this->started);
    }

    public function pluginNamed(string $name): ?Plugin
    {
        $key = $this->byName[$name] ?? null;

        return $key === null ? null : ($this->plugins[$key] ?? null);
    }

    /**
     * Dispose started plugins in reverse start order. Each dispose is shielded:
     * a throw is handed to $log and swallowed, so one faulty plugin cannot strand
     * the teardown of the others. Idempotent.
     *
     * @param \Closure(string): void $log
     */
    public function disposeAll(\Closure $log): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        foreach (\array_reverse($this->started) as $plugin) {
            try {
                $plugin->dispose();
            } catch (\Throwable $e) {
                $log(ErrorLine::of($e, 'plugin dispose ' . $plugin->name()));
            }
        }

        $this->started = [];
        $this->plugins = [];
        $this->byName = [];
    }
}
