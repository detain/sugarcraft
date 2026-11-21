<?php

declare(strict_types=1);

namespace SugarCraft\Plugins;

/**
 * One plugin in its lifecycle shape: the host loads it under its trust gate,
 * registers it by name, starts it with a {@see PluginContext}, and disposes
 * it at shutdown.
 *
 * A plugin that never passed the load gate never reaches the registry, and a
 * plugin the registry refuses at register never starts. Every payload that
 * crosses this boundary is a JSON-serializable array, so a plugin never holds
 * a host object it could reach back through.
 */
interface Plugin
{
    /**
     * The plugin's name, unique across the host's accepted plugins. The host
     * matches names exactly and never case-folds them.
     */
    public function name(): string;

    /**
     * Bring the plugin up. Called once, after the trust gate and the name
     * door have both passed. Throwing refuses this plugin only: the host
     * records the refusal and keeps every other plugin running.
     */
    public function start(PluginContext $context): void;

    /**
     * Symmetric teardown: close sockets, cancel every timer armed through the
     * context loop, and release anything held. Called once per started plugin
     * in reverse start order. A throw here is logged and swallowed by the host,
     * so one faulty plugin cannot strand the teardown of the others.
     */
    public function dispose(): void;
}
