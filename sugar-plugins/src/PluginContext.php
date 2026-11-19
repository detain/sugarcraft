<?php

declare(strict_types=1);

namespace SugarCraft\Plugins;

use React\EventLoop\LoopInterface;
use SugarCraft\Plugins\Host\HostServices;

/**
 * What a plugin is allowed to know about the launch it runs in.
 *
 * The context carries identity and the settings the host chose for the plugin,
 * and no host policy. A plugin reads its own settings section from $config,
 * which the host froze at launch, and it reaches its state only through
 * $stateDir. It never receives a reference to the host's stores or registries.
 *
 * Immutable value object, constructed once per plugin at start.
 */
final readonly class PluginContext
{
    /**
     * @param \Closure(string): void $log one line per event; never a secret
     * @param array<string, mixed> $config the plugin's settings section, frozen at launch
     * @param HostServices|null $host the in-process host calls; null until a host is wired in
     */
    private function __construct(
        public string $plugin,
        public string $name,
        public ?string $root,
        public LoopInterface $loop,
        public \Closure $log,
        public array $config,
        public ?string $stateDir,
        public ?HostServices $host = null,
    ) {
    }

    /**
     * @param \Closure(string): void|null $log null discards every line
     * @param array<string, mixed> $config
     */
    public static function for(
        string $plugin,
        string $name,
        ?string $root,
        LoopInterface $loop,
        ?\Closure $log = null,
        array $config = [],
        ?string $stateDir = null,
        ?HostServices $host = null,
    ): self {
        return new self(
            $plugin,
            $name,
            $root,
            $loop,
            $log ?? static function (string $line): void {
            },
            $config,
            $stateDir,
            $host,
        );
    }
}
