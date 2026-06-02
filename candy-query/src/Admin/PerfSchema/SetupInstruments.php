<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Immutable representation of a Performance Schema instrument.
 *
 * Instruments track specific internal activities in the MySQL server.
 * Can be enabled/disabled at runtime via withEnabled() / withTimed().
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema setup_instruments
 */
final readonly class SetupInstruments
{
    use Mutable;

    /**
     * @param string $name     Full instrument name (e.g. "wait/io/file/sql/binlog")
     * @param bool   $enabled  Whether the instrument is currently enabled
     * @param bool   $timed    Whether the instrument is currently timed
     * @param string $properties Comma-separated properties (e.g. "global,stat,abstract")
     * @param string $flags    Comma-separated flags
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public bool $timed,
        public string $properties,
        public string $flags,
    ) {}

    /**
     * Factory method to create a new instance.
     */
    public static function new(
        string $name = '',
        bool $enabled = false,
        bool $timed = false,
        string $properties = '',
        string $flags = '',
    ): self {
        return new self($name, $enabled, $timed, $properties, $flags);
    }

    /**
     * Return a new instance with the enabled state changed.
     *
     * @param bool $enabled New enabled state
     * @return static New instance
     */
    public function withEnabled(bool $enabled): static
    {
        return $this->mutate(['enabled' => $enabled]);
    }

    /**
     * Return a new instance with the timed state changed.
     *
     * @param bool $timed New timed state
     * @return static New instance
     */
    public function withTimed(bool $timed): static
    {
        return $this->mutate(['timed' => $timed]);
    }

    /**
     * Check if this instrument has a specific property.
     */
    public function hasProperty(string $property): bool
    {
        if ($this->properties === '') {
            return false;
        }

        $props = array_map('trim', explode(',', $this->properties));
        return in_array($property, $props, true);
    }

    /**
     * Check if this instrument is an abstract (pseudo) instrument.
     */
    public function isAbstract(): bool
    {
        return $this->hasProperty('abstract');
    }

    /**
     * Check if this instrument is a global-level instrument.
     */
    public function isGlobal(): bool
    {
        return $this->hasProperty('global');
    }
}
