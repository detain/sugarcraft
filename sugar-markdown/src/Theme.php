<?php

declare(strict_types=1);

namespace SugarCraft\Markdown;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Palettes;
use SugarCraft\Style\Style;

/**
 * A {@see Renderer} stylesheet — one {@see Style} per Markdown element
 * type. Themes are immutable; pick a built-in via {@see ansi()} /
 * {@see plain()} or build a custom one with the constructor.
 */
final class Theme
{
    /**
     * Stock-theme instance memo (SM-S53): every stock factory returns the
     * same fully-readonly value object. Themes are immutable, so sharing is
     * invisible to callers and lets Renderer::renderMarkdown() bundle-cache
     * its per-theme parse environment by object identity.
     *
     * @var array<string, self>
     */
    private static array $stockInstances = [];

    /**
     * Whether $theme is one of the memoised stock instances (identity, not
     * value equality — a custom theme that happens to match a stock theme's
     * bytes deliberately stays outside the render-bundle cache).
     */
    public static function isStock(self $theme): bool
    {
        return \in_array($theme, self::$stockInstances, true);
    }

    // ---- element slots (read the constructor for the canonical roster) ----
    // PHP forbids `new` in promoted-property defaults, so the fifteen
    // element styles are de-promoted and normalised to a no-op Style in
    // the constructor body — callers may now omit any slot they do not
    // style, and {@see with()} swaps exactly one slot off a stock theme.
    public readonly Style $heading1;
    public readonly Style $heading2;
    public readonly Style $heading3;
    public readonly Style $heading4;
    public readonly Style $heading5;
    public readonly Style $heading6;
    public readonly Style $paragraph;
    public readonly Style $bold;
    public readonly Style $italic;
    public readonly Style $code;
    public readonly Style $codeBlock;
    public readonly Style $link;
    public readonly Style $blockquote;
    public readonly Style $listMarker;
    public readonly Style $rule;

    public function __construct(
        ?Style $heading1 = null,
        ?Style $heading2 = null,
        ?Style $heading3 = null,
        ?Style $heading4 = null,
        ?Style $heading5 = null,
        ?Style $heading6 = null,
        ?Style $paragraph = null,
        ?Style $bold = null,
        ?Style $italic = null,
        ?Style $code = null,
        ?Style $codeBlock = null,
        ?Style $link = null,
        ?Style $blockquote = null,
        ?Style $listMarker = null,
        ?Style $rule = null,
        // ---- syntax-highlighting tokens (used inside fenced code blocks) ----
        public readonly ?Style $keyword = null,
        public readonly ?Style $string  = null,
        public readonly ?Style $number  = null,
        public readonly ?Style $comment = null,
        // ---- v2 element slots (lipgloss/glamour parity) ----
        /** Strike-through text — `~~foo~~`. */
        public readonly ?Style $strike = null,
        /** Visible label of a link, separate from the URL. */
        public readonly ?Style $linkText = null,
        /** `![alt](src)` — the alt text. */
        public readonly ?Style $image = null,
        /** Inline HTML block / span — `<details>` / `<kbd>`. */
        public readonly ?Style $htmlBlock = null,
        public readonly ?Style $htmlSpan  = null,
        /** Definition list term + body (when the markdown extension is loaded). */
        public readonly ?Style $definitionTerm        = null,
        public readonly ?Style $definitionDescription = null,
        /** Plain text node — most themes leave null and fall through to paragraph. */
        public readonly ?Style $text = null,
        /** Auto-detected URLs (`https://x`) when not wrapped in `[...]`. */
        public readonly ?Style $autolink = null,

        // ---- glamour-element parity ----
        /**
         * Outer document margin: number of blank lines emitted before
         * the document body. Mirrors charmbracelet/glamour.Document.Margin.
         */
        public readonly int $documentMargin = 0,
        /**
         * Number of cells the document is indented from the left edge.
         * Mirrors charmbracelet/glamour.Document.Indent.
         */
        public readonly int $documentIndent = 0,
        /**
         * Indentation cells per list nesting level. Mirrors charmbracelet/glamour's
         * per-list `LevelIndent`. Default 0 means "use bullet width
         * + 1" (the prior behaviour); set explicitly to a positive
         * value to override (glamour stock dark / light themes use 4).
         */
        public readonly int $listLevelIndent = 0,
        /** Glyph rendered for a checked task list item (`[x]`). */
        public readonly string $taskTickedGlyph = '☑',
        /** Glyph rendered for an unchecked task list item (`[ ]`). */
        public readonly string $taskUntickedGlyph = '☐',
        /** Glyph repeated to draw a thematic break / horizontal rule. */
        public readonly string $horizontalRuleGlyph = '─',
        /** Cells the thematic-break glyph repeats across. Default 40. */
        public readonly int $horizontalRuleLength = 40,

        // ---- table substyles ----
        /**
         * Style applied to GFM-table header cells. Default null = NO header
         * styling (M17): bold is not inherited, set this slot explicitly to
         * emphasise the header row.
         */
        public readonly ?Style $tableHeader    = null,
        /** Style applied to body-row cells. Default null = no styling. */
        public readonly ?Style $tableCell      = null,
        /**
         * RESERVED (M18): the border glyphs are taken from the
         * tableColumnSeparator/tableCenterSeparator/tableRowSeparator
         * strings; this style slot is not applied to them yet.
         */
        public readonly ?Style $tableSeparator = null,

        /**
         * Style applied to the visible alt-text of inline images,
         * distinct from `$image` which paints the wider `[image]` /
         * fallback marker. Mirrors charmbracelet/glamour.ImageText slot.
         */
        public readonly ?Style $imageText = null,

        /**
         * Glyph prefixed to every heading line — defaults to repeating
         * `'#'` per heading level, matching upstream commonmark output.
         * Set to a non-null string to override globally (e.g. `'❯ '`
         * for chevron-prefixed headings).
         */
        public readonly ?string $headingPrefix = null,
        /** Glyph appended to heading lines. Default null = nothing. */
        public readonly ?string $headingSuffix = null,
        /**
         * Optional prefix prepended to every paragraph
         * (StylePrimitive::Prefix). Strings decoded from JSON arrive
         * control-swept: {@see StyleGuide::fromJsonString()} runs every
         * string slot through `Sanitize::untrusted()`.
         */
        public readonly string $paragraphPrefix = '',
        /** Optional suffix appended to every paragraph. */
        public readonly string $paragraphSuffix = '',
        /**
         * Casing transform applied to heading bodies — `upper` / `lower`
         * / `title` / `none`. Default `none`. Mirrors charmbracelet/glamour's
         * StylePrimitive `Upper` / `Lower` / `Title` flags collapsed
         * into one selector.
         */
        public readonly string $headingCase = 'none',

        /**
         * Distinct marker style for ordered lists. Default null falls
         * through to the catch-all {@see $listMarker}. Mirrors
         * glamour's separate `Item.OrderedList` slot.
         */
        public readonly ?Style $orderedListMarker = null,

        /**
         * Distinct marker style for unordered lists. Default null
         * falls through to {@see $listMarker}. Mirrors
         * charmbracelet/glamour.Item.UnorderedList.
         */
        public readonly ?Style $unorderedListMarker = null,

        /**
         * `printf` format used for ordered-list markers. Receives the
         * 1-based item number. Default `'%d.'`. Mirrors charmbracelet/glamour's
         * upstream `Format` field (only the integer-format slice).
         */
        public readonly string $orderedListMarkerFormat = '%d.',

        /**
         * Marker glyph for unordered list items. Default `'•'`.
         * Mirrors charmbracelet/glamour.Item.Prefix for unordered lists.
         */
        public readonly string $unorderedListMarkerGlyph = '•',

        // ---- table-separator glyph overrides (glamour parity) ----
        /** Centre intersection (`┼`-style) glyph for table separators. */
        public readonly string $tableCenterSeparator = '┼',
        /** Vertical column-separator glyph. Default `│`. */
        public readonly string $tableColumnSeparator = '│',
        /** Horizontal row-separator glyph. Default `─`. */
        public readonly string $tableRowSeparator    = '─',

        // ---- definition list (glamour `DefinitionList`) ----
        /**
         * Block-level style applied to the entire definition list.
         * Default null = no wrapping style; the term / description
         * children render with their own styles.
         */
        public readonly ?Style $definitionList = null,

        // ---- StylePrimitive features (glamour parity) ----
        /**
         * Block-level prefix prepended once before the document body.
         * Mirrors charmbracelet/glamour.Document.BlockPrefix.
         */
        public readonly string $documentBlockPrefix = '',
        /**
         * Block-level suffix appended once after the document body.
         */
        public readonly string $documentBlockSuffix = '',
    ) {
        $noop = Style::new();
        $this->heading1   = $heading1   ?? $noop;
        $this->heading2   = $heading2   ?? $noop;
        $this->heading3   = $heading3   ?? $noop;
        $this->heading4   = $heading4   ?? $noop;
        $this->heading5   = $heading5   ?? $noop;
        $this->heading6   = $heading6   ?? $noop;
        $this->paragraph  = $paragraph  ?? $noop;
        $this->bold       = $bold       ?? $noop;
        $this->italic     = $italic     ?? $noop;
        $this->code       = $code       ?? $noop;
        $this->codeBlock  = $codeBlock  ?? $noop;
        $this->link       = $link       ?? $noop;
        $this->blockquote = $blockquote ?? $noop;
        $this->listMarker = $listMarker ?? $noop;
        $this->rule       = $rule       ?? $noop;
    }

    /** Default ANSI theme: bright accents on each heading, coloured code, etc. */
    public static function ansi(): self
    {
        if (isset(self::$stockInstances['ansi'])) {
            return self::$stockInstances['ansi'];
        }

        $accent  = Color::hex('#ff5f87'); // pink
        $cyan    = Color::ansi(14);
        $yellow  = Color::ansi(11);
        $blue    = Color::ansi(12);
        $magenta = Color::ansi(13);
        $green   = Color::ansi(10);
        $grey    = Color::ansi(8);

        return self::$stockInstances['ansi'] = new self(
            heading1:   Style::new()->bold()->foreground($accent),
            heading2:   Style::new()->bold()->foreground($cyan),
            heading3:   Style::new()->bold()->foreground($yellow),
            heading4:   Style::new()->bold()->foreground($blue),
            heading5:   Style::new()->bold()->foreground($magenta),
            heading6:   Style::new()->bold()->foreground($green),
            paragraph:  Style::new(),
            bold:       Style::new()->bold(),
            italic:     Style::new()->italic(),
            code:       Style::new()->foreground($accent),
            codeBlock:  Style::new()->faint(),
            link:       Style::new()->underline()->foreground($blue),
            blockquote: Style::new()->italic()->foreground($grey),
            listMarker: Style::new()->foreground($accent),
            rule:       Style::new()->foreground($grey),
            keyword:    Style::new()->bold()->foreground($magenta),
            string:     Style::new()->foreground($green),
            number:     Style::new()->foreground($yellow),
            comment:    Style::new()->italic()->foreground($grey),
        );
    }

    /** Plain-text theme: every Style is a no-op. Useful for snapshot tests. */
    public static function plain(): self
    {
        if (isset(self::$stockInstances['plain'])) {
            return self::$stockInstances['plain'];
        }

        $s = Style::new();
        return self::$stockInstances['plain'] = new self(
            heading1: $s, heading2: $s, heading3: $s,
            heading4: $s, heading5: $s, heading6: $s,
            paragraph: $s, bold: $s, italic: $s,
            code: $s, codeBlock: $s, link: $s,
            blockquote: $s, listMarker: $s, rule: $s,
            keyword: $s, string: $s, number: $s, comment: $s,
            strike: $s, linkText: $s, image: $s,
            htmlBlock: $s, htmlSpan: $s,
            definitionTerm: $s, definitionDescription: $s,
            text: $s, autolink: $s,
        );
    }

    /**
     * Plain-text theme that auto-selects when stdout is not a TTY.
     * Mirrors charmbracelet/glamour.Notty style: indistinguishable from `plain()`
     * but a separate factory so docs can refer to it by name. Use as
     * the fallback in non-interactive scripts (CI logs, file dumps).
     */
    public static function notty(): self
    {
        return self::plain();
    }

    /**
     * Monochrome ASCII theme — every Style emits at most bold / italic
     * / underline / strikethrough (no colour; the strike element carries
     * {@see Style::strikethrough()} for `~~text~~`). Distinct from {@see plain()} (which is a
     * total no-op) and {@see notty()} (an alias of plain). Use this
     * when you want emphasis to survive a colour-less terminal but not
     * a totally bare one — e.g. printer-friendly logs, accessibility
     * output, or test fixtures that should still differentiate
     * headings from body text.
     *
     * Mirrors charmbracelet/glamour.ASCII preset.
     */
    public static function ascii(): self
    {
        if (isset(self::$stockInstances['ascii'])) {
            return self::$stockInstances['ascii'];
        }

        $bold   = Style::new()->bold();
        $italic = Style::new()->italic();
        $under  = Style::new()->underline();
        $plain  = Style::new();
        return self::$stockInstances['ascii'] = new self(
            heading1: $bold, heading2: $bold, heading3: $bold,
            heading4: $bold, heading5: $bold, heading6: $bold,
            paragraph:  $plain,
            bold:       $bold,
            italic:     $italic,
            code:       $plain,
            codeBlock:  $plain,
            link:       $under,
            blockquote: $italic,
            listMarker: $plain,
            rule:       $plain,
            keyword:    $bold,
            string:     $plain,
            number:     $plain,
            comment:    $italic,
            strike:     Style::new()->strikethrough(),
            linkText:   $under,
            image:      $italic,
            htmlBlock:  $plain,
            htmlSpan:   $plain,
            text:       $plain,
            autolink:   $under,
        );
    }

    /**
     * The canonical stock-theme roster — the single source every name
     * list in this library derives from ({@see byName()},
     * {@see requireName()}, `Renderer::withStandardStyle()` errors).
     * Alias spellings (`tokyonight`, dashed/underscored variants) are
     * accepted by normalisation in {@see byName()} but not listed here.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return ['ansi', 'plain', 'notty', 'ascii', 'dark', 'light', 'dracula', 'tokyo-night', 'pink'];
    }

    /**
     * Strict sibling of {@see byName()}: the same normalised lookup, but
     * an unknown name throws instead of returning null. The message
     * carries the full valid-name list, derived from {@see names()}.
     *
     * @throws \InvalidArgumentException when no stock theme answers the name
     */
    public static function requireName(string $name): self
    {
        $theme = self::byName($name);
        if ($theme === null) {
            throw new \InvalidArgumentException(Lang::t('renderer.unknown_style', [
                'name'   => $name,
                'styles' => implode(', ', self::names()),
            ]));
        }
        return $theme;
    }

    /**
     * Look up a built-in theme by glamour-compatible name. Returns
     * null for unknown names so callers can fall back to their own
     * default rather than receiving a surprising one. Names mirror the
     * upstream glamour preset slugs (case-insensitive, hyphens
     * normalised to none); the roster is {@see names()}. `auto` is
     * deliberately NOT a byName slug — it resolves via
     * {@see fromEnvironment()} only, so a pure lookup never probes the
     * terminal.
     *
     *   ansi / plain / notty / ascii / dark / light /
     *   dracula / tokyo-night | tokyonight / pink
     */
    public static function byName(string $name): ?self
    {
        $key = strtolower(str_replace(['-', '_', ' '], '', trim($name)));
        return match ($key) {
            'ansi'                  => self::ansi(),
            'plain'                 => self::plain(),
            'notty'                 => self::notty(),
            'ascii'                 => self::ascii(),
            'dark'                  => self::dark(),
            'light'                 => self::light(),
            'dracula'               => self::dracula(),
            'tokyonight'            => self::tokyoNight(),
            'pink'                  => self::pink(),
            default                 => null,
        };
    }

    /**
     * Glamour-compatible env-var auto-selection. `NO_COLOR`, when set to
     * any non-empty value, wins first (M16): the no-color.org convention
     * asks for plain output regardless of anything else configured, so
     * {@see plain()} is returned even against a `GLAMOUR_STYLE` preset.
     * Otherwise `GLAMOUR_STYLE` names a preset, and the literal string
     * `auto` (matching glamour's convention) returns {@see notty()} when
     * STDOUT is not a TTY and {@see dark()} otherwise. Falls back to
     * `$default` (default `ansi()`) when no variable applies or the name
     * is unrecognised. Note that `new Renderer()` keeps its own `ansi()`
     * hard default — honouring the environment is exactly what this
     * channel is for.
     */
    public static function fromEnvironment(?self $default = null): self
    {
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return self::plain();
        }
        $env = getenv('GLAMOUR_STYLE');
        if ($env !== false && $env !== '') {
            if (strtolower($env) === 'auto') {
                $isTty = @stream_isatty(STDOUT); // n7: stream_isatty is always present on PHP >= 8.1.
                return $isTty ? self::dark() : self::notty();
            }
            $named = self::byName($env);
            if ($named !== null) {
                return $named;
            }
        }
        return $default ?? self::ansi();
    }

    /**
     * Dark-background optimised theme. Bright accents for headings,
     * faint code backdrop, blue underlined links — matches glamour's
     * `Dark` preset visually.
     */
    public static function dark(): self
    {
        if (isset(self::$stockInstances['dark'])) {
            return self::$stockInstances['dark'];
        }

        $h = Color::hex('#ff5fd2');     // bright magenta
        $cyan   = Color::ansi(14);
        $yellow = Color::ansi(11);
        $blue   = Color::ansi(12);
        $green  = Color::ansi(10);
        $grey   = Color::hex('#8a8a8a');

        return self::$stockInstances['dark'] = new self(
            heading1:   Style::new()->bold()->foreground($h),
            heading2:   Style::new()->bold()->foreground($cyan),
            heading3:   Style::new()->bold()->foreground($yellow),
            heading4:   Style::new()->bold()->foreground($blue),
            heading5:   Style::new()->bold()->foreground($green),
            heading6:   Style::new()->bold()->foreground($grey),
            paragraph:  Style::new(),
            bold:       Style::new()->bold(),
            italic:     Style::new()->italic(),
            code:       Style::new()->foreground(Color::hex('#ffaf87'))->background(Color::hex('#262626')),
            codeBlock:  Style::new()->foreground(Color::hex('#dadada'))->background(Color::hex('#262626')),
            link:       Style::new()->underline()->foreground($blue),
            blockquote: Style::new()->italic()->foreground($grey),
            listMarker: Style::new()->foreground($h),
            rule:       Style::new()->foreground($grey),
            keyword:    Style::new()->bold()->foreground(Color::hex('#ff5fd2')),
            string:     Style::new()->foreground($green),
            number:     Style::new()->foreground($yellow),
            comment:    Style::new()->italic()->foreground($grey),
            strike:     Style::new()->strikethrough()->foreground($grey),
            linkText:   Style::new()->underline()->foreground($cyan),
            image:      Style::new()->italic()->foreground($cyan),
            htmlBlock:  Style::new()->foreground($grey),
            htmlSpan:   Style::new()->foreground($grey),
            autolink:   Style::new()->underline()->foreground($blue),
        );
    }

    /**
     * Light-background optimised theme. Darker accents and a no-bg
     * code style.
     */
    public static function light(): self
    {
        if (isset(self::$stockInstances['light'])) {
            return self::$stockInstances['light'];
        }

        $h = Color::hex('#9c4dff');      // deep purple
        $cyan   = Color::hex('#005f87');
        $yellow = Color::hex('#875f00');
        $blue   = Color::hex('#005faf');
        $green  = Color::hex('#005f00');
        $grey   = Color::hex('#5f5f5f');

        return self::$stockInstances['light'] = new self(
            heading1:   Style::new()->bold()->foreground($h),
            heading2:   Style::new()->bold()->foreground($cyan),
            heading3:   Style::new()->bold()->foreground($yellow),
            heading4:   Style::new()->bold()->foreground($blue),
            heading5:   Style::new()->bold()->foreground($green),
            heading6:   Style::new()->bold()->foreground($grey),
            paragraph:  Style::new(),
            bold:       Style::new()->bold(),
            italic:     Style::new()->italic(),
            code:       Style::new()->foreground(Color::hex('#af0000')),
            codeBlock:  Style::new()->foreground(Color::hex('#262626')),
            link:       Style::new()->underline()->foreground($blue),
            blockquote: Style::new()->italic()->foreground($grey),
            listMarker: Style::new()->foreground($h),
            rule:       Style::new()->foreground($grey),
            keyword:    Style::new()->bold()->foreground($h),
            string:     Style::new()->foreground($green),
            number:     Style::new()->foreground($yellow),
            comment:    Style::new()->italic()->foreground($grey),
            strike:     Style::new()->strikethrough()->foreground($grey),
            linkText:   Style::new()->underline()->foreground($cyan),
            image:      Style::new()->italic()->foreground($cyan),
            htmlBlock:  Style::new()->foreground($grey),
            htmlSpan:   Style::new()->foreground($grey),
            autolink:   Style::new()->underline()->foreground($blue),
        );
    }

    /**
     * Dracula colour scheme — popular dark-purple palette.
     */
    public static function dracula(): self
    {
        if (isset(self::$stockInstances['dracula'])) {
            return self::$stockInstances['dracula'];
        }

        // Named hex sourced from sugar-core Palettes SSOT (byte-identical to
        // the former inline literals; see ThemeTest drift guard).
        $bg   = Color::hex(Palettes::DRACULA['background']);
        $fg   = Color::hex(Palettes::DRACULA['foreground']);
        $pink = Color::hex(Palettes::DRACULA['pink']);
        $purp = Color::hex(Palettes::DRACULA['purple']);
        $cyan = Color::hex(Palettes::DRACULA['cyan']);
        $green= Color::hex(Palettes::DRACULA['green']);
        $org  = Color::hex(Palettes::DRACULA['orange']);
        $yel  = Color::hex(Palettes::DRACULA['yellow']);
        $com  = Color::hex(Palettes::DRACULA['comment']);

        return self::$stockInstances['dracula'] = new self(
            heading1:   Style::new()->bold()->foreground($pink),
            heading2:   Style::new()->bold()->foreground($purp),
            heading3:   Style::new()->bold()->foreground($cyan),
            heading4:   Style::new()->bold()->foreground($green),
            heading5:   Style::new()->bold()->foreground($org),
            heading6:   Style::new()->bold()->foreground($yel),
            paragraph:  Style::new()->foreground($fg),
            bold:       Style::new()->bold(),
            italic:     Style::new()->italic(),
            code:       Style::new()->foreground($pink)->background($bg),
            codeBlock:  Style::new()->foreground($fg)->background($bg),
            link:       Style::new()->underline()->foreground($cyan),
            blockquote: Style::new()->italic()->foreground($com),
            listMarker: Style::new()->foreground($pink),
            rule:       Style::new()->foreground($com),
            keyword:    Style::new()->bold()->foreground($pink),
            string:     Style::new()->foreground($yel),
            number:     Style::new()->foreground($purp),
            comment:    Style::new()->italic()->foreground($com),
            strike:     Style::new()->strikethrough()->foreground($com),
            linkText:   Style::new()->underline()->foreground($cyan),
            image:      Style::new()->italic()->foreground($cyan),
            htmlBlock:  Style::new()->foreground($com),
            htmlSpan:   Style::new()->foreground($com),
            autolink:   Style::new()->underline()->foreground($cyan),
        );
    }

    /**
     * Tokyo Night theme — dark blue/purple palette.
     */
    public static function tokyoNight(): self
    {
        if (isset(self::$stockInstances['tokyoNight'])) {
            return self::$stockInstances['tokyoNight'];
        }

        $bg     = Color::hex('#1a1b26');
        $fg     = Color::hex('#a9b1d6');
        $blue   = Color::hex('#7aa2f7');
        $cyan   = Color::hex('#7dcfff');
        $green  = Color::hex('#9ece6a');
        $org    = Color::hex('#ff9e64');
        $purple = Color::hex('#bb9af7');
        $red    = Color::hex('#f7768e');
        $com    = Color::hex('#565f89');

        return self::$stockInstances['tokyoNight'] = new self(
            heading1:   Style::new()->bold()->foreground($red),
            heading2:   Style::new()->bold()->foreground($org),
            heading3:   Style::new()->bold()->foreground($green),
            heading4:   Style::new()->bold()->foreground($blue),
            heading5:   Style::new()->bold()->foreground($purple),
            heading6:   Style::new()->bold()->foreground($cyan),
            paragraph:  Style::new()->foreground($fg),
            bold:       Style::new()->bold(),
            italic:     Style::new()->italic(),
            code:       Style::new()->foreground($cyan)->background($bg),
            codeBlock:  Style::new()->foreground($fg)->background($bg),
            link:       Style::new()->underline()->foreground($blue),
            blockquote: Style::new()->italic()->foreground($com),
            listMarker: Style::new()->foreground($org),
            rule:       Style::new()->foreground($com),
            keyword:    Style::new()->bold()->foreground($purple),
            string:     Style::new()->foreground($green),
            number:     Style::new()->foreground($org),
            comment:    Style::new()->italic()->foreground($com),
            strike:     Style::new()->strikethrough()->foreground($com),
            linkText:   Style::new()->underline()->foreground($blue),
            image:      Style::new()->italic()->foreground($cyan),
            htmlBlock:  Style::new()->foreground($com),
            htmlSpan:   Style::new()->foreground($com),
            autolink:   Style::new()->underline()->foreground($blue),
        );
    }

    /**
     * Pink — playful sweet palette.
     */
    public static function pink(): self
    {
        if (isset(self::$stockInstances['pink'])) {
            return self::$stockInstances['pink'];
        }

        $hot = Color::hex('#ff5fd2');
        $rose = Color::hex('#ff87d7');
        $lav  = Color::hex('#d7afff');
        $cream= Color::hex('#ffd7af');
        $green= Color::hex('#afff87');

        return self::$stockInstances['pink'] = new self(
            heading1:   Style::new()->bold()->foreground($hot),
            heading2:   Style::new()->bold()->foreground($rose),
            heading3:   Style::new()->bold()->foreground($lav),
            heading4:   Style::new()->bold()->foreground($cream),
            heading5:   Style::new()->bold()->foreground($green),
            heading6:   Style::new()->bold()->foreground($hot),
            paragraph:  Style::new(),
            bold:       Style::new()->bold(),
            italic:     Style::new()->italic(),
            code:       Style::new()->foreground($hot),
            codeBlock:  Style::new()->faint(),
            link:       Style::new()->underline()->foreground($lav),
            blockquote: Style::new()->italic()->foreground($rose),
            listMarker: Style::new()->foreground($hot),
            rule:       Style::new()->foreground($rose),
            keyword:    Style::new()->bold()->foreground($hot),
            string:     Style::new()->foreground($green),
            number:     Style::new()->foreground($cream),
            comment:    Style::new()->italic()->foreground($rose),
            strike:     Style::new()->strikethrough()->foreground($rose),
            linkText:   Style::new()->underline()->foreground($lav),
            image:      Style::new()->italic()->foreground($lav),
            htmlBlock:  Style::new()->foreground($rose),
            htmlSpan:   Style::new()->foreground($rose),
            autolink:   Style::new()->underline()->foreground($lav),
        );
    }

    /**
     * Derive a copy with every Style slot passed through `$map`.
     *
     * WHY: an embedding terminal's contrast law must be able to rewrite
     * foreground colours across a whole palette without hard-coding this
     * class's slot list at the consumer (reviews-1103 A11Y-1: candy-code
     * projects its chrome tokens against the real terminal background and
     * needed the markdown half to ride the same resolver). Scalar and
     * layout fields ride through untouched; an unset optional Style stays
     * unset. Returning the SAME instance from the mapper is explicitly
     * supported, so a projection that changes nothing rebuilds nothing —
     * identity survives slot by slot.
     *
     * @param callable(Style): Style $map
     */
    /** @return array<string, \ReflectionProperty> properties keyed by name */
    private static function propertiesByName(): array
    {
        $properties = [];
        foreach ((new \ReflectionClass(self::class))->getProperties() as $property) {
            $properties[$property->getName()] = $property;
        }

        return $properties;
    }

    public function mapStyles(callable $map): self
    {
        $args = [];
        $properties = self::propertiesByName();
        foreach ((new \ReflectionMethod(self::class, '__construct'))->getParameters() as $parameter) {
            $value = $properties[$parameter->getName()]->getValue($this);
            if ($value instanceof Style) {
                $mapped = $map($value);
                if (!$mapped instanceof Style) {
                    throw new \InvalidArgumentException(Lang::t('theme.mapper_not_style', ['type' => get_debug_type($mapped)]));
                }
                $value = $mapped;
            }
            $args[$parameter->getName()] = $value;
        }

        return new self(...$args);
    }

    /**
     * One-slot modification idiom: return a copy of this theme with a
     * single Style slot swapped, leaving every other slot (styles and
     * scalars alike) untouched. This is the cheap path for "dark, but
     * gold code" — the constructor's 15 required styles never need
     * re-listing. `$slot` must name a Style-typed constructor slot;
     * anything else throws with the valid roster.
     *
     * @throws \InvalidArgumentException on an unknown or non-Style slot name
     */
    public function with(string $slot, Style $style): self
    {
        $args = [];
        $styleSlots = [];
        $properties = self::propertiesByName();
        foreach ((new \ReflectionMethod(self::class, '__construct'))->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $styleSlots[] = $name;
            }
            $args[$name] = $properties[$name]->getValue($this);
        }

        if (!\in_array($slot, $styleSlots, true)) {
            throw new \InvalidArgumentException(Lang::t('theme.with_slot_not_style', [
                'slot'  => $slot,
                'slots' => implode(', ', $styleSlots),
            ]));
        }

        $args[$slot] = $style;

        return new self(...$args);
    }

    /**
     * Load a theme from a JSON file, optionally bounded by $maxBytes.
     * The JSON shape is one object per element, each carrying a subset of
     * `foreground` / `background` (hex `#rrggbb` or `ansi:N` or
     * `ansi256:N`) and the standard boolean attribute flags (`bold`,
     * `italic`, `underline`, `strike`, `faint`, `blink`, `reverse`).
     * Missing elements default to the plain Style.
     *
     * $maxBytes is the hostile-workspace guard: a stat probe refuses an
     * over-cap file before its bytes are materialised, and the read byte
     * count is checked again after (a swap or race between the two must
     * not smuggle a bigger payload). The refusal names the cap.
     *
     * The read failure contract mirrors {@see StyleGuide::fromFile()}: a
     * non-file path, or a read that warned, raises `RuntimeException`
     * before any decode; contents that are not a JSON object raise
     * `InvalidArgumentException`.
     *
     * Trust note: a theme file is CODE the terminal executes — colour
     * and flag declarations, but declarations nonetheless. Do not
     * auto-load workspace-adjacent style files; load only files the
     * operator pinned. (StyleGuide's decoder additionally sweeps every
     * string slot through `Sanitize::untrusted()`; this loader carries
     * no string slots to sweep.)
     *
     * @throws \RuntimeException when the file cannot be read or exceeds $maxBytes
     * @throws \InvalidArgumentException when its contents are not a JSON object
     */
    public static function fromFile(string $path, ?int $maxBytes = null): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException(Lang::t('theme.read_failed', ['path' => $path]));
        }
        if ($maxBytes !== null) {
            $size = filesize($path);
            if ($size !== false && $size > $maxBytes) {
                throw new \RuntimeException(Lang::t('theme.file_too_large', ['path' => $path, 'bytes' => $maxBytes]));
            }
        }
        // The read failure is reported by the exception below, so the
        // warning file_get_contents() raises (an unreadable file, a
        // directory swapped in after is_file()) is captured for its message
        // rather than leaked to the caller's error handler or the console.
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
        if ($raw === false || $warning !== null) {
            $msg = $warning ?? 'unknown error';
            throw new \RuntimeException(Lang::t('theme.read_failed', ['path' => $path]) . ": {$msg}");
        }
        if ($maxBytes !== null && strlen($raw) > $maxBytes) {
            throw new \RuntimeException(Lang::t('theme.file_too_large', ['path' => $path, 'bytes' => $maxBytes]));
        }
        return self::fromJsonString($raw);
    }

    /**
     * @deprecated Prefer {@see fromFile()} — it names the file contract and
     *     carries the optional byte ceiling. This alias forwards unchanged.
     */
    public static function fromJson(string $path): self
    {
        return self::fromFile($path);
    }

    /**
     * Decode a theme from JSON. Two schemas are accepted (M6):
     *
     * - a payload carrying a `document` block is glamour's styles.json and
     *   routes through {@see StyleGuide}, so element and chroma vocabulary
     *   decodes as documented there;
     * - anything else is this library's FLAT schema: an object whose keys
     *   name style slots (heading1..heading6, paragraph, bold, italic,
     *   code, codeBlock, link, blockquote, listMarker, rule, keyword,
     *   string, number, comment, strike, linkText, image, htmlBlock,
     *   htmlSpan, definitionTerm, definitionDescription, text, autolink),
     *   each an object of `foreground`/`background` colour strings plus
     *   boolean flags.
     *
     * WHAT THE FLAT READER LEAVES AT DEFAULTS (m17): documentMargin and
     * documentIndent, paragraph and document affixes, heading prefixes
     * and suffixes, bullet and table separator glyphs, definition-list
     * and task glyphs, and the rule glyph and length. Unknown keys are
     * ignored; absent slots become plain styles.
     *
     * @see fromJson()
     */
    public static function fromJsonString(string $json): self
    {
        $data = json_decode($json, associative: true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(Lang::t('theme.json_invalid'));
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException(Lang::t('theme.json_object'));
        }
        // A JSON list is not a schema either way: the flat reader would
        // ignore the integer keys and the glamour router would find no
        // `document`, both quietly yielding an all-plain Theme. The door
        // refuses it as the non-object payload the README promises.
        if (array_is_list($data)) {
            throw new \InvalidArgumentException(Lang::t('theme.json_object'));
        }
        if (StyleGuide::isGlamourSchema($data)) {
            return StyleGuide::fromDecoded($data)->theme();
        }
        $pick = static fn(string $k): Style
            => isset($data[$k]) && is_array($data[$k])
                ? self::parseStyle($data[$k])
                : Style::new();
        return new self(
            heading1:              $pick('heading1'),
            heading2:              $pick('heading2'),
            heading3:              $pick('heading3'),
            heading4:              $pick('heading4'),
            heading5:              $pick('heading5'),
            heading6:              $pick('heading6'),
            paragraph:             $pick('paragraph'),
            bold:                  $pick('bold'),
            italic:                $pick('italic'),
            code:                  $pick('code'),
            codeBlock:             $pick('codeBlock'),
            link:                  $pick('link'),
            blockquote:            $pick('blockquote'),
            listMarker:            $pick('listMarker'),
            rule:                  $pick('rule'),
            keyword:               $pick('keyword'),
            string:                $pick('string'),
            number:                $pick('number'),
            comment:               $pick('comment'),
            strike:                $pick('strike'),
            linkText:              $pick('linkText'),
            image:                 $pick('image'),
            htmlBlock:             $pick('htmlBlock'),
            htmlSpan:              $pick('htmlSpan'),
            definitionTerm:        $pick('definitionTerm'),
            definitionDescription: $pick('definitionDescription'),
            text:                  $pick('text'),
            autolink:              $pick('autolink'),
        );
    }

    /**
     * Flag truth for a JSON config value (m12). A real boolean answers
     * directly; a string counts as OFF only for the negative spellings
     * `false`, `0`, `no`, `off` (case-insensitive) or the empty string;
     * an integer is zero-or-not. Plain `!empty()` would flip
     * `"bold": "false"` ON, because any non-empty string is truthy.
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

    /** @param array<string,mixed> $cfg */
    private static function parseStyle(array $cfg): Style
    {
        $s = Style::new();
        if (isset($cfg['foreground']) && is_string($cfg['foreground'])) {
            $c = self::parseColor($cfg['foreground']);
            if ($c !== null) $s = $s->foreground($c);
        }
        if (isset($cfg['background']) && is_string($cfg['background'])) {
            $c = self::parseColor($cfg['background']);
            if ($c !== null) $s = $s->background($c);
        }
        foreach (['bold', 'italic', 'underline', 'strike', 'faint', 'blink', 'reverse'] as $flag) {
            if (self::flagOn($cfg[$flag] ?? null)) {
                $s = match ($flag) {
                    'bold'      => $s->bold(),
                    'italic'    => $s->italic(),
                    'underline' => $s->underline(),
                    'strike'    => $s->strikethrough(),
                    'faint'     => $s->faint(),
                    'blink'     => $s->blink(),
                    'reverse'   => $s->reverse(),
                };
            }
        }
        return $s;
    }

    private static function parseColor(string $spec): ?Color
    {
        if ($spec === '') {
            return null;
        }
        if ($spec[0] === '#') {
            return Color::hex($spec);
        }
        if (str_starts_with($spec, 'ansi256:')) {
            $num = substr($spec, 8);
            if (!preg_match('/^\d+$/', $num)) {
                throw new \InvalidArgumentException(Lang::t('theme.bad_color', ['spec' => $spec]));
            }
            $val = (int) $num;
            if ($val < 0 || $val > 255) {
                throw new \InvalidArgumentException(Lang::t('theme.bad_color', ['spec' => $spec]));
            }
            return Color::ansi256($val);
        }
        if (str_starts_with($spec, 'ansi:')) {
            $num = substr($spec, 5);
            if (!preg_match('/^\d+$/', $num)) {
                throw new \InvalidArgumentException(Lang::t('theme.bad_color', ['spec' => $spec]));
            }
            $val = (int) $num;
            if ($val < 0 || $val > 15) {
                throw new \InvalidArgumentException(Lang::t('theme.bad_color', ['spec' => $spec]));
            }
            return Color::ansi($val);
        }
        return null;
    }
}
