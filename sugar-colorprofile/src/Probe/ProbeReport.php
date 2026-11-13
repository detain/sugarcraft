<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Probe;

use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Env;
use SugarCraft\ColorProfile\Lang;

/**
 * Readonly value object holding the result of a terminal capability probe.
 *
 * Each detected capability is stored with its source string indicating
 * how it was discovered: "env:VAR[=value]", "terminfo:cap" or
 * "fallback:<reason>". Sources arriving from the probe (or from a caller
 * fabricating a report by hand) are sanitized at this boundary: TERM and
 * friends are attacker-influenced over SSH, and reports are printed by
 * diagnostics — no control byte may ride through here.
 *
 * Internally uses string keys (the Capability enum's string value) for
 * PHP compatibility, but exposes enum-based accessor methods. Keys are
 * parsed into the enum at construction — an unknown key is a boundary
 * error, never a value that survives to surprise {@see all()} later.
 *
 * @readonly
 */
final readonly class ProbeReport
{
    /**
     * Sanitized map of capability string key to its source string.
     *
     * Private since CP-51 (sugar-colorprofile-064): the raw string-keyed map
     * was a public second door competing with the typed ones — {@see all()}
     * for enum keys, {@see allWithSource()} for the map plus its sources.
     * Legacy `$report->capabilities` reads still answer through the
     * deprecated {@see __get()} shim below.
     */
    private array $capabilities;

    /**
     * @param array<string, string>  $capabilities  Map of capability string key to its source string
     * @param \DateTimeImmutable     $detectedAt    Timestamp of probe execution
     *
     * @throws \InvalidArgumentException when a key is not a known Capability value
     */
    public function __construct(
        array $capabilities,
        public \DateTimeImmutable $detectedAt = new \DateTimeImmutable(),
    ) {
        $unknown = [];
        foreach (array_keys($capabilities) as $key) {
            try {
                Capability::from((string) $key);
            } catch (\ValueError) {
                $unknown[] = (string) $key;
            }
        }
        if ($unknown !== []) {
            throw new \InvalidArgumentException(Lang::t('probe.unknown_capability', ['keys' => implode(', ', $unknown)]));
        }

        // Belt sanitization (DM19): the detection chain already cleans its own
        // source strings, but a hand-built report — e.g. one replayed from a
        // captured hostile TERM — must not smuggle escape bytes into whatever
        // prints this later.
        $clean = [];
        foreach ($capabilities as $key => $source) {
            $clean[(string) $key] = Env::sanitize((string) $source);
        }
        $this->capabilities = $clean;
    }

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
     * Source format: "env:VAR[=value]", "terminfo:cap" or "fallback:<reason>".
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
            // Safe: the constructor already rejected unknown keys.
            $result[] = Capability::from((string) $key);
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
     * One-release deprecation shim for the pre-CP-51 public map read
     * (`$report->capabilities`) — answers the map and raises
     * E_USER_DEPRECATED. Removal target: 2.0. New code must use
     * {@see all()} or {@see allWithSource()}; writes never answered here
     * (the class is readonly, so `$report->capabilities = …` throws
     * regardless of this shim).
     */
    public function __get(string $name): mixed
    {
        if ($name === 'capabilities') {
            \trigger_error(
                'Reading ProbeReport::$capabilities directly is deprecated; use all() or allWithSource() (removal in 2.0).',
                \E_USER_DEPRECATED,
            );

            return $this->capabilities;
        }

        throw new \Error(\sprintf('Undefined property: %s::$%s', static::class, $name));
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
     * Bridge the capability set onto the emission-side profile enum.
     *
     * The probe reports speak capabilities; renderers speak ColorProfile.
     * This is the canonical translation so consumers never re-derive the
     * ladder by hand. Precedence follows the color rungs; NoColor and a
     * bare BasicAscii floor map onto the colorless Ascii rung — since CP-05
     * suppression is an opt-out from COLOR, not from ANSI, and routing the
     * NoColor capability back onto NoTTY erased the one vocabulary
     * distinction renderers still had (CP-20; the NoTTY name is hardware
     * absence only, and DetectionChain::userOptedOut() carries the WHY).
     * An empty report means nothing was observed at all, which is the chain
     * fallback's answer (Ansi) — not a claim of monochrome.
     */
    public function toColorProfile(): ColorProfile
    {
        return match (true) {
            $this->has(Capability::TrueColor) => ColorProfile::TrueColor,
            $this->has(Capability::Color256) => ColorProfile::Ansi256,
            $this->has(Capability::Color16) => ColorProfile::Ansi,
            $this->has(Capability::NoColor) => ColorProfile::Ascii,
            $this->capabilities === [] => ColorProfile::Ansi,
            default => ColorProfile::Ascii,
        };
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
