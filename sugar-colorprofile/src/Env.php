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
 *    snapshot built as `array_merge($_ENV, getenv() ?: [])`. The getenv()
 *    side is the right operand, so it wins on EVERY key the two sources
 *    share — and it also adds entries $_ENV omits. A value changed via
 *    putenv() after process start therefore overrides the $_ENV copy of
 *    the same key; that precedence is the contract, not an accident of
 *    which source happens to carry the key.
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
     * Keys are matched case-exactly — an intentional policy, not an accident
     * of array lookup. On Windows the environment block is case-insensitive
     * at the OS layer, so an unusual spelling (`No_Color`, `term`) can be
     * present in the shell's view and still miss every rung here; aliasing
     * keys would reorder resolution on POSIX to fix an unverifiable-from-CI
     * platform, so the caveat is documented instead (CP-40, sugar-colorprofile-049).
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
     * The lookup is case-exact like {@see self::resolve()} — see the CP-40
     * platform caveat there (`No_Color` misses on Windows by design).
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
     * The force-value grammar shared by every CLICOLOR_FORCE reader: a value
     * forces when it is SET and is neither the empty string nor the exact
     * falsy spelling '0' — the termenv/chafa rule ("any non-empty != 0"),
     * which the README's chalk-escape-hatch justification invokes while the
     * old literal '1'-only reading silently stripped color for the
     * `CLICOLOR_FORCE=true|yes|on|2` spellings real dotfiles and CI images
     * export. Only '0' and unset/empty are falsy; every other non-empty
     * string forces. Env owns this single rule so the three readers —
     * DetectionChain step 1, TerminalProbe's capability mapping and
     * Probe::isForceColor — cannot drift apart (XPLAT-02).
     */
    public static function isForceValue(?string $value): bool
    {
        return $value !== null && $value !== '' && $value !== '0';
    }

    /**
     * Make an untrusted environment value safe to interpolate into a source
     * string: strip C0/C1/DEL control bytes (which could forge escape
     * sequences downstream consumers print) and cap the length.
     *
     * The output is guaranteed valid UTF-8 of at most
     * {@see SOURCE_MAX_BYTES} bytes. Both halves of that guarantee needed
     * repair: a byte-wise control sweep strips the 0x80-0x9F BYTES, which
     * are legal continuation bytes of valid characters (the euro sign
     * `E2 82 AC` lost its middle byte, orphaning `E2 AC`), and the result
     * then broke `json_encode()` of reports carrying it; a plain `substr()`
     * cap could split a character straddling the boundary. The /u pattern
     * matches C1 as CODE POINTS (U+0080-U+009F) instead, malformed input
     * (where /u refuses to match at all) is scrubbed by a byte pass plus
     * `mb_convert_encoding()`'s substitution of invalid sequences, and the
     * cap runs through `mb_strcut()` so it lands on a character boundary.
     */
    public static function sanitize(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $value);

        if ($clean === null) {
            // Invalid UTF-8 in: /u-mode matching refuses the whole subject.
            // Sweep the control bytes byte-wise, then let
            // mb_convert_encoding() replace every remaining invalid
            // sequence. The byte sweep may clip a valid character riding
            // inside the garbage (0x80-0x9F are legal continuation bytes);
            // inside hostile malformed input that costs substitution of
            // that character, never invalid output.
            $byteSwept = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $value) ?? '';
            $clean = \mb_convert_encoding($byteSwept, 'UTF-8', 'UTF-8');
        }

        return \mb_strcut($clean, 0, self::SOURCE_MAX_BYTES, 'UTF-8');
    }
}
