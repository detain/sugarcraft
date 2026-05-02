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
            heading1:   $pick('heading1'),
            heading2:   $pick('heading2'),
            heading3:   $pick('heading3'),
            heading4:   $pick('heading4'),
            heading5:   $pick('heading5'),
            heading6:   $pick('heading6'),
            paragraph:  $pick('paragraph'),
            bold:       $pick('bold'),
            italic:     $pick('italic'),
            code:       $pick('code'),
            codeBlock:  $pick('codeBlock'),
            link:       $pick('link'),
            blockquote: $pick('blockquote'),
            listMarker: $pick('listMarker'),
            rule:       $pick('rule'),
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
