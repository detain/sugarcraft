<?php

declare(strict_types=1);

namespace CandyCore\Shine;

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Style;

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
            throw new \RuntimeException("could not read theme file: $path");
        }
        return self::fromJsonString($raw);
    }

    /** @see fromJson() */
    public static function fromJsonString(string $json): self
    {
        $data = json_decode($json, associative: true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('theme JSON must decode to an object');
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
            return Color::ansi256((int) substr($spec, 8));
        }
        if (str_starts_with($spec, 'ansi:')) {
            return Color::ansi((int) substr($spec, 5));
        }
        return null;
    }
}
