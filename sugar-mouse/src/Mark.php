<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Core\Util\Sanitize;

/**
 * Wrap $content with sentinel zone markers so {@see Scanner} can later
 * extract bounding boxes without any external Manager wiring.
 *
 * Marker format — a sentinel tag pair around a short id:
 *
 *   U+E000 <id> U+E001 <content> U+E000 / <id> U+E001
 *
 * The U+E000 + id + U+E001 triple is the zone opening;
 * U+E000 / + id + U+E001 is the zone close.
 *
 * The sentinels are Private Use Area codepoints (U+E000/U+E001), not C0
 * controls or anything adjacent to them: they are ZERO WIDTH to the
 * scanner and normally render as nothing, but they are NOT
 * guaranteed invisible on every host — terminals fall back to rendering
 * unused PUA codepoints as boxes or Dotted Circle, and screen readers
 * announce them as garbage. Treat a frame that leaks markers as cosmetic
 * noise, never as hidden text. {@see Scan::strip()} removes them.
 *
 * Marking can be globally disabled (e.g. for non-interactive output such
 * as width-only measurement passes).  When {@see $enabled} is false,
 * {@see wrap()} returns $content byte-for-byte with no sentinels — but it
 * still validates id AND content, so a measurement pass and a marked render
 * agree on what input is acceptable.
 *
 * Mirrors bubblezone's Mark function.
 */
final class Mark
{
    /**
     * Allowed id charset — ASCII letters, digits, and the punctuation used by
     * real zone ids (`cell:5:3`, `crumb-2`, `a.b_c`).  Anchored with \A…\z
     * (NOT $, which would also match before a trailing newline) and matched
     * byte-wise (no /u) so any byte >= 0x80 is rejected.  This inherently bars
     * the PUA sentinel bytes U+E000 (\xEE\x80\x80) / U+E001 (\xEE\x80\x81) plus
     * any CSI/OSC/APC/control byte — an id carrying those would desync
     * {@see Scan::parse()} (a zone-marker injection).
     */
    private const ID_PATTERN = '/\A[A-Za-z0-9._:-]+\z/';

    /**
     * Upper bound on id length in bytes.  Ids are short identifiers
     * (`cell:5:3`, `crumb-2`); an oversized id only bloats every emitted
     * sentinel and the scanner's forward id-terminator search.
     */
    public const MAX_ID_BYTES = 256;

    /**
     * Upper bound on wrapped-content length in bytes.  A single zone wraps
     * one widget's rendered cells (with SGR), so 1 MiB is far above any real
     * terminal region.  The guard bounds {@see Scan::parse()}'s work when a
     * caller reflects untrusted text through {@see wrap()} (mild-DoS defence:
     * an unbounded region would give an attacker an arbitrarily large buffer
     * to scan).
     */
    public const MAX_CONTENT_BYTES = 1_048_576;

    /**
     * Raw constructor — kept public only for the existing `new Mark()`
     * call sites across the legacy monorepo. New code takes
     * {@see new()} / {@see disabled()}; see also {@see withEnabled()}.
     *
     * @internal Prefer {@see new()} (house factory idiom).
     *
     * @param bool $enabled Whether sentinels are emitted.  Defaults to true.
     *                     Set to false to suppress all sentinel output.
     */
    public function __construct(
        private readonly bool $enabled = true,
    ) {}

    /**
     * Factory — the default root: a Mark instance with marking enabled.
     *
     * Equivalent to `new Mark()`; the public constructor is kept for the
     * existing `new Mark()` call sites across the monorepo.
     */
    public static function new(): self
    {
        return new self(true);
    }

    /**
     * Factory — creates a Mark instance with marking disabled.
     */
    public static function disabled(): self
    {
        return new self(false);
    }

    /**
     * Return a new Mark with the given enabled state.
     */
    public function withEnabled(bool $enabled): self
    {
        if ($this->enabled === $enabled) {
            return $this;
        }
        return $this->mutate($enabled);
    }

    /**
     * Clone with explicit state — the single construction seam behind every
     * `with*()` setter (factories build directly; house fluent rule).
     */
    private function mutate(bool $enabled): self
    {
        return new self($enabled);
    }

    /**
     * Wrap $content with start / end sentinels for $id.
     *
     * When this instance was constructed with {@see $enabled} = false,
     * returns $content unchanged (no sentinels emitted) — after validating.
     *
     * Trust boundary.  The sentinels use private-use codepoints (U+E000 /
     * U+E001).  Ids are charset-validated so an id can never carry them, but
     * CONTENT legitimately may: nesting a marked child inside a marked parent
     * is a supported pattern.  That same freedom means content that carries
     * foreign sentinels (pasted glyphs, upstream renders, reflected model
     * output) will forge zones at parse time — sweeping untrusted text is the
     * consumer's duty, and {@see self::untrusted()} is the tool: pass any
     * untrusted string through it before wrapping it (or before composing it
     * into a marked frame). Per the fleet trust-boundary story
     * (docs/TRUST-BOUNDARY.md in the sugarcraft monorepo — not a link
     * relative to this split repo).
     *
     * @throws \InvalidArgumentException When $id contains any byte outside
     *         {@see self::ID_PATTERN} — e.g. a sentinel byte, a control
     *         byte, or whitespace — which would otherwise let a caller-supplied
     *         id inject spurious zone markers into the scanned stream; or when
     *         $id / $content exceed {@see self::MAX_ID_BYTES} /
     *         {@see self::MAX_CONTENT_BYTES} (bounds the scanner's work on
     *         reflected untrusted text).
     */
    public function wrap(string $id, string $content): string
    {
        // Validate unconditionally (even when disabled): an out-of-charset id
        // is a caller bug regardless of whether sentinels are emitted, and the
        // same id feeds both the marked render and any measurement pass.
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(Lang::t('mark.id_invalid', [
                'pattern' => self::ID_PATTERN,
                'id'      => self::escapeForMessage($id),
            ]));
        }

        // Length guards — also unconditional so a disabled measurement pass and
        // an enabled render agree on what is acceptable.  Bounds the id-field
        // search and the total buffer Scan::parse() must walk.
        $idLen = strlen($id);
        if ($idLen > self::MAX_ID_BYTES) {
            throw new \InvalidArgumentException(Lang::t('mark.id_too_long', [
                'max' => self::MAX_ID_BYTES,
                'len' => $idLen,
            ]));
        }
        $contentLen = strlen($content);
        if ($contentLen > self::MAX_CONTENT_BYTES) {
            throw new \InvalidArgumentException(Lang::t('mark.content_too_long', [
                'max' => self::MAX_CONTENT_BYTES,
                'len' => $contentLen,
            ]));
        }

        if ($this->enabled === false) {
            return $content;
        }

        return Sentinel::OPEN
            . $id
            . Sentinel::CLOSE
            . $content
            . Sentinel::OPEN
            . '/'
            . $id
            . Sentinel::CLOSE;
    }

    /**
     * Whether $id is a zone id {@see wrap()} would accept: non-empty, within
     * {@see self::MAX_ID_BYTES}, and every byte inside {@see self::ID_PATTERN}.
     *
     * The single source of truth for both sides of the markup:
     * {@see wrap()} enforces it when encoding and {@see Scan::parse()} uses it
     * when decoding, so a U+E000 … U+E001 span that Mark could never have
     * emitted is treated as stray sentinels, not as a zone tag.
     */
    public static function isValidId(string $id): bool
    {
        return strlen($id) <= self::MAX_ID_BYTES && preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Sweep zone sentinels out of an UNTRUSTED string before it is composed
     * into a marked frame.
     *
     * This is the mechanical half of the consumer sweep duty documented on
     * {@see wrap()}: paste, model output, or any upstream render can carry
     * U+E000 / U+E001 bytes (or full forged tags with charset-valid ids), and
     * a bare {@see Scan::parse()} will register them as live zones. Passing
     * each untrusted leaf through here removes exactly the two sentinel
     * codepoints and changes nothing else — ids, ANSI, ordinary private-use
     * glyphs outside U+E000/U+E001, and legitimate nested {@see wrap()}
     * output from a trusted component all survive byte-for-byte where they
     * should. (Delegates to sugar-core's surgical
     * {@see \SugarCraft\Core\Util\Sanitize::stripZoneSentinels()}.)
     */
    public static function untrusted(string $content): string
    {
        return Sanitize::stripZoneSentinels($content);
    }

    /**
     * Quoted single-line repr of $value for exception messages: printable
     * ASCII passes through (with quote/backslash escapes and \n \r \t), every
     * other byte becomes \xHH. var_export()/print_r() re-emit control and
     * UTF-8 bytes RAW into the message — a hostile id would smuggle terminal
     * traffic into whoever logs or prints the refusal. This never does.
     */
    public static function escapeForMessage(string $value): string
    {
        $out = "'";
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            $b = ord($c);
            $out .= match (true) {
                $c === "'"  => "\\'",
                $c === "\\" => '\\\\',
                $c === "\n" => '\n',
                $c === "\r" => '\r',
                $c === "\t" => '\t',
                $b >= 0x20 && $b <= 0x7e => $c,
                default    => sprintf('\\x%02X', $b),
            };
        }
        return $out . "'";
    }

    /**
     * Static convenience alias — creates a temporary instance and calls wrap().
     * Uses an enabled instance so sentinel output is always produced.
     */
    public static function zone(string $id, string $content): string
    {
        return (new self(true))->wrap($id, $content);
    }
}
