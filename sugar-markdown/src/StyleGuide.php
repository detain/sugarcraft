<?php

declare(strict_types=1);

namespace SugarCraft\Markdown;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Style\Style;

/**
 * Parser + producer for Glamour STYLE JSON documents.
 *
 * Mirrors charmbracelet/glamour's style-file schema: a top-level object of
 * element blocks (`document`, `h1`…`h6`, `paragraph`, `block_quote`,
 * `code_block`, `codespan`, `strong`, `emph`, `link`, `hr`, …), each a
 * StylePrimitive carrying colours, attribute flags, `block_prefix` /
 * `block_suffix`, `indent` / `indent_token` and `margin`, plus a sibling
 * `chroma` block mapping chroma token names (`Keyword`, `LiteralString`,
 * `Comment`, `LiteralNumber`, …) to their own primitives.
 *
 * DIVERGENCE, stated honestly: candy-mdview ships the same vocabulary as the
 * adapter {@see \SugarCraft\MdView\GlamourTheme} (it converts parsed
 * primitives into a sugar-markdown Theme on the consumer side). This class is NOT a
 * re-export of it and cannot be — candy-mdview REQUIRES sugar-markdown, so a
 * sugar-markdown→glow dependency would mint a sibling require-cycle: the
 * producer never depends on its consumer. The
 * decode vocabulary is therefore duplicated locally; the two families share
 * the schema contract, not the code. Glow's adapter can in time delegate
 * here (consumer→producer is the lawful direction).
 *
 * Being the producer's own decoder, {@see theme()} lands values directly
 * in their native {@see Theme} slots; nothing needs an adapter hop.
 *
 * WHAT DECODES: colours and attribute flags, the document's
 * `block_prefix` / `block_suffix` / `indent` / `margin`, `paragraph` block
 * affixes, the chroma families Keyword / LiteralString / LiteralNumber
 * / Comment, the `uppercase` / `lowercase` spellings on the heading
 * blocks (folded into the Theme's single `headingCase` selector), and
 * the `inline_image` / `block_image` primitives (folded into the Theme's
 * single `imageText` alt-text slot — inline wins when a document spells
 * both, mirroring the headingCase tie-break).
 *
 * WHAT THEME() DROPS, and why: line-control `block_prefix` /
 * `block_suffix` on NON-document elements (glamour uses "\n" there purely
 * for vertical spacing this renderer manages itself), per-heading-level
 * prefixes (sugar-markdown exposes one global headingPrefix whose semantics are a
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
     * Parse a glamour JSON file.
     *
     * A path that is not a regular file (missing, or a directory — which
     * `is_readable()` accepts and `file_get_contents()` reads as an empty
     * string, surfacing as a misleading "invalid JSON") raises
     * `RuntimeException` before any read. The read itself runs under a
     * scoped error handler rather than `@`: the warning PHP raises (an
     * unreadable file, a path swapped out after the probe) is captured into
     * the exception message instead of being suppressed or leaked to the
     * caller's error handler — the same contract as {@see Theme::fromJson()}.
     *
     * Trust note: a style file is code the terminal runs. Do not
     * auto-load workspace-adjacent style files; load only files the
     * operator pinned. Decoded string slots (`block_prefix` /
     * `block_suffix` / `indent_token`) are swept through
     * `Sanitize::untrusted()` so a hostile file cannot inject raw
     * escape sequences — stock glamour "\n"/space affixes survive the
     * sweep byte-identical.
     *
     * @throws \RuntimeException        when the file cannot be read
     * @throws \InvalidArgumentException when its contents are not a JSON object
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException(Lang::t('theme.read_failed', ['path' => $path]));
        }
        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $raw = file_get_contents($path);
        } finally {
            restore_error_handler();
        }
        // A read that warned is untrustworthy even when it returned a string
        // (a short read yields the bytes before the failure), so either
        // signal fails the load.
        if ($raw === false || $warning !== null) {
            $msg = $warning ?? 'unknown error';
            throw new \RuntimeException(Lang::t('theme.read_failed', ['path' => $path]) . ": {$msg}");
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

        // SEC-02 boundary: every decoded STRING slot goes through
        // Sanitize::untrusted(). A style file is code the terminal runs
        // — glamour ships prefixes like "\n" (kept: TAB/LF/CR survive the
        // sweep byte-identical) but a hostile file must never be able to
        // inject a raw OSC/CSI sequence into the render through a prefix,
        // a suffix or an indent token.
        return new self(
            blockPrefix:    Sanitize::untrusted((string) ($document['block_prefix'] ?? '')),
            blockSuffix:    Sanitize::untrusted((string) ($document['block_suffix'] ?? '')),
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
            // SEC-02: same sweep as the affix slots — glamour's stock "│ "
            // and four-space tokens pass through unchanged (no control
            // bytes in them), a hostile token cannot carry an escape.
            return Sanitize::untrusted($token);
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
            imageText:           $this->style('inline_image') ?? $this->style('block_image'),
            headingCase:         $this->headingCase(),
            documentMargin:      $this->documentMargin,
            documentIndent:      $this->documentIndent,
            paragraphPrefix:     Sanitize::untrusted((string) ($paragraph['block_prefix'] ?? '')),
            paragraphSuffix:     Sanitize::untrusted((string) ($paragraph['block_suffix'] ?? '')),
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
     * SM-S14: glamour spells heading casing as `uppercase` / `lowercase`
     * on the h1-h6 primitives (or the shared `heading` block); the Theme
     * carries one `headingCase` selector. A document that asks for both
     * lands on `upper` — house tie-break, stated in the README; glamour's
     * own corpora set exactly one flag per heading level, so upstream the
     * two spellings never collide and there is no behaviour to mirror.
     */
    private function headingCase(): string
    {
        $lower = false;
        foreach (['heading', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $element) {
            $primitive = $this->elements[$element] ?? [];
            if (self::flagOn($primitive['uppercase'] ?? null)) {
                return 'upper';
            }
            if (self::flagOn($primitive['lowercase'] ?? null)) {
                $lower = true;
            }
        }

        return $lower ? 'lower' : 'none';
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

    /**
     * Flag truth for a primitive's boolean slot (m12, shared law with
     * {@see Theme::flagOn()}): glamour JSON carries flags as real
     * booleans, but a `"false"` string must not switch one on.
     */
    private static function flagOn(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(strtolower($value), ['', 'false', '0', 'no', 'off'], true);
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        return false;
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

        if (self::flagOn($primitive['bold'] ?? null)) {
            $style = $style->bold();
        }
        if (self::flagOn($primitive['italic'] ?? null)) {
            $style = $style->italic();
        }
        if (self::flagOn($primitive['underline'] ?? null)) {
            $style = $style->underline();
        }
        if (self::flagOn($primitive['strikethrough'] ?? null)) {
            $style = $style->strikethrough();
        }
        if (self::flagOn($primitive['faint'] ?? null)) {
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
