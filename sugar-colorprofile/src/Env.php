<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

/**
 * Single environment-resolution rule shared by every detection surface.
 *
 * Before this class existed, Palette applied a per-key overlay
 * ($env[$key] ?? $_ENV[$key] ?? getenv($key)) while DetectionChain replaced the
 * whole map when non-empty and TerminalProbe merged getenv over $_ENV — the
 * same environment could answer differently depending on which surface you
 * asked. This is the one rule, used by all four surfaces:
 *
 *  - A NON-EMPTY injected map is a COMPLETE, hermetic replacement. Ambient
 *    variables do NOT leak in. Callers who want layering merge explicitly.
 *  - An EMPTY injected map means "use the ambient environment": a single
 *    snapshot of $_ENV with getenv() values merged on top (getenv wins only
 *    where $_ENV lacks the key, because getenv() adds entries $_ENV omits).
 *
 * Values are always read via {@see self::value()} so that a false from a
 * hypothetical non-string source normalizes to null, matching the
 * array_key_exists-plus-null semantics every rung expects.
 *
 * {@see self::sanitize()} bounds untrusted interpolations (TERM and friends
 * are attacker-influenced over SSH) before they ride in source strings.
 */
final class Env
{
    /** Maximum length of a sanitized interpolation before it enters a source string. */
    public const SOURCE_MAX_BYTES = 128;

    private function __construct()
    {
    }

    /**
     * Resolve the effective environment map for one detection run.
     *
     * @param array<array-key, string|null> $injected Hermetic override map; [] = ambient.
     * @return array<string, string|null>
     */
    public static function resolve(array $injected): array
    {
        if ($injected !== []) {
            /** @var array<string, string|null> $injected */
            return $injected;
        }

        /** @var array<string, string|null> $ambient */
        $ambient = array_merge($_ENV, getenv() ?: []);

        return $ambient;
    }

    /**
     * Read one variable from a resolved map, normalizing absent/false/null to null.
     *
     * @param array<string, string|null> $resolved
     */
    public static function value(array $resolved, string $key): ?string
    {
        $raw = $resolved[$key] ?? null;

        if ($raw === null || $raw === false) {
            return null;
        }

        return (string) $raw;
    }

    /**
     * Make an untrusted environment value safe to interpolate into a source
     * string: strip C0/C1/DEL control bytes (which could forge escape
     * sequences downstream consumers print) and cap the length.
     */
    public static function sanitize(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $value) ?? '';

        if (strlen($clean) > self::SOURCE_MAX_BYTES) {
            $clean = substr($clean, 0, self::SOURCE_MAX_BYTES);
        }

        return $clean;
    }
}
