<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Palettes;
use SugarCraft\Sprinkles\Style;

/**
 * Per-status palette used by {@see StatusLine} and {@see Banner}.
 * Themes are immutable; {@see ansi()} ships the default colourful
 * palette, {@see plain()} produces a no-op palette ideal for
 * snapshot tests, and a handful of named presets (`charm`,
 * `dracula`, `nord`, `catppuccin`) cover the most-requested
 * branded palettes.
 *
 * Every presenter that is handed no theme falls back to {@see detect()},
 * not {@see ansi()}: the colourful palette downgraded to what the output
 * stream can show, so `myapp --help | less` or `NO_COLOR=1 myapp` gets no
 * colour bytes. Passing an explicit theme (including `ansi()`) is taken as
 * the caller's decision and is rendered as given; combine it with
 * {@see withColorProfile()} to apply the same downgrade to any preset.
 */
final class Theme
{
    /**
     * All seven slots are non-nullable: a theme without a style for a level
     * is an illegal state, so the type system enforces it at the boundary
     * (strict_types turns a null argument into a TypeError) instead of a
     * hand-rolled constructor loop.
     */
    public function __construct(
        public readonly Style $success,
        public readonly Style $error,
        public readonly Style $warn,
        public readonly Style $info,
        public readonly Style $prompt,
        public readonly Style $accent,
        public readonly Style $muted,
    ) {}

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

    /**
     * The {@see ansi()} palette downgraded to the colour capability of the
     * output stream — the fallback every presenter uses when no theme is
     * passed.
     *
     * The capability comes from candy-core's {@see ColorProfile::detect()},
     * which owns the whole convention order (NO_COLOR, non-tty output,
     * CLICOLOR_FORCE / FORCE_COLOR, COLORTERM, TERM, ...), and is applied via
     * {@see withColorProfile()}. Detection runs on every call: no result is
     * cached, so a process that changes its environment or output stream is
     * never served a stale answer.
     *
     * Mirrors charmbracelet/fang, which renders through
     * `colorprofile.Detect(w, os.Environ())`. One known divergence lives in
     * candy-core, not here: core checks NO_COLOR before tty-ness, so a
     * non-tty stream with NO_COLOR set resolves to Ascii (bold/faint kept)
     * where upstream resolves it to NoTTY (no escape bytes at all). The
     * guarantee that holds either way is "no colour"; this class inherits
     * the upstream order as soon as core adopts it.
     *
     * @param array<string,string>|null $env    environment to inspect;
     *                                          null = the process environment
     * @param resource|null             $stream stream whose tty-ness decides
     *                                          whether colour is wanted;
     *                                          null = STDOUT (when the SAPI
     *                                          defines it); a closed STDOUT
     *                                          is treated as a non-tty
     *                                          stream, never as an error
     *
     * @throws \InvalidArgumentException when $stream is passed and is not
     *                                   an open stream resource
     */
    public static function detect(?array $env = null, mixed $stream = null): self
    {
        if ($stream === null) {
            return self::ansi()->withColorProfile(self::detectDefaultStream($env));
        }
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException(
                'Theme::detect() expects a stream resource or null; got ' . get_debug_type($stream) . '.'
            );
        }
        return self::ansi()->withColorProfile(ColorProfile::detect($env, $stream));
    }

    /**
     * Profile for the default output when the caller named no stream.
     *
     * The bad-argument guard in {@see detect()} is for a stream the caller
     * passed; STDOUT is substituted here, so its state must never surface
     * as the caller's error. A process that `fclose(STDOUT)`s (daemons and
     * workers that log elsewhere) still has the constant defined but no
     * terminal behind it — that is "output is not a tty", so it is detected
     * exactly as any non-tty stream would be, against a throwaway in-memory
     * stream. That keeps candy-core the sole owner of the convention order
     * (NO_COLOR, CLICOLOR_FORCE / FORCE_COLOR, ...) instead of copying it.
     *
     * With no STDOUT constant at all (non-CLI SAPI) there is no stream to
     * ask, so tty detection is skipped and the environment decides.
     *
     * @param array<string,string>|null $env
     */
    private static function detectDefaultStream(?array $env): ColorProfile
    {
        if (!\defined('STDOUT')) {
            return ColorProfile::detect($env, null);
        }
        if (\is_resource(\STDOUT)) {
            return ColorProfile::detect($env, \STDOUT);
        }

        $notATty = fopen('php://memory', 'r');
        if ($notATty === false) {
            // Unreachable in practice; a closed STDOUT is not a terminal.
            return ColorProfile::NoTty;
        }
        try {
            return ColorProfile::detect($env, $notATty);
        } finally {
            fclose($notATty);
        }
    }

    /**
     * Downgrade every style in this theme to $profile.
     *
     * - `TrueColor` / `Ansi256` / `Ansi`: colours are re-quantised to the
     *   tier the terminal supports; text attributes (bold, faint, ...) stay.
     * - `Ascii` (e.g. NO_COLOR): colours are dropped, text attributes stay —
     *   NO_COLOR forbids colour, not emphasis.
     * - `NoTty` (output is not a terminal): colours AND every SGR text
     *   attribute plus OSC 8 hyperlinks are dropped, so the theme emits no
     *   escape sequence at all. Layout properties (padding, borders, width)
     *   are kept, so the plain text lines up exactly as the styled one did.
     *
     * The downgrade is one-way: attributes removed for `NoTty` are not
     * restored by a later call with a richer profile.
     */
    public function withColorProfile(ColorProfile $profile): self
    {
        $fit = $profile === ColorProfile::NoTty
            ? static fn (Style $s): Style => $s->colorProfile($profile)
                ->unsetBold()->unsetItalic()->unsetUnderline()->unsetStrikethrough()
                ->unsetFaint()->unsetBlink()->rapidBlink(false)->unsetReverse()
                ->unsetOverline()->unsetInvisible()->unsetHyperlink()
            : static fn (Style $s): Style => $s->colorProfile($profile);

        return new self(
            success: $fit($this->success),
            error:   $fit($this->error),
            warn:    $fit($this->warn),
            info:    $fit($this->info),
            prompt:  $fit($this->prompt),
            accent:  $fit($this->accent),
            muted:   $fit($this->muted),
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
     * passes it here to select the appropriate palette. Theme itself keeps no
     * state — the program owns the detection — so calling this with no msg
     * simply returns the {@see ansi()} fallback.
     *
     * Dark terminals get the {@see nord()} palette; light terminals get
     * {@see catppuccin()}.
     *
     * @param BackgroundColorMsg|null $bg  parsed OSC-11 reply; null (or a
     *                                     reply that never arrived) falls back
     *                                     to {@see ansi()}
     */
    public static function auto(?BackgroundColorMsg $bg = null): self
    {
        if ($bg !== null) {
            return $bg->isDark() ? self::nord() : self::catppuccin();
        }
        // Stateless: without a msg in hand there is nothing to detect from.
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

    /**
     * Dracula palette — hex literals re-sourced from candy-core's
     * {@see Palettes::DRACULA} single-source-of-truth so they no longer
     * drift as they are hand-copied around the monorepo.
     *
     * `accent` binds Dracula's `pink` (matching candy-sprinkles' accent
     * slot), not `purple`; before W5 it had diverged to `purple` #bd93f9.
     */
    public static function dracula(): self
    {
        $p = Palettes::DRACULA;
        return new self(
            success: Style::new()->bold()->foreground(Color::hex($p['green'])),
            error:   Style::new()->bold()->foreground(Color::hex($p['red'])),
            warn:    Style::new()->bold()->foreground(Color::hex($p['yellow'])),
            info:    Style::new()->bold()->foreground(Color::hex($p['cyan'])),
            prompt:  Style::new()->bold()->foreground(Color::hex($p['pink'])),
            accent:  Style::new()->bold()->foreground(Color::hex($p['pink'])),
            muted:   Style::new()->foreground(Color::hex($p['comment'])),
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
     * Resolve a theme by name. Presets: `'ansi'`, `'plain'`, `'charm'`,
     * `'dracula'`, `'nord'`, `'catppuccin'`, and `'auto'` (the adaptive
     * factory with no background msg — the {@see ansi()} fallback).
     * Case-insensitive.
     *
     * @throws \InvalidArgumentException if the name is not a recognised preset
     */
    public static function byName(string $name): self
    {
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
