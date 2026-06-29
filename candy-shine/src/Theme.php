<?php

declare(strict_types=1);

namespace SugarCraft\Shine;

use SugarCraft\Shine\Lang;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

/**
 * A {@see Renderer} stylesheet — one {@see Style} per Markdown element
 * type. Themes are immutable; pick a built-in via {@see ansi()} /
 * {@see plain()} or build a custom one with the constructor.
 */
final class Theme
{
    public function __construct(
        public readonly Style $heading1,
        public readonly Style $heading2,
        public readonly Style $heading3,
        public readonly Style $heading4,
        public readonly Style $heading5,
        public readonly Style $heading6,
        public readonly Style $paragraph,
        public readonly Style $bold,
        public readonly Style $italic,
        public readonly Style $code,
        public readonly Style $codeBlock,
        public readonly Style $link,
        public readonly Style $blockquote,
        public readonly Style $listMarker,
        public readonly Style $rule,
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

        // ---- glamour-element parity (audit #9) ----
        /**
         * Outer document margin: number of blank lines emitted before
         * the document body. Mirrors glamour's `Document.Margin`.
         */
        public readonly int $documentMargin = 0,
        /**
         * Number of cells the document is indented from the left edge.
         * Mirrors glamour's `Document.Indent`.
         */
        public readonly int $documentIndent = 0,
        /**
         * Indentation cells per list nesting level. Mirrors glamour's
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

        // ---- table substyles (audit #9 wrap-up) ----
        /** Style applied to GFM-table header cells. Default null = inherit `bold`. */
        public readonly ?Style $tableHeader    = null,
        /** Style applied to body-row cells. Default null = no styling. */
        public readonly ?Style $tableCell      = null,
        /** Style applied to the separator characters that join cells. */
        public readonly ?Style $tableSeparator = null,

        /**
         * Style applied to the visible alt-text of inline images,
         * distinct from `$image` which paints the wider `[image]` /
         * fallback marker. Mirrors glamour's `ImageText` slot.
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
        /** Optional prefix prepended to every paragraph (StylePrimitive::Prefix). */
        public readonly string $paragraphPrefix = '',
        /** Optional suffix appended to every paragraph. */
        public readonly string $paragraphSuffix = '',
        /**
         * Casing transform applied to heading bodies — `upper` / `lower`
         * / `title` / `none`. Default `none`. Mirrors glamour's
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
         * falls through to {@see $listMarker}. Mirrors glamour's
         * `Item.UnorderedList` slot.
         */
        public readonly ?Style $unorderedListMarker = null,

        /**
         * `printf` format used for ordered-list markers. Receives the
         * 1-based item number. Default `'%d.'`. Mirrors glamour's
         * upstream `Format` field (only the integer-format slice).
         */
        public readonly string $orderedListMarkerFormat = '%d.',

        /**
         * Marker glyph for unordered list items. Default `'•'`.
         * Mirrors glamour's `Item.Prefix` for unordered lists.
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
         * Mirrors glamour's `Document.BlockPrefix`.
         */
        public readonly string $documentBlockPrefix = '',
        /**
         * Block-level suffix appended once after the document body.
         */
        public readonly string $documentBlockSuffix = '',
        /**
         * Per-element conceal flag: when true, suppresses the element
         * type from emit. Used by Notty / ASCII themes to skip emoji.
         * Currently a no-op placeholder that callers can branch on.
         */
        public readonly bool $conceal = false,
    ) {}

    /** Default ANSI theme: bright accents on each heading, coloured code, etc. */
    public static function ansi(): self
    {
        $accent  = Color::hex('#ff5f87'); // pink
        $cyan    = Color::ansi(14);
        $yellow  = Color::ansi(11);
        $blue    = Color::ansi(12);
        $magenta = Color::ansi(13);
        $green   = Color::ansi(10);
        $grey    = Color::ansi(8);

        return new self(
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
        $s = Style::new();
        return new self(
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
     * Mirrors glamour's `Notty` style: indistinguishable from `plain()`
     * but a separate factory so docs can refer to it by name. Use as
     * the fallback in non-interactive scripts (CI logs, file dumps).
     */
    public static function notty(): self
    {
        return self::plain();
    }

    /**
     * Monochrome ASCII theme — every Style emits at most bold / italic
     * / underline (no colour). Distinct from {@see plain()} (which is a
     * total no-op) and {@see notty()} (an alias of plain). Use this
     * when you want emphasis to survive a colour-less terminal but not
     * a totally bare one — e.g. printer-friendly logs, accessibility
     * output, or test fixtures that should still differentiate
     * headings from body text.
     *
     * Mirrors glamour's `ASCII` preset.
     */
    public static function ascii(): self
    {
        $bold   = Style::new()->bold();
        $italic = Style::new()->italic();
        $under  = Style::new()->underline();
        $plain  = Style::new();
        return new self(
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
     * Look up a built-in theme by glamour-compatible name. Returns
     * null for unknown names so callers can fall back to their own
     * default rather than receiving a surprising one. Names mirror the
     * upstream glamour preset slugs (case-insensitive, hyphens
     * normalised to none).
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
            'notty', 'no-tty'       => self::notty(),
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
     * Glamour-compatible env-var auto-selection. Reads the
     * `GLAMOUR_STYLE` environment variable; if set to a known preset
     * name, returns it. The literal string `auto` (matching glamour's
     * convention) returns {@see notty()} when STDOUT is not a TTY and
     * {@see dark()} otherwise. Falls back to `$default` (default
     * `ansi()`) when the env var is missing or unrecognised.
     */
    public static function fromEnvironment(?self $default = null): self
    {
        $env = getenv('GLAMOUR_STYLE');
        if ($env !== false && $env !== '') {
            if (strtolower($env) === 'auto') {
                $isTty = function_exists('stream_isatty')
                    ? @stream_isatty(STDOUT)
                    : posix_isatty(STDOUT);
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
     * Dark-background optimized theme. Bright accents for headings,
     * faint code backdrop, blue underlined links — matches glamour's
     * `Dark` preset visually.
     */
    public static function dark(): self
    {
        $h = Color::hex('#ff5fd2');     // bright magenta
        $cyan   = Color::ansi(14);
        $yellow = Color::ansi(11);
        $blue   = Color::ansi(12);
        $green  = Color::ansi(10);
        $grey   = Color::hex('#8a8a8a');

        return new self(
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
     * Light-background optimized theme. Darker accents and a no-bg
     * code style.
     */
    public static function light(): self
    {
        $h = Color::hex('#9c4dff');      // deep purple
        $cyan   = Color::hex('#005f87');
        $yellow = Color::hex('#875f00');
        $blue   = Color::hex('#005faf');
        $green  = Color::hex('#005f00');
        $grey   = Color::hex('#5f5f5f');

        return new self(
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
        $bg   = Color::hex('#282a36');
        $fg   = Color::hex('#f8f8f2');
        $pink = Color::hex('#ff79c6');
        $purp = Color::hex('#bd93f9');
        $cyan = Color::hex('#8be9fd');
        $green= Color::hex('#50fa7b');
        $org  = Color::hex('#ffb86c');
        $yel  = Color::hex('#f1fa8c');
        $com  = Color::hex('#6272a4');

        return new self(
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
        $bg     = Color::hex('#1a1b26');
        $fg     = Color::hex('#a9b1d6');
        $blue   = Color::hex('#7aa2f7');
        $cyan   = Color::hex('#7dcfff');
        $green  = Color::hex('#9ece6a');
        $org    = Color::hex('#ff9e64');
        $purple = Color::hex('#bb9af7');
        $red    = Color::hex('#f7768e');
        $com    = Color::hex('#565f89');

        return new self(
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
        $hot = Color::hex('#ff5fd2');
        $rose = Color::hex('#ff87d7');
        $lav  = Color::hex('#d7afff');
        $cream= Color::hex('#ffd7af');
        $green= Color::hex('#afff87');

        return new self(
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
     * Load a theme from a JSON file. The JSON shape is one object per
     * element, each carrying a subset of `foreground` / `background`
     * (hex `#rrggbb` or `ansi:N` or `ansi256:N`) and the standard
     * boolean attribute flags (`bold`, `italic`, `underline`, `strike`,
     * `faint`, `blink`, `reverse`). Missing elements default to the
     * plain Style.
     */
    public static function fromJson(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(Lang::t('theme.read_failed', ['path' => $path]));
        }
        return self::fromJsonString($raw);
    }

    /** @see fromJson() */
    public static function fromJsonString(string $json): self
    {
        $data = json_decode($json, associative: true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException(Lang::t('theme.json_object'));
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
            if (!empty($cfg[$flag])) {
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
