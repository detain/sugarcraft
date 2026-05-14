<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

/**
 * Configuration for a module instance.
 *
 * @readonly
 */
final class ModuleConfig
{
    public function __construct(
        public readonly string $name,
        public readonly int $interval = 0,
        public readonly int $minWidth = 30,
        public readonly int $minHeight = 4,
        public readonly array $options = [],
    ) {}

    /**
     * Create a config with the minimum interval (no auto-update).
     */
    public static function static(string $name, int $minWidth = 30, int $minHeight = 4): self
    {
        return new self($name, 0, $minWidth, $minHeight);
    }

    /**
     * Create a config with periodic updates.
     */
    public static function withInterval(string $name, int $interval, int $minWidth = 30, int $minHeight = 4): self
    {
        return new self($name, $interval, $minWidth, $minHeight);
    }

    /**
     * Create a config with custom options.
     */
    public static function withOptions(string $name, array $options, int $minWidth = 30, int $minHeight = 4): self
    {
        return new self($name, 0, $minWidth, $minHeight, $options);
    }
}
