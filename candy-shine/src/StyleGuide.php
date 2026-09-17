<?php

declare(strict_types=1);

namespace SugarCraft\Shine;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

/**
 * Parser + producer for Glamour STYLE JSON documents (E736 shine 7.1).
 *
 * Mirrors charmbracelet/glamour's style-file schema: a top-level object of
 * element blocks (`document`, `h1`…`h6`, `paragraph`, `block_quote`,
 * `code_block`, `codespan`, `strong`, `emph`, `link`, `hr`, …), each a
 * StylePrimitive carrying colours, attribute flags, `block_prefix` /
 * `block_suffix`, `indent` / `indent_token` and `margin`, plus a sibling
 * `chroma` block mapping chroma token names (`Keyword`, `LiteralString`,
 * `Comment`, `LiteralNumber`, …) to their own primitives.
 *
 * DIVERGENCE, stated honestly: sugar-glow ships the same vocabulary as the
 * adapter {@see \SugarCraft\Glow\GlamourTheme} (it converts parsed
 * primitives into a shine Theme on the consumer side). This class is NOT a
 * re-export of it and cannot be — sugar-glow REQUIRES candy-shine, so a
 * shine→glow dependency would mint a sibling require-cycle (r88 design
 * ruling, reaffirmed r89): the producer never depends on its consumer. The
 * decode vocabulary is therefore duplicated locally; the two families share
 * the schema contract, not the code. Glow's adapter can in time delegate
 * here (consumer→producer is the lawful direction) — deliberately not
 * touched in this lane so the glow suite carries unchanged.
 *
 * Being the producer's own decoder, {@see theme()} lands values directly
 * in their native {@see Theme} slots; nothing needs an adapter hop.
 *
 * WHAT DECODES: colours and attribute flags, the document's
 * `block_prefix` / `block_suffix` / `indent` / `margin`, `paragraph` block
 * affixes, and the chroma families Keyword / LiteralString / LiteralNumber
 * / Comment.
 *
 * WHAT THEME() DROPS, and why: line-control `block_prefix` /
 * `block_suffix` on NON-document elements (glamour uses "\n" there purely
 * for vertical spacing this renderer manages itself), per-heading-level
 * prefixes (shine exposes one global headingPrefix whose semantics are a
 * visible glyph, not a newline), and chroma families beyond the four slots
 * (no target field exists). Nothing is silently lost: dropped keys stay
 * addressable on this object via {@see element()} / {@see chroma()}, so a
 * future richer Theme slot can adopt them without re-parsing.
 *
 * Colour vocabulary: `#rrggbb` / `#rgb` hex (invalid hex THROWS — fail fast
 * on a corrupt file), `"N"` numeric strings / ints as xterm-256 indices
 * (0-255; out-of-range numbers are ignored rather than guessed), and
 * `"auto"` / named colours (no target representation) resolve to no colour.
 *
 * Mirrors charmbracelet/glamour's styles.JSON style-file reader.
 */
final class StyleGuide
{
    /**
     * @param string $blockPrefix    document `block_prefix` literal.
     * @param string $blockSuffix    document `block_suffix` literal.
     * @param int    $documentIndent coerced non-negative document `indent`.
     * @param int    $documentMargin coerced non-negative document `margin`.
     * @param array<string, array<string, mixed>> $elements element name => StylePrimitive map.
     * @param array<string, array<string, mixed>> $chroma   chroma token => StylePrimitive map.
     */
    public function __construct(
        public readonly string $blockPrefix = '',
        public readonly string $blockSuffix = '',
        public readonly int $documentIndent = 0,
        public readonly int $documentMargin = 0,
        public readonly array $elements = [],
        public readonly array $chroma = [],
    ) {}

    /**
     * True when a decoded JSON document carries the glamour signature — a
     * top-level `document` OBJECT. The flat per-element colour map
     * {@see Theme::fromJsonString()} consumes has no `document` key, so the
     * two schemas never collide and callers may sniff with this predicate.
     *
     * @param array<string, mixed> $decoded
     */
    public static function isGlamourSchema(array $decoded): bool
    {
        return isset($decoded['document']) && is_array($decoded['document']);
    }

    /**
     * Parse a glamour JSON file. An unreadable path raises
     * `InvalidArgumentException` before any decode: the door probes
     * readability, then the read itself is guarded `@` + `=== false` — the
     * r83-s2 idiom keeping the probe→read race net silent-but-fatal rather
     * than warning-emitting.
     */
    public static function fromFile(string $path): self
    {
        if (is_readable($path) === false) {
            throw new \InvalidArgumentException(Lang::t('theme.read_failed', ['path' => $path]));
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException(Lang::t('theme.read_failed', ['path' => $path]));
        }

        return self::fromJsonString($raw);
    }

    public static function fromJsonString(string $json): self
    {
        $decoded = json_decode($json, associative: true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(Lang::t('theme.json_invalid'));
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(Lang::t('theme.json_object'));
        }

        return self::fromDecoded($decoded);
    }

    /**
     * Build from an already-decoded document — the path a caller that
     * sniffed via {@see isGlamourSchema()} takes, so the file is decoded
     * exactly once.
     *
     * @param array<string, mixed> $decoded
     */
    public static function fromDecoded(array $decoded): self
    {
        /** @var array<string, array<string, mixed>> $elements */
        $elements = [];
        /** @var array<string, array<string, mixed>> $chroma */
        $chroma = [];

        foreach ($decoded as $name => $primitive) {
            if (!is_string($name) || !is_array($primitive)) {
                continue;
            }
            if ($name === 'chroma') {
                foreach ($primitive as $token => $tokenStyle) {
                    if (is_string($token) && is_array($tokenStyle)) {
                        $chroma[$token] = $tokenStyle;
                    }
                }
                continue;
            }
            $elements[$name] = $primitive;
        }

        $document = $elements['document'] ?? [];

        return new self(
            blockPrefix:    (string) ($document['block_prefix'] ?? ''),
            blockSuffix:    (string) ($document['block_suffix'] ?? ''),
            documentIndent: self::nonNegativeInt($document['indent'] ?? 0),
            documentMargin: self::nonNegativeInt($document['margin'] ?? 0),
            elements:       $elements,
            chroma:         $chroma,
        );
    }

    /** Raw StylePrimitive for an element block, null when absent. */
    public function element(string $name): ?array
    {
        return $this->elements[$name] ?? null;
    }

    /** Raw StylePrimitive for a chroma token, null when absent. */
    public function chroma(string $token): ?array
    {
        return $this->chroma[$token] ?? null;
    }

    /**
     * The `indent_token` literal for an element (glamour's `block_quote`
     * carries "│ ", stock `code_block` carries four spaces). The `document`
     * block falls back to {@see $documentIndent} plain cells when it sets
     * `indent` without a token; every other element answers '' when unset.
     */
    public function indentToken(string $element = 'document'): string
    {
        $token = $this->elements[$element]['indent_token'] ?? null;
        if (is_string($token)) {
            return $token;
        }

        return $element === 'document' ? str_repeat(' ', $this->documentIndent) : '';
    }

    /**
     * Materialise the native {@see Theme}. Missing elements fall through to
     * the plain Style — the same default {@see Theme::fromJsonString()}
     * gives its flat schema — except `bold` / `italic`, which fall through
     * to the actual SGR attribute glamour's stock styles give them.
     */
    public function theme(): Theme
    {
        $paragraph = $this->elements['paragraph'] ?? [];

        return new Theme(
            heading1:            $this->style('h1') ?? $this->style('heading') ?? Style::new(),
            heading2:            $this->style('h2') ?? $this->style('heading') ?? Style::new(),
            heading3:            $this->style('h3') ?? $this->style('heading') ?? Style::new(),
            heading4:            $this->style('h4') ?? $this->style('heading') ?? Style::new(),
            heading5:            $this->style('h5') ?? $this->style('heading') ?? Style::new(),
            heading6:            $this->style('h6') ?? $this->style('heading') ?? Style::new(),
            paragraph:           $this->style('paragraph') ?? Style::new(),
            bold:                $this->style('strong') ?? Style::new()->bold(),
            italic:              $this->style('emph') ?? Style::new()->italic(),
            code:                $this->style('codespan') ?? Style::new(),
            codeBlock:           $this->style('code_block') ?? Style::new(),
            link:                $this->style('link') ?? Style::new(),
            blockquote:          $this->style('block_quote') ?? $this->style('blockquote') ?? Style::new(),
            listMarker:          $this->style('list') ?? Style::new(),
            rule:                $this->style('hr') ?? Style::new(),
            keyword:             $this->chromaStyle('Keyword'),
            string:              $this->chromaStyle('LiteralString') ?? $this->chromaStyle('String'),
            number:              $this->chromaStyle('LiteralNumber') ?? $this->chromaStyle('Number'),
            comment:             $this->chromaStyle('Comment'),
            strike:              $this->style('strikethrough'),
            documentMargin:      $this->documentMargin,
            documentIndent:      $this->documentIndent,
            paragraphPrefix:     (string) ($paragraph['block_prefix'] ?? ''),
            paragraphSuffix:     (string) ($paragraph['block_suffix'] ?? ''),
            documentBlockPrefix: $this->blockPrefix,
            documentBlockSuffix: $this->blockSuffix,
        );
    }

    /** Parsed Style for one element block, null when the block is absent. */
    private function style(string $element): ?Style
    {
        $primitive = $this->elements[$element] ?? null;

        return $primitive === null ? null : self::styleFrom($primitive);
    }

    /**
     * Resolve one chroma family to a Style under the ORDERING LAW: the
     * exact token always wins over any subtype (`Keyword` beats
     * `KeywordReserved` even when the subtype is listed first), and subtypes
     * match by prefix in document order — deterministic, since PHP
     * preserves JSON key order through the associative decode.
     */
    private function chromaStyle(string $family): ?Style
    {
        $exact = $this->chroma[$family] ?? null;
        if (is_array($exact)) {
            return self::styleFrom($exact);
        }
        foreach ($this->chroma as $token => $primitive) {
            if (str_starts_with($token, $family)) {
                return self::styleFrom($primitive);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $primitive */
    private static function styleFrom(array $primitive): Style
    {
        $style = Style::new();

        $foreground = self::color($primitive['color'] ?? null);
        if ($foreground !== null) {
            $style = $style->foreground($foreground);
        }
        $background = self::color($primitive['background_color'] ?? null);
        if ($background !== null) {
            $style = $style->background($background);
        }

        if (!empty($primitive['bold'])) {
            $style = $style->bold();
        }
        if (!empty($primitive['italic'])) {
            $style = $style->italic();
        }
        if (!empty($primitive['underline'])) {
            $style = $style->underline();
        }
        if (!empty($primitive['strikethrough'])) {
            $style = $style->strikethrough();
        }
        if (!empty($primitive['faint'])) {
            $style = $style->faint();
        }

        return $style;
    }

    /** Glamour colour vocab: `#hex` (throws when malformed), 0-255 index, else none. */
    private static function color(mixed $value): ?Color
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= 255 ? Color::ansi256($value) : null;
        }
        if (!is_string($value)) {
            return null;
        }

        $spec = trim($value);
        if ($spec === '' || strcasecmp($spec, 'auto') === 0) {
            return null;
        }
        if ($spec[0] === '#') {
            return Color::hex($spec);
        }
        if (preg_match('/^\d{1,3}$/', $spec) === 1) {
            $index = (int) $spec;

            return $index <= 255 ? Color::ansi256($index) : null;
        }

        return null;
    }

    /** int | digit-string → non-negative int; anything else coerces to 0. */
    private static function nonNegativeInt(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return max(0, (int) $value);
        }

        return 0;
    }
}
