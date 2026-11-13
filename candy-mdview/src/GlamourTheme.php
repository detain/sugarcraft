<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

use SugarCraft\Core\Util\Color;
use SugarCraft\Markdown\Theme;
use SugarCraft\Style\Style;

/**
 * Parser + adapter for Glamour STYLE JSON documents.
 *
 * Mirrors charmbracelet/glamour's style-file schema: a top-level object of
 * element blocks (`document`, `h1`…`h6`, `paragraph`, `block_quote`,
 * `code_block`, `codespan`, `strong`, `emph`, `link`, `hr`, …), each a
 * StylePrimitive carrying colours, attribute flags, `block_prefix` /
 * `block_suffix`, `indent` / `indent_token` and `margin`, plus a sibling
 * `chroma` block mapping chroma token names (`Keyword`, `LiteralString`,
 * `Comment`, `LiteralNumber`, …) to their own primitives.
 *
 * HISTORY, and the rule this class exists to obey: the original
 * `GlamourTheme` parsed a FLAT one-level shape nothing upstream
 * emitted, was never wired into the live CLI path, and was deleted as dead
 * code. This rewrite keeps the parsed primitives
 * addressable, converts them to a sugar-markdown {@see Theme} via
 * {@see toMarkdownTheme()}, and IS consumed: {@see RenderCommand::loadThemeConfig()}
 * sniffs the `document` signature and routes glamour-shaped
 * `--theme-config` files through here, so the adapter can never rot unused
 * again.
 *
 * WHAT THE ADAPTER CARRIES: colours and attribute flags, the document's
 * `block_prefix` / `block_suffix` / `indent` / `margin` (into Theme's
 * glamour-parity slots), `paragraph` block affixes (into
 * paragraphPrefix/Suffix, which sugar-markdown prepends per paragraph), and
 * the chroma families Keyword / LiteralString* / LiteralNumber* / Comment*
 * (into Theme's four syntax-token slots).
 *
 * WHAT IT DELIBERATELY DROPS, and why: line-control `block_prefix` /
 * `block_suffix` values on NON-document, NON-paragraph elements (glamour
 * uses "\n" there purely for vertical spacing sugar-markdown manages
 * itself), the `image` / `table_header` / `table_row` / `table_cell` /
 * `table_separator` styles (sugar-markdown keeps its own slots for these
 * but the adapter has no mapping decided — mapping them would change what
 * existing style files render, so they are DISCLOSED here and stay
 * addressable rather than silently repurposed), per-heading-level
 * prefixes (sugar-markdown exposes one global headingPrefix whose semantics
 * are a visible glyph, not a newline), and chroma families beyond the four
 * slots (no target field exists). Nothing is silently mis-mapped: dropped
 * keys stay readable on this object via {@see element()} / {@see chroma()},
 * so a future richer Theme slot can adopt them without re-parsing.
 *
 * HYGIENE AT THE PARSE DOOR: the four decoration slots that cross into
 * Theme accept only inert whitespace ([\n\t ]*) — style files are input,
 * and everything else is an escape-sequence injection channel; the two
 * decoration integers (`document.indent` / `document.margin`) refuse values
 * over {@see Theme::MAX_DOCUMENT_DECORATION} by name instead of multiplying
 * into a str_repeat blowup.
 *
 * Colour vocabulary: `#rrggbb` / `#rgb` hex (invalid hex THROWS — fail fast
 * on a corrupt file), `"N"` numeric strings / ints as xterm-256 indices
 * (0-255; out-of-range digits are ignored rather than guessed), and
 * `"auto"` / names (no target representation) resolve to no colour.
 */
final class GlamourTheme
{
    /**
     * Hard ceiling on theme-config bytes any GlamourTheme door will read.
     * Shared by {@see fromFile()} and RenderCommand's stream-capped read so
     * the two entrances cannot drift into different size policies.
     */
    public const MAX_CONFIG_BYTES = 1_000_000;

    /**
     * @param array<string, array<string, mixed>> $elements  element name => StylePrimitive map
     * @param array<string, array<string, mixed>> $chroma    chroma token => StylePrimitive map
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
     * top-level `document` OBJECT. The flat per-element colour map sugar-markdown's
     * {@see Theme::fromJsonString()} consumes has no `document` key, so the
     * two schemas never collide.
     *
     * @param array<string, mixed> $decoded
     */
    public static function isGlamourSchema(array $decoded): bool
    {
        return isset($decoded['document']) && is_array($decoded['document']);
    }

    /**
     * Parse a glamour JSON file. Unreadable paths raise
     * `InvalidArgumentException` with the shared theme-config wording.
     */
    public static function fromFile(string $path): self
    {
        if (is_readable($path) === false) {
            throw new \InvalidArgumentException(Lang::t('render.theme_config_unreadable', ['path' => $path]));
        }
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_CONFIG_BYTES) {
            throw new \InvalidArgumentException(Lang::t('render.theme_config_too_large', [
                'path'  => $path,
                'limit' => self::MAX_CONFIG_BYTES,
            ]));
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException(Lang::t('render.theme_config_unreadable', ['path' => $path]));
        }

        return self::fromJsonString($raw);
    }

    public static function fromJsonString(string $json): self
    {
        $decoded = json_decode($json, associative: true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(Lang::t('glamour.json_invalid'));
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(Lang::t('glamour.json_object'));
        }

        return self::fromDecoded($decoded);
    }

    /**
     * Build from an already-decoded document — the path
     * {@see RenderCommand::loadThemeConfig()} takes so the byte-capped read
     * is decoded exactly once.
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

        // Parse-boundary hygiene: the four decoration slots that cross into
        // sugar-markdown's Theme are whitespace-only (finding 005), and the
        // two decoration integers are capped (finding 018). Refusing here —
        // at fromDecoded — covers EVERY entrance (fromFile, fromJsonString,
        // RenderCommand's stream route) with one law.
        $docPrefix  = self::whitespaceSlot($document['block_prefix'] ?? '', 'document.block_prefix');
        $docSuffix  = self::whitespaceSlot($document['block_suffix'] ?? '', 'document.block_suffix');
        self::whitespaceSlot($elements['paragraph']['block_prefix'] ?? '', 'paragraph.block_prefix');
        self::whitespaceSlot($elements['paragraph']['block_suffix'] ?? '', 'paragraph.block_suffix');

        return new self(
            blockPrefix:     $docPrefix,
            blockSuffix:     $docSuffix,
            documentIndent:  self::boundedDecoration($document['indent'] ?? 0, 'document.indent'),
            documentMargin:  self::boundedDecoration($document['margin'] ?? 0, 'document.margin'),
            elements:        $elements,
            chroma:          $chroma,
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
     * Convert to the sugar-markdown stylesheet the live renderer consumes.
     * Missing elements fall through to the plain {@see Style} — the same
     * default {@see Theme::fromJsonString()} gives its flat schema.
     */
    public function toMarkdownTheme(): Theme
    {
        $paragraph = $this->elements['paragraph'] ?? [];

        return new Theme(
            heading1:          $this->style('h1') ?? $this->style('heading') ?? Style::new(),
            heading2:          $this->style('h2') ?? $this->style('heading') ?? Style::new(),
            heading3:          $this->style('h3') ?? $this->style('heading') ?? Style::new(),
            heading4:          $this->style('h4') ?? $this->style('heading') ?? Style::new(),
            heading5:          $this->style('h5') ?? $this->style('heading') ?? Style::new(),
            heading6:          $this->style('h6') ?? $this->style('heading') ?? Style::new(),
            paragraph:         $this->style('paragraph') ?? Style::new(),
            bold:              $this->style('strong') ?? Style::new()->bold(),
            italic:            $this->style('emph') ?? Style::new()->italic(),
            code:              $this->style('codespan') ?? Style::new(),
            codeBlock:         $this->style('code_block') ?? Style::new(),
            link:              $this->style('link') ?? Style::new(),
            blockquote:        $this->style('block_quote') ?? $this->style('blockquote') ?? Style::new(),
            listMarker:        $this->style('list') ?? Style::new(),
            rule:              $this->style('hr') ?? Style::new(),
            keyword:           $this->chromaStyle('Keyword'),
            string:            $this->chromaStyle('LiteralString') ?? $this->chromaStyle('String'),
            number:            $this->chromaStyle('LiteralNumber') ?? $this->chromaStyle('Number'),
            comment:           $this->chromaStyle('Comment'),
            strike:            $this->style('strikethrough'),
            documentMargin:    $this->documentMargin,
            documentIndent:    $this->documentIndent,
            paragraphPrefix:   self::whitespaceSlot($paragraph['block_prefix'] ?? '', 'paragraph.block_prefix'),
            paragraphSuffix:   self::whitespaceSlot($paragraph['block_suffix'] ?? '', 'paragraph.block_suffix'),
            documentBlockPrefix: $this->blockPrefix,
            documentBlockSuffix: $this->blockSuffix,
        );
    }

    /**
     * The decoration law for the four slots that cross into Theme (finding
     * 005): glamour uses document/paragraph `block_prefix` / `block_suffix`
     * for VERTICAL SPACING only, so exactly the inert whitespace class
     * [\n\t ]* is accepted. Anything else is an injection channel — before
     * this guard a style file could smuggle a bare OSC 0 title-set,
     * arbitrary SGR, or a forged OSC 8 envelope (past the renderer's
     * refused-scheme gate) into every rendered frame, rc 0. Non-strings
     * still coerce to '' (malformed shape reads as empty, not fatal — the
     * array case cannot carry a payload); payload-bearing strings refuse by
     * naming the slot.
     */
    private static function whitespaceSlot(mixed $value, string $slot): string
    {
        if (!is_string($value)) {
            return '';
        }

        if (preg_match('/\A[\n\t ]*\z/', $value) === 1) {
            return $value;
        }

        throw new \InvalidArgumentException(Lang::t('glamour.decoration_unsafe', ['slot' => $slot]));
    }

    /**
     * Non-negative int with the shared decoration ceiling (finding 018 —
     * sugar-markdown's Theme::MAX_DOCUMENT_DECORATION is the single source
     * of truth the lib's own constructor saturates to). At this door an
     * over-cap value REFUSES with its name rather than saturating: a style
     * file asking for a 1.5-billion-cell indent is broken, and silence is
     * the wrong answer for the one door that speaks to the user.
     */
    private static function boundedDecoration(mixed $value, string $key): int
    {
        $number = self::nonNegativeInt($value);

        if ($number > Theme::MAX_DOCUMENT_DECORATION) {
            throw new \InvalidArgumentException(Lang::t('glamour.value_too_large', [
                'key'   => $key,
                'limit' => Theme::MAX_DOCUMENT_DECORATION,
            ]));
        }

        return $number;
    }

    /** Parsed Style for one element block, null when the block is absent. */
    private function style(string $element): ?Style
    {
        $primitive = $this->elements[$element] ?? null;

        return $primitive === null ? null : self::styleFrom($primitive);
    }

    /**
     * Resolve one chroma family to a Style: the exact token wins over the
     * first subtype (`Keyword` beats `KeywordReserved`), subtypes match by
     * prefix in document order — deterministic, since PHP preserves JSON key
     * order through the associative decode.
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
