<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles;

use SugarCraft\Core\Util\Color;

/**
 * Canonical theme palette — the single source of truth for terminal
 * colour schemes across SugarCraft consumer libs.
 *
 * Mirrors charmbracelet/lipgloss.Theme.
 */
final class Theme
{
    /**
     * @param Color $foreground  Primary text colour
     * @param Color $background  Canvas colour
     * @param Color $primary     Main interactive / brand colour
     * @param Color $secondary   Supporting colour
     * @param Color $accent      Accent colour; defaults to $primary in basic themes, distinct in richer named themes
     * @param Color $muted       Muted colour; defaults to $secondary in basic themes, distinct in richer named themes
     * @param Color $error      Error / danger state
     * @param Color $warning    Warning state
     * @param Color $success    Positive / confirmation state
     * @param Color $info       Informational / neutral state
     * @param Color $border     Border / divider colour
     * @param Color $separator  Row / column separator (lighter than border)
     * @param Color $cursor     Caret / pointer colour
     * @return self
     */
    public function __construct(
        public readonly Color $foreground,
        public readonly Color $background,
        public readonly Color $primary,
        public readonly Color $secondary,
        public readonly Color $accent,
        public readonly Color $muted,
        public readonly Color $error,
        public readonly Color $warning,
        public readonly Color $success,
        public readonly Color $info,
        public readonly Color $border,
        public readonly Color $separator,
        public readonly Color $cursor,
    ) {}

    // ─── Named constructors ─────────────────────────────────────────────

    /** Mirrors charmbracelet/lipgloss.Theme.dark(). */
    public static function dark(): self
    {
        return new self(
            foreground:  Color::hex('#c5c9d4'),
            background:  Color::hex('#0e0e14'),
            primary:      Color::hex('#7091f5'),
            secondary:    Color::hex('#8b8fa8'),
            accent:       Color::hex('#7091f5'),
            muted:        Color::hex('#8b8fa8'),
            error:        Color::hex('#f5a0a0'),
            warning:      Color::hex('#e6c98a'),
            success:      Color::hex('#a0d8a0'),
            info:         Color::hex('#87c0d0'),
            border:       Color::hex('#2e3141'),
            separator:    Color::hex('#1e2030'),
            cursor:       Color::hex('#7091f5'),
        );
    }

    /** Mirrors charmbracelet/lipgloss.Theme.light(). */
    public static function light(): self
    {
        return new self(
            foreground:  Color::hex('#383a42'),
            background:   Color::hex('#fafafa'),
            primary:       Color::hex('#4f46e5'),
            secondary:     Color::hex('#10b981'),
            accent:       Color::hex('#4f46e5'),
            muted:        Color::hex('#6b7280'),
            error:        Color::hex('#ef4444'),
            warning:      Color::hex('#f59e0b'),
            success:      Color::hex('#10b981'),
            info:         Color::hex('#0ea5e9'),
            border:       Color::hex('#e5e7eb'),
            separator:    Color::hex('#f3f4f6'),
            cursor:       Color::hex('#4f46e5'),
        );
    }

    /** Mirrors charmbracelet/lipgloss.Theme.dracula(). */
    public static function dracula(): self
    {
        return new self(
            foreground:  Color::hex('#f8f8f2'),
            background:  Color::hex('#282a36'),
            primary:     Color::hex('#bd93f9'),
            secondary:   Color::hex('#50fa7b'),
            accent:      Color::hex('#ff79c6'),
            muted:       Color::hex('#6272a4'),
            error:       Color::hex('#ff5555'),
            warning:     Color::hex('#ffb86c'),
            success:     Color::hex('#50fa7b'),
            info:        Color::hex('#8be9fd'),
            border:      Color::hex('#44475a'),
            separator:   Color::hex('#383a46'),
            cursor:      Color::hex('#f8f8f2'),
        );
    }

    /** Mirrors charmbracelet/lipgloss.Theme.tokyoNight(). */
    public static function tokyoNight(): self
    {
        return new self(
            foreground:  Color::hex('#c0caf5'),
            background:   Color::hex('#1a1b26'),
            primary:      Color::hex('#7aa2f7'),
            secondary:    Color::hex('#9ece6a'),
            accent:       Color::hex('#bb9af7'),
            muted:        Color::hex('#565f89'),
            error:        Color::hex('#f7768e'),
            warning:      Color::hex('#e0af68'),
            success:      Color::hex('#9ece6a'),
            info:         Color::hex('#7dcfff'),
            border:       Color::hex('#292e42'),
            separator:    Color::hex('#1f2030'),
            cursor:       Color::hex('#c0caf5'),
        );
    }

    /** Mirrors charmbracelet/lipgloss.Theme.oneDark(). */
    public static function oneDark(): self
    {
        return new self(
            foreground:  Color::hex('#abb2bf'),
            background:  Color::hex('#282c34'),
            primary:      Color::hex('#61afef'),
            secondary:    Color::hex('#98c379'),
            accent:       Color::hex('#c678dd'),
            muted:        Color::hex('#5c6370'),
            error:        Color::hex('#e06c75'),
            warning:      Color::hex('#e5c07b'),
            success:     Color::hex('#98c379'),
            info:        Color::hex('#56b6c2'),
            border:       Color::hex('#3e4451'),
            separator:    Color::hex('#2c313a'),
            cursor:       Color::hex('#528bff'),
        );
    }

    /** Mirrors charmbracelet/lipgloss.Theme.githubDark(). */
    public static function githubDark(): self
    {
        return new self(
            foreground:  Color::hex('#c9d1d9'),
            background:   Color::hex('#0d1117'),
            primary:      Color::hex('#58a6ff'),
            secondary:    Color::hex('#3fb950'),
            accent:       Color::hex('#f778ba'),
            muted:        Color::hex('#8b949e'),
            error:        Color::hex('#f85149'),
            warning:      Color::hex('#d29922'),
            success:      Color::hex('#3fb950'),
            info:         Color::hex('#79c0ff'),
            border:       Color::hex('#30363d'),
            separator:   Color::hex('#161b22'),
            cursor:      Color::hex('#58a6ff'),
        );
    }

    /**
     * Mirrors charmbracelet/lipgloss.Theme.solarizedDark().
     * Based on the Solarized colour palette (Ethan Schoonover).
     */
    public static function solarizedDark(): self
    {
        return new self(
            foreground:  Color::hex('#839496'),
            background:  Color::hex('#002b36'),
            primary:      Color::hex('#268bd2'),
            secondary:    Color::hex('#859900'),
            accent:       Color::hex('#d33682'),
            muted:        Color::hex('#586e75'),
            error:        Color::hex('#dc322f'),
            warning:      Color::hex('#b58900'),
            success:      Color::hex('#859900'),
            info:        Color::hex('#2aa198'),
            border:       Color::hex('#073642'),
            separator:    Color::hex('#093a47'),
            cursor:       Color::hex('#839496'),
        );
    }

    /**
     * Mirrors charmbracelet/lipgloss.Theme.solarizedLight().
     * Based on the Solarized colour palette (Ethan Schoonover).
     */
    public static function solarizedLight(): self
    {
        return new self(
            foreground:  Color::hex('#657b83'),
            background:   Color::hex('#fdf6e3'),
            primary:      Color::hex('#268bd2'),
            secondary:    Color::hex('#859900'),
            accent:       Color::hex('#d33682'),
            muted:        Color::hex('#93a1a1'),
            error:        Color::hex('#dc322f'),
            warning:      Color::hex('#b58900'),
            success:      Color::hex('#859900'),
            info:        Color::hex('#2aa198'),
            border:       Color::hex('#eee8d5'),
            separator:    Color::hex('#f5efdb'),
            cursor:       Color::hex('#657b83'),
        );
    }

    /**
     * Terminal-default 8-colour ANSI theme. Mirrors
     * charmbracelet/lipgloss.Theme.ansi().
     */
    public static function ansi(): self
    {
        return new self(
            foreground:  Color::ansi(7),   // white
            background:  Color::ansi(0),   // black
            primary:     Color::ansi(6),   // cyan
            secondary:   Color::ansi(2),   // green
            accent:      Color::ansi(5),   // magenta
            muted:      Color::ansi(8),   // bright black
            error:      Color::ansi(1),   // red
            warning:    Color::ansi(3),   // yellow
            success:    Color::ansi(2),   // green
            info:       Color::ansi(4),   // blue
            border:     Color::ansi(8),   // bright black
            separator:  Color::ansi(0),   // black
            cursor:     Color::ansi(7),   // white
        );
    }

    /**
     * Auto-detect theme from the `COLORFGBG` environment variable.
     * Falls back to dark when the variable is absent or unparseable.
     *
     * Format: `COLORFGBG=foreground;background` (e.g. `15;0` or `0;15`).
     * If the background index is >= 8 the terminal uses a dark palette.
     *
     * Mirrors charmbracelet/lipgloss.Theme.adaptive().
     */
    public static function adaptive(): self
    {
        $raw = getenv('COLORFGBG');
        if ($raw === false || $raw === '') {
            return self::dark();
        }

        $parts = explode(';', $raw);
        if (count($parts) !== 2) {
            return self::dark();
        }

        $bg = (int) ($parts[1] ?? 0);

        return $bg >= 8 ? self::light() : self::dark();
    }

    /**
     * Enumerate the names of all built-in theme factories.
     *
     * Each entry maps to a same-named zero-arg static factory on this class
     * (e.g. `'dracula'` → {@see Theme::dracula()}). The trailing `'adaptive'`
     * is environment-derived (resolves to `dark`/`light` via `COLORFGBG`)
     * rather than a fixed palette. Listed in declaration order. Enables
     * programmatic discovery (e.g. a `--list-themes` command).
     *
     * @return list<string>
     */
    public static function catalog(): array
    {
        return ['dark', 'light', 'dracula', 'tokyoNight', 'oneDark', 'githubDark', 'solarizedDark', 'solarizedLight', 'ansi', 'adaptive'];
    }

    // ─── Fluent withers ──────────────────────────────────────────────────

    /**
     * Returns a new Theme with the foreground colour replaced.
     * @return self
     */
    public function withForeground(Color $foreground): self
    {
        return new self(
            foreground:  $foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $this->info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the background colour replaced.
     * @return self
     */
    public function withBackground(Color $background): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $this->info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the primary colour replaced.
     * @return self
     */
    public function withPrimary(Color $primary): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $this->info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the secondary colour replaced.
     * @return self
     */
    public function withSecondary(Color $secondary): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $this->info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the accent colour replaced.
     * @return self
     */
    public function withAccent(Color $accent): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $this->info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the muted colour replaced.
     * @return self
     */
    public function withMuted(Color $muted): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $this->info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the error colour replaced.
     * @return self
     */
    public function withError(Color $error): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $this->info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the warning colour replaced.
     * @return self
     */
    public function withWarning(Color $warning): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $warning,
            success:     $this->success,
            info:        $this->info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the success colour replaced.
     * @return self
     */
    public function withSuccess(Color $success): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $success,
            info:        $this->info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the info colour replaced.
     * @return self
     */
    public function withInfo(Color $info): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the border colour replaced.
     * @return self
     */
    public function withBorder(Color $border): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $this->info,
            border:      $border,
            separator:   $this->separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the separator colour replaced.
     * @return self
     */
    public function withSeparator(Color $separator): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $this->info,
            border:      $this->border,
            separator:   $separator,
            cursor:      $this->cursor,
        );
    }

    /**
     * Returns a new Theme with the cursor colour replaced.
     * @return self
     */
    public function withCursor(Color $cursor): self
    {
        return new self(
            foreground:  $this->foreground,
            background:  $this->background,
            primary:     $this->primary,
            secondary:   $this->secondary,
            accent:      $this->accent,
            muted:       $this->muted,
            error:       $this->error,
            warning:     $this->warning,
            success:     $this->success,
            info:        $this->info,
            border:      $this->border,
            separator:   $this->separator,
            cursor:      $cursor,
        );
    }
}
