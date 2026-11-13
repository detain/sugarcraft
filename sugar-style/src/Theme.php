<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Palettes;

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
     * @param array<string,Style> $styles Role name → Style bindings (semantic roles, lipgloss Theme.StyleFunc family; default empty)
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
        public readonly array $styles = [],
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
            foreground:  Color::hex(Palettes::DRACULA['foreground']),
            background:  Color::hex(Palettes::DRACULA['background']),
            primary:     Color::hex(Palettes::DRACULA['purple']),
            secondary:   Color::hex(Palettes::DRACULA['green']),
            accent:      Color::hex(Palettes::DRACULA['pink']),
            muted:       Color::hex(Palettes::DRACULA['comment']),
            error:       Color::hex(Palettes::DRACULA['red']),
            warning:     Color::hex(Palettes::DRACULA['orange']),
            success:     Color::hex(Palettes::DRACULA['green']),
            info:        Color::hex(Palettes::DRACULA['cyan']),
            border:      Color::hex(Palettes::DRACULA['currentLine']),
            separator:   Color::hex(Palettes::DRACULA['separator']),
            cursor:      Color::hex(Palettes::DRACULA['foreground']),
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
            foreground:  Color::hex(Palettes::ONE_DARK['foreground']),
            background:  Color::hex(Palettes::ONE_DARK['background']),
            primary:      Color::hex(Palettes::ONE_DARK['blue']),
            secondary:    Color::hex(Palettes::ONE_DARK['green']),
            accent:       Color::hex(Palettes::ONE_DARK['magenta']),
            muted:        Color::hex(Palettes::ONE_DARK['comment']),
            error:        Color::hex(Palettes::ONE_DARK['red']),
            warning:      Color::hex(Palettes::ONE_DARK['yellow']),
            success:     Color::hex(Palettes::ONE_DARK['green']),
            info:        Color::hex(Palettes::ONE_DARK['cyan']),
            border:       Color::hex(Palettes::ONE_DARK['currentLine']),
            separator:    Color::hex(Palettes::ONE_DARK['separator']),
            cursor:       Color::hex(Palettes::ONE_DARK['cursor']),
        );
    }

    /** Mirrors charmbracelet/lipgloss.Theme.githubDark(). */
    public static function githubDark(): self
    {
        return new self(
            foreground:  Color::hex(Palettes::GITHUB_DARK['foreground']),
            background:   Color::hex(Palettes::GITHUB_DARK['background']),
            primary:      Color::hex(Palettes::GITHUB_DARK['blue']),
            secondary:    Color::hex(Palettes::GITHUB_DARK['green']),
            accent:       Color::hex(Palettes::GITHUB_DARK['pink']),
            muted:        Color::hex(Palettes::GITHUB_DARK['comment']),
            error:        Color::hex(Palettes::GITHUB_DARK['red']),
            warning:      Color::hex(Palettes::GITHUB_DARK['yellow']),
            success:      Color::hex(Palettes::GITHUB_DARK['green']),
            info:         Color::hex(Palettes::GITHUB_DARK['cyan']),
            border:       Color::hex(Palettes::GITHUB_DARK['currentLine']),
            separator:   Color::hex(Palettes::GITHUB_DARK['separator']),
            cursor:      Color::hex(Palettes::GITHUB_DARK['blue']),
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
     * Pick the matching member of the built-in dark/light pair from a
     * known polarity. The injectable seam {@see adaptive()} cannot accept
     * — callers that already know the background (an OSC-11 probe, a host
     * setting, a stored user preference) resolve the theme without
     * re-reading the environment.
     */
    public static function for(bool $isDark): self
    {
        return $isDark ? self::dark() : self::light();
    }

    /**
     * Auto-detect theme from the `COLORFGBG` environment variable.
     * Falls back to dark when the variable is absent or unparseable.
     *
     * Format: `COLORFGBG=foreground;background` (e.g. `15;0` or `0;15`),
     * or rxvt's three-field `foreground;xpm;background` — the background
     * is always the LAST field. It is an xterm-256 palette index; the
     * theme is light when that slot's default RGB is light (luminance
     * >= 0.5 via {@see Color::isDark()}), so white (7, 15) and pale
     * 256-colour slots (e.g. 255) read light, while black (0) and
     * bright-black (8, a dark grey) read dark. An empty, non-numeric
     * (e.g. `default`) or out-of-range (> 255) background falls back
     * to dark, as does a value with no `;` at all.
     *
     * Mirrors muesli/termenv's COLORFGBG read (last field) and its
     * `HasDarkBackground()` luminance test, which lipgloss uses.
     *
     * @param bool $fallbackDark Polarity used when no usable signal is
     *                           present; default dark (finding 058's knob —
     *                           hosts that ship no COLORFGBG but run light
     *                           consoles pass false).
     */
    public static function adaptive(bool $fallbackDark = true): self
    {
        $fallback = $fallbackDark ? self::dark() : self::light();

        $raw = getenv('COLORFGBG');
        if ($raw === false || trim($raw) === '') {
            return $fallback;
        }

        $fields = explode(';', trim($raw));
        if (count($fields) < 2) {
            // A lone number names no background — termenv requires the `;`.
            return $fallback;
        }
        $background = trim((string) end($fields));
        if (!ctype_digit($background)) {
            return $fallback;
        }

        $index = (int) $background;
        if ($index > 255) {
            return $fallback;
        }

        return Color::ansi256($index)->isDark() ? self::dark() : self::light();
    }

    /**
     * Enumerate the names of all built-in theme factories.
     *
     * Each entry maps to a same-named static factory on this class
     * (e.g. `'dracula'` → {@see Theme::dracula()}; the factories take no
     * required arguments). The trailing `'adaptive'`
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

    /**
     * The Style bound to a semantic role, or null when the theme defines
     * no binding for it. Mirrors charmbracelet/lipgloss's Theme.StyleFunc
     * (whose (Style, bool) tuple collapses to the nullable read here).
     */
    public function style(string $role): ?Style
    {
        return $this->styles[$role] ?? null;
    }

    /**
     * Returns a new Theme with one role binding replaced — or removed when
     * $style is null. The registry rides the private copy door, so no
     * wither can drop it: the lesson of finding 037 applied to the 14th
     * field itself (finding 003).
     */
    public function withStyle(string $role, ?Style $style): self
    {
        $styles = $style === null
            ? array_diff_key($this->styles, [$role => true])
            : array_merge($this->styles, [$role => $style]);

        return $this->with(['styles' => $styles]);
    }

    // ─── Fluent withers ──────────────────────────────────────────────────

    /**
     * The single copy door — every wither declares only the slot it
     * replaces and rides this choke (mirrors the private `with()` idiom
     * of {@see Style}).
     *
     * Threading each of the 13 fields by hand through 13 withers was
     * correct today but structurally open to the 14th-field drop: add a
     * slot and every wither must remember it, with no test forcing the
     * update (finding 037). Here a new field costs exactly one `??=`
     * row of this method.
     *
     * @param array<string, Color> $overrides slot name => replacement colour
     */
    private function with(array $overrides): self
    {
        return new self(
            foreground: $overrides['foreground'] ?? $this->foreground,
            background: $overrides['background'] ?? $this->background,
            primary:    $overrides['primary'] ?? $this->primary,
            secondary:  $overrides['secondary'] ?? $this->secondary,
            accent:     $overrides['accent'] ?? $this->accent,
            muted:      $overrides['muted'] ?? $this->muted,
            error:      $overrides['error'] ?? $this->error,
            warning:    $overrides['warning'] ?? $this->warning,
            success:    $overrides['success'] ?? $this->success,
            info:       $overrides['info'] ?? $this->info,
            border:     $overrides['border'] ?? $this->border,
            separator:  $overrides['separator'] ?? $this->separator,
            cursor:     $overrides['cursor'] ?? $this->cursor,
            styles:     $overrides['styles'] ?? $this->styles,
        );
    }

    /**
     * Returns a new Theme with the foreground colour replaced.
     * @return self
     */
    public function withForeground(Color $foreground): self
    {
        return $this->with(['foreground' => $foreground]);
    }

    /**
     * Returns a new Theme with the background colour replaced.
     * @return self
     */
    public function withBackground(Color $background): self
    {
        return $this->with(['background' => $background]);
    }

    /**
     * Returns a new Theme with the primary colour replaced.
     * @return self
     */
    public function withPrimary(Color $primary): self
    {
        return $this->with(['primary' => $primary]);
    }

    /**
     * Returns a new Theme with the secondary colour replaced.
     * @return self
     */
    public function withSecondary(Color $secondary): self
    {
        return $this->with(['secondary' => $secondary]);
    }

    /**
     * Returns a new Theme with the accent colour replaced.
     * @return self
     */
    public function withAccent(Color $accent): self
    {
        return $this->with(['accent' => $accent]);
    }

    /**
     * Returns a new Theme with the muted colour replaced.
     * @return self
     */
    public function withMuted(Color $muted): self
    {
        return $this->with(['muted' => $muted]);
    }

    /**
     * Returns a new Theme with the error colour replaced.
     * @return self
     */
    public function withError(Color $error): self
    {
        return $this->with(['error' => $error]);
    }

    /**
     * Returns a new Theme with the warning colour replaced.
     * @return self
     */
    public function withWarning(Color $warning): self
    {
        return $this->with(['warning' => $warning]);
    }

    /**
     * Returns a new Theme with the success colour replaced.
     * @return self
     */
    public function withSuccess(Color $success): self
    {
        return $this->with(['success' => $success]);
    }

    /**
     * Returns a new Theme with the info colour replaced.
     * @return self
     */
    public function withInfo(Color $info): self
    {
        return $this->with(['info' => $info]);
    }

    /**
     * Returns a new Theme with the border colour replaced.
     * @return self
     */
    public function withBorder(Color $border): self
    {
        return $this->with(['border' => $border]);
    }

    /**
     * Returns a new Theme with the separator colour replaced.
     * @return self
     */
    public function withSeparator(Color $separator): self
    {
        return $this->with(['separator' => $separator]);
    }

    /**
     * Returns a new Theme with the cursor colour replaced.
     * @return self
     */
    public function withCursor(Color $cursor): self
    {
        return $this->with(['cursor' => $cursor]);
    }
}
