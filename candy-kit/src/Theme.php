<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

/**
 * Per-status palette used by {@see StatusLine} and {@see Banner}.
 * Themes are immutable; {@see ansi()} ships the default colourful
 * palette, {@see plain()} produces a no-op palette ideal for
 * snapshot tests, and a handful of named presets (`charm`,
 * `dracula`, `nord`, `catppuccin`) cover the most-requested
 * branded palettes.
 */
final class Theme
{
    /**
     * @param Style|null $success
     * @param Style|null $error
     * @param Style|null $warn
     * @param Style|null $info
     * @param Style|null $prompt
     * @param Style|null $accent
     * @param Style|null $muted
     */
    public function __construct(
        public readonly ?Style $success,
        public readonly ?Style $error,
        public readonly ?Style $warn,
        public readonly ?Style $info,
        public readonly ?Style $prompt,
        public readonly ?Style $accent,
        public readonly ?Style $muted,
    ) {
        // Fail-fast: illegal states (null Style fields) must halt immediately
        // with a descriptive exception rather than a generic TypeError.
        foreach (['success' => $success, 'error' => $error, 'warn' => $warn,
                  'info' => $info, 'prompt' => $prompt, 'accent' => $accent, 'muted' => $muted] as $name => $style) {
            if ($style === null) {
                throw new \InvalidArgumentException("Theme::{$name} cannot be null");
            }
        }
    }

    /** @return Style */
    public function success(): Style { return $this->success; }

    /** @return Style */
    public function error(): Style { return $this->error; }

    /** @return Style */
    public function warn(): Style { return $this->warn; }

    /** @return Style */
    public function info(): Style { return $this->info; }

    /** @return Style */
    public function prompt(): Style { return $this->prompt; }

    /** @return Style */
    public function accent(): Style { return $this->accent; }

    /** @return Style */
    public function muted(): Style { return $this->muted; }

    public static function ansi(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::ansi(10)),  // bright green
            error:   Style::new()->bold()->foreground(Color::ansi(9)),   // bright red
            warn:    Style::new()->bold()->foreground(Color::ansi(11)),  // bright yellow
            info:    Style::new()->bold()->foreground(Color::ansi(12)),  // bright blue
            prompt:  Style::new()->bold()->foreground(Color::hex('#ff5f87')),
            accent:  Style::new()->bold()->foreground(Color::ansi(13)),  // bright magenta
            muted:   Style::new()->faint(),
        );
    }

    public static function plain(): self
    {
        $s = Style::new();
        return new self($s, $s, $s, $s, $s, $s, $s);
    }

    /**
     * Terminal-adaptive factory — picks a dark or light theme by checking
     * the terminal background colour.
     *
     * The TEA program sends {@see \SugarCraft\Core\Cmd::requestBackgroundColor()}
     * during init, receives a {@see BackgroundColorMsg} in its update loop, and
     * passes it here to select the appropriate palette. If no msg is supplied,
     * the stored last-detected value is used (set by the update loop).
     * As a fallback when no detection has occurred, {@see ansi()} is returned.
     *
     * Dark terminals get the {@see nord()} palette; light terminals get
     * {@see catppuccin()}.
     *
     * @param BackgroundColorMsg|null $bg  parsed OSC-11 reply, or null to use
     *                                     the stored last-detected value
     */
    public static function auto(?BackgroundColorMsg $bg = null): self
    {
        if ($bg !== null) {
            return $bg->isDark() ? self::nord() : self::catppuccin();
        }
        // No msg supplied: caller should have stored the detection result.
        // Fall back to ansi() until the program has received a reply.
        return self::ansi();
    }

    /**
     * Begin building a custom theme with a fluent interface.
     *
     * @example Theme::build()->success(...)->error(...)->muted(...)->build()
     */
    public static function build(): ThemeBuilder
    {
        return new ThemeBuilder();
    }

    /** Charm-brand pink + cyan accent set. */
    public static function charm(): self
    {
        $pink = Color::hex('#ff5fd2');
        $cyan = Color::hex('#5fafff');
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#5fff87')),
            error:   Style::new()->bold()->foreground(Color::hex('#ff5f5f')),
            warn:    Style::new()->bold()->foreground(Color::hex('#ffd75f')),
            info:    Style::new()->bold()->foreground($cyan),
            prompt:  Style::new()->bold()->foreground($pink),
            accent:  Style::new()->bold()->foreground($pink),
            muted:   Style::new()->foreground(Color::hex('#888888')),
        );
    }

    /** Dracula palette. */
    public static function dracula(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#50fa7b')),
            error:   Style::new()->bold()->foreground(Color::hex('#ff5555')),
            warn:    Style::new()->bold()->foreground(Color::hex('#f1fa8c')),
            info:    Style::new()->bold()->foreground(Color::hex('#8be9fd')),
            prompt:  Style::new()->bold()->foreground(Color::hex('#ff79c6')),
            accent:  Style::new()->bold()->foreground(Color::hex('#bd93f9')),
            muted:   Style::new()->foreground(Color::hex('#6272a4')),
        );
    }

    /** Nord palette — cool blues and frost tones. */
    public static function nord(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#a3be8c')),
            error:   Style::new()->bold()->foreground(Color::hex('#bf616a')),
            warn:    Style::new()->bold()->foreground(Color::hex('#ebcb8b')),
            info:    Style::new()->bold()->foreground(Color::hex('#88c0d0')),
            prompt:  Style::new()->bold()->foreground(Color::hex('#5e81ac')),
            accent:  Style::new()->bold()->foreground(Color::hex('#88c0d0')),
            muted:   Style::new()->foreground(Color::hex('#4c566a')),
        );
    }

    /** Catppuccin Mocha — pastel set. */
    public static function catppuccin(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#a6e3a1')),
            error:   Style::new()->bold()->foreground(Color::hex('#f38ba8')),
            warn:    Style::new()->bold()->foreground(Color::hex('#f9e2af')),
            info:    Style::new()->bold()->foreground(Color::hex('#94e2d5')),
            prompt:  Style::new()->bold()->foreground(Color::hex('#cba6f7')),
            accent:  Style::new()->bold()->foreground(Color::hex('#cba6f7')),
            muted:   Style::new()->foreground(Color::hex('#a6adc8')),
        );
    }

    /**
     * Resolve a theme by name. Presets: `'ansi'`, `'plain'`, `'charm`,
     * `'dracula'`, `'nord'`, `'catppuccin'`. Case-insensitive.
     *
     * @throws \InvalidArgumentException if the name is not a string or not recognised
     */
    public static function byName(string $name): self
    {
        if (\is_string($name) === false) {
            throw new \InvalidArgumentException('Theme name must be a string, ' . \gettype($name) . ' given');
        }
        return match (strtolower($name)) {
            'ansi'        => self::ansi(),
            'plain'       => self::plain(),
            'charm'       => self::charm(),
            'dracula'     => self::dracula(),
            'nord'        => self::nord(),
            'catppuccin'  => self::catppuccin(),
            'auto'        => self::auto(),
            default       => throw new \InvalidArgumentException("unknown theme: {$name}"),
        };
    }
}

/**
 * Fluent builder for custom {@see Theme} instances.
 *
 * @internal  Use via {@see Theme::build()}
 */
final class ThemeBuilder
{
    private ?Style $success = null;
    private ?Style $error   = null;
    private ?Style $warn    = null;
    private ?Style $info    = null;
    private ?Style $prompt  = null;
    private ?Style $accent  = null;
    private ?Style $muted   = null;

    /** @return $this */
    public function success(Style $s): self { $this->success = $s; return $this; }

    /** @return $this */
    public function error(Style $s): self { $this->error = $s; return $this; }

    /** @return $this */
    public function warn(Style $s): self { $this->warn = $s; return $this; }

    /** @return $this */
    public function info(Style $s): self { $this->info = $s; return $this; }

    /** @return $this */
    public function prompt(Style $s): self { $this->prompt = $s; return $this; }

    /** @return $this */
    public function accent(Style $s): self { $this->accent = $s; return $this; }

    /** @return $this */
    public function muted(Style $s): self { $this->muted = $s; return $this; }

    /**
     * @throws \InvalidArgumentException if any field is still null
     */
    public function build(): Theme
    {
        return new Theme(
            success: $this->success ?? throw new \InvalidArgumentException('success style is required'),
            error:   $this->error   ?? throw new \InvalidArgumentException('error style is required'),
            warn:    $this->warn    ?? throw new \InvalidArgumentException('warn style is required'),
            info:    $this->info    ?? throw new \InvalidArgumentException('info style is required'),
            prompt:  $this->prompt  ?? throw new \InvalidArgumentException('prompt style is required'),
            accent:  $this->accent  ?? throw new \InvalidArgumentException('accent style is required'),
            muted:   $this->muted   ?? throw new \InvalidArgumentException('muted style is required'),
        );
    }
}
