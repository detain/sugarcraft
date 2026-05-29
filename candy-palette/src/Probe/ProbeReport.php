<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Probe;

/**
 * Readonly value object holding the result of a terminal capability probe.
 *
 * Each detected capability is stored with its source string indicating
 * how it was discovered: "env:VAR", "terminfo:cap", "escape:OSCn", "fallback".
 *
 * Internally uses string keys (the Capability enum's string value) for
 * PHP compatibility, but exposes enum-based accessor methods.
 *
 * @readonly
 */
final readonly class ProbeReport
{
    /**
     * @param array<string, string>  $capabilities  Map of capability string key to its source string
     * @param \DateTimeImmutable     $detectedAt    Timestamp of probe execution
     */
    public function __construct(
        public array $capabilities,
        public \DateTimeImmutable $detectedAt = new \DateTimeImmutable(),
    ) {}

    /**
     * Check if a capability was detected.
     */
    public function has(Capability $cap): bool
    {
        return isset($this->capabilities[$cap->value]);
    }

    /**
     * Get the source string for a detected capability.
     *
     * Returns null if the capability was not detected.
     * Source format: "env:VAR", "terminfo:cap", "escape:OSCn", "fallback"
     */
    public function source(Capability $cap): ?string
    {
        return $this->capabilities[$cap->value] ?? null;
    }

    /**
     * Return all detected capabilities as a list.
     *
     * @return list<Capability>
     */
    public function all(): array
    {
        $result = [];
        foreach (array_keys($this->capabilities) as $key) {
            $result[] = Capability::from($key);
        }
        return $result;
    }

    /**
     * Return all detected capabilities with their sources.
     *
     * @return array<string, string>
     */
    public function allWithSource(): array
    {
        return $this->capabilities;
    }

    /**
     * Check if the probe detected TrueColor support.
     */
    public function hasTrueColor(): bool
    {
        return $this->has(Capability::TrueColor);
    }

    /**
     * Check if the probe detected 256-color support.
     */
    public function hasColor256(): bool
    {
        return $this->has(Capability::Color256);
    }

    /**
     * Check if colors are disabled.
     */
    public function hasNoColor(): bool
    {
        return $this->has(Capability::NoColor);
    }

    /**
     * Return a human-readable description of the highest color capability.
     */
    public function colorDescription(): string
    {
        if ($this->has(Capability::TrueColor)) {
            return '24-bit TrueColor (16.7 million colors)';
        }
        if ($this->has(Capability::Color256)) {
            return '256-color ANSI';
        }
        if ($this->has(Capability::Color16)) {
            return '16-color ANSI';
        }
        if ($this->has(Capability::NoColor)) {
            return 'No color support';
        }
        return 'Basic ASCII';
    }
}
