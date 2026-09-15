<?php

declare(strict_types=1);

/**
 * CandyPalette — terminal color profile detection and color degradation.
 *
 * Port of charmbracelet/colorprofile providing:
 * - Detection of terminal color capability via environment + TTY inspection
 * - Color conversion (TrueColor → ANSI256 → ANSI16 → ASCII)
 * - ProfileWriter for automatic ANSI degradation on write
 * - NO_COLOR / FORCE_COLOR / COLORTERM standard env var support
 *
 * @see https://github.com/charmbracelet/colorprofile
 */
namespace SugarCraft\Palette;

use SugarCraft\Palette\Color;
use SugarCraft\Palette\Profile;

/**
 * Detect and query the terminal color profile.
 *
 * Mirrors the top-level functions in charmbracelet/colorprofile.
 */
final class Palette
{
    /**
     * Parameter lists longer than this are passed through untouched; no real
     * SGR sequence approaches it, and explode()ing a megabyte-scale one is a
     * memory amplification on an attacker-influenced render path.
     */
    private const MAX_SGR_PARAMS_BYTES = 4096;

    private Profile $profile;

    /**
     * Detect the terminal color profile from the environment.
     *
     * Priority (aligned with Probe::colorProfile SSOT):
     *  1. CLICOLOR_FORCE=1   → TrueColor (SugarCraft extension)
     *  2. FORCE_COLOR=0..3   → profile by level (SugarCraft extension)
     *  3. NO_COLOR=          → NoTTY
     *  4. CLICOLOR=0         → NoTTY
     *  5. COLORTERM=24bit|truecolor|yes → TrueColor
     *  6. TERM_PROGRAM=iTerm.app → TrueColor
     *  7. TERM=dumb          → NoTTY
     *  8. WT_SESSION set     → TrueColor
     *  9. GOOGLE_CLOUD_SHELL=true → TrueColor
     * 10. TMUX||STY + TERM screen/tmux → ANSI256
     * 11. TERM=*-256color|xterm-kitty|xterm-ghostty → ANSI256
     * 12. TERM=xterm*|screen*|tmux* → ANSI
     * 13. TTY detection      → NoTTY if not a TTY
     * 14. Default            → ANSI
     *
     * @param resource|null $stream  Stream to check for TTY (default: STDOUT)
     * @param array<string,string|null> $env     Environment map (default: $_ENV)
     */
    public function __construct(
        $stream = null,
        array $env = [],
    ) {
        $this->profile = self::detectProfile($stream, $env);
    }

    /**
     * Get the detected profile.
     */
    public function profile(): Profile
    {
        return $this->profile;
    }

    /**
     * Override the detected profile (e.g. manually downgrade for testing).
     */
    public function withProfile(Profile $profile): self
    {
        $clone = clone $this;
        $clone->profile = $profile;
        return $clone;
    }

    /**
     * Shortcut: detect and return the profile enum.
     *
     * @param resource|null $stream
     * @param array<string,string|null> $env
     */
    public static function detect($stream = null, array $env = []): Profile
    {
        return (new self($stream, $env))->profile();
    }

    /**
     * Convert a color to the detected (or manually set) profile.
     */
    public function convert(Color $color): Color
    {
        return $color->convert($this->profile);
    }

    /**
     * Static shortcut for one-off color conversion.
     */
    public static function toProfile(Color $color, Profile $profile): Color
    {
        return $color->convert($profile);
    }

    /**
     * Convert any TrueColor/ANSI256/ANSI sequence in a string to
     * match the current profile, and strip if NoTTY.
     *
     * @param string $ansi  A string potentially containing SGR/CSI/OSC sequences
     * @return string       The string with color codes degraded/stripped
     */
    public function degrade(string $ansi): string
    {
        if ($this->profile === Profile::NoTTY) {
            return $this->stripAnsi($ansi);
        }

        if ($this->profile === Profile::TrueColor) {
            return $ansi; // No conversion needed
        }

        return $this->rewriteAnsi($ansi, $this->profile);
    }

    /**
     * Strip all ANSI escape sequences from a string.
     * Used when NoTTY is active.
     */
    public static function stripAnsi(string $s): string
    {
        // CSI sequences: \x1b[...{letter}
        // Extended CSI: \x1b[?... (private mode), \x1b[>... (private mode), \x1b[=... (private mode)
        // OSC sequences: \x1b]...(\x07|\x1b\\)
        // DCS sequences: \x1bP...(\x07|\x1b\\)
        // SS3 sequences: \x1bO{letter}
        // APC sequences: \x1b_...(\x07|\x1b\\)
        // Charset selectors: \x1b(B, \x1b(U, etc.
        return \preg_replace(
            '/(?:\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|'
            . '\x1b\[[0-9;:<>=?]*[A-Za-z]|'
            . '\x1b[PX^_][^\x07\x1b]*(?:\x07|\x1b\\\\)|'
            . '\x1b[OopeHMJKhCBDsu]|'
            . '\x1b[()*+][@-~])/',
            '',
            $s,
        ) ?? $s;
    }

    /**
     * Comment on the detected profile in a human-readable way.
     */
    public function comment(): string
    {
        return match ($this->profile) {
            Profile::TrueColor => 'fancy',
            Profile::ANSI256   => '1990s fancy',
            Profile::ANSI      => 'normcore',
            Profile::Ascii     => 'ancient',
            Profile::NoTTY     => 'naughty!',
        };
    }

    /**
     * Full descriptive sentence about the terminal's color capabilities.
     */
    public function describe(): string
    {
        return "Your terminal supports {$this->profile->label()} ({$this->profile->description()}).";
    }

    // -------------------------------------------------------------------------
    // Private detection logic
    // -------------------------------------------------------------------------

    /**
     * Core detection logic — uses DetectionChain for env-based detection.
     *
     * Priority:
     *  1. CLICOLOR_FORCE=1      → TrueColor (overrides everything)
     *  2. FORCE_COLOR           → SugarCraft extension level override (0=Ascii, 1=ANSI, 2=ANSI256, 3=TC)
     *  3. NO_COLOR (any value) → NoTTY
     *  4. CLICOLOR=0            → NoTTY
     *  5. COLORTERM=24bit|truecolor|yes → TrueColor
     *  6. TERM_PROGRAM=iTerm.app → TrueColor (Palette-specific, not in Probe)
     *  7. TERM=dumb             → NoTTY
     *  8. WT_SESSION set        → TrueColor (Windows Terminal)
     *  9. GOOGLE_CLOUD_SHELL=true → TrueColor
     * 10. TMUX||STY + base TERM screen/tmux → ANSI256
     * 11. TERM=*-256color|xterm-kitty|xterm-ghostty → ANSI256
     * 12. TERM=xterm*|screen*|tmux* → ANSI
     * 13. isatty()              → NoTTY if not a TTY
     * 14. Default               → ANSI
     */
    private static function detectProfile($stream, array $env): Profile
    {
        // 1. CLICOLOR_FORCE=1 → TrueColor (overrides everything below)
        $cliclorForce = $env['CLICOLOR_FORCE'] ?? \getenv('CLICOLOR_FORCE');
        if ($cliclorForce === '1') {
            return Profile::TrueColor;
        }

        // 2. FORCE_COLOR: SugarCraft extension (level-based) — not in DetectionChain
        $force = $env['FORCE_COLOR'] ?? $_ENV['FORCE_COLOR'] ?? \getenv('FORCE_COLOR');
        if (\is_string($force) && $force !== '') {
            $level = \intval($force);
            return match (true) {
                $level >= 3 => Profile::TrueColor,
                $level === 2 => Profile::ANSI256,
                $level === 1 => Profile::ANSI,
                default => Profile::Ascii,
            };
        }

        // Use DetectionChain for core env-based detection (steps 3-12)
        $chain = DetectionChain::detect($env);

        // 3-7: Handle NO_COLOR, CLICOLOR=0, TERM=dumb via DetectionChain
        if (!$chain->allowsColor()) {
            // TERM_PROGRAM=iTerm.app check is Palette-specific (not in Probe/TerminalProbe)
            $termProgram = $env['TERM_PROGRAM'] ?? $_ENV['TERM_PROGRAM'] ?? \getenv('TERM_PROGRAM') ?: null;
            if ($termProgram === 'iTerm.app') {
                return Profile::TrueColor;
            }
            return Profile::NoTTY;
        }

        // 8-12: Return the profile from DetectionChain
        $profile = $chain->toProfile();

        // TERM_PROGRAM=iTerm.app check (only if color is allowed)
        $termProgram = $env['TERM_PROGRAM'] ?? $_ENV['TERM_PROGRAM'] ?? \getenv('TERM_PROGRAM') ?: null;
        if ($termProgram === 'iTerm.app') {
            return Profile::TrueColor;
        }

        // 13. TTY detection — Palette-specific (Probe and TerminalProbe don't
        // check TTY). Only applies when the caller hands us a stream: with
        // $stream === null detection is env-only, mirroring upstream
        // colorprofile.Detect which checks isatty on the *passed* writer.
        // (The old posix_isatty(STDOUT) fallback made env-only detection
        // return NoTTY under any piped/CI stdout, regardless of $env.)
        if ($stream !== null && \function_exists('stream_isatty')) {
            if (!@\stream_isatty($stream)) {
                return Profile::NoTTY;
            }
        }

        return $profile;
    }

    /**
     * Rewrite ANSI SGR sequences in a string to match $targetProfile.
     *
     * Walks every CSI …m sequence, not just ones that happen to START with a
     * color introducer: attribute-prefixed forms (`\e[1;38;2;…m`), combined
     * forms (`\e[38;5;n;4m`) and colon sub-parameter forms (`\e[38:2::r:g:b`,
     * ECMA-48) all get their color segments rewritten while every other
     * parameter — 1/2/3/4/5/7/8/9/21/53, the resets 39/49/59, styled-underline
     * `4:n` — passes through untouched, and an underline color (SGR 58) is
     * re-emitted per-profile rather than dropped or left at a depth the
     * terminal cannot address.
     */
    private function rewriteAnsi(string $s, Profile $targetProfile): string
    {
        return \preg_replace_callback(
            '/\x1b\[([0-9;:]*)m/',
            fn (array $m): string => $this->rewriteSgr($m[1], $targetProfile),
            $s,
        ) ?? $s;
    }

    /**
     * Rewrite one SGR parameter list (the text between `CSI` and `m`).
     */
    private function rewriteSgr(string $params, Profile $target): string
    {
        // Fast path: no extended-colour introducer → byte-identical passthrough.
        // (Leading zeros are valid ECMA-48 padding: `038` introduces colour 38.)
        // Absurdly long parameter lists also pass through untouched — real SGR
        // lists fit in a few hundred bytes, and walking a megabyte-scale one
        // would multiply memory for no benefit on hostile input.
        if (
            $params === ''
            || \strlen($params) > self::MAX_SGR_PARAMS_BYTES
            || \preg_match('/(?:^|;)0*(?:38|48|58)(?:$|[;:])/', $params) !== 1
        ) {
            return "\x1b[{$params}m";
        }

        $groups = \explode(';', $params);
        $out = [];
        $count = \count($groups);

        for ($i = 0; $i < $count; $i++) {
            $subs = \explode(':', $groups[$i]);
            $head = $subs[0];
            if ($head === '' || !\ctype_digit($head)) {
                $out[] = $groups[$i];
                continue;
            }
            $intro = (int) $head;
            if ($intro !== 38 && $intro !== 48 && $intro !== 58) {
                $out[] = $groups[$i];
                continue;
            }

            $consumed = 0;
            // Colon form: the whole spec lives inside this one group
            // (`38:5:196`, `38:2::r:g:b`). Otherwise it continues across the
            // following semicolon-separated groups.
            $spec = \count($subs) > 1
                ? $this->parseColonColor(\array_slice($subs, 1))
                : $this->parseSemicolonColor($groups, $i, $consumed);
            if ($spec === null) {
                // Not a colour spec we understand (e.g. `38;3` CMY) — pass through.
                $out[] = $groups[$i];
                continue;
            }
            [$kind, $values] = $spec;
            $out[] = $this->emitColor($intro, $kind, $values, $target);
            $i += $consumed;
        }

        return "\x1b[" . \implode(';', $out) . 'm';
    }

    /**
     * Parse `…;5;n` / `…;2;r;g;b` (and missing-argument defaults) following a
     * lone 38/48/58 group. ECMA-48 defaults absent colour ids/components to 0.
     *
     * @param list<string> $groups
     * @param-out int      $consumed following groups taken by the spec
     *
     * @return array{0:int,1:list<int>} [colourId (5|2), components]
     */
    private function parseSemicolonColor(array $groups, int $i, int &$consumed): ?array
    {
        $consumed = 0;
        if (!isset($groups[$i + 1])) {
            // Trailing `38` with no spec: xterm defaults the colour to slot 0.
            return [5, [0]];
        }
        $subs = \explode(':', $groups[$i + 1]);
        $type = (int) $subs[0];

        if ($type === 5) {
            if (isset($subs[1]) && $subs[1] !== '') {
                $consumed = 1;
                return [5, [self::sgrInt($subs[1])]];
            }
            $consumed = 2;
            return [5, [$this->firstSub($groups[$i + 2] ?? null) ?? 0]];
        }

        if ($type === 2) {
            // `38;2::r:g:b` / `38;2:tol;r;g;b` / `38;2;r;g;b`
            $tail = \array_values(\array_filter(
                \array_slice($subs, 1),
                static fn (string $v): bool => $v !== '',
            ));
            if (\count($tail) >= 3) {
                // Colon sub-parameters may carry a colour-space id / tolerance
                // before the components (`38;2:1:tol;r:g:b`-style continuations);
                // the components are always the LAST three values. Known edge:
                // for a hybrid form that packs ≥4 sub-parameters into the
                // semicolon-headed type group (`38;2:cs:tol:r:g:b;extra`) the
                // leading fields are consumed here while later SEMICOLON groups
                // pass through as plain attributes — xterm would keep pulling
                // components from them. No real emitter produces the hybrid;
                // fully-colon and fully-semicolon forms both parse exactly.
                $consumed = 1;
                $rgb = \array_slice($tail, -3);
                return [2, \array_map(self::sgrInt(...), $rgb)];
            }
            $consumed = 4;
            return [2, [
                $this->firstSub($groups[$i + 2] ?? null) ?? 0,
                $this->firstSub($groups[$i + 3] ?? null) ?? 0,
                $this->firstSub($groups[$i + 4] ?? null) ?? 0,
            ]];
        }

        return null;
    }

    /**
     * Parse the sub-parameter list of a colon-form spec (`5:196`, `2:r:g:b`,
     * `2:tol:r:g:b` — colour-space/tolerance fields precede the trailing three
     * components; an empty tolerance sub-parameter is skipped). Missing
     * components default to 0, matching the semicolon path and xterm.
     *
     * @param list<string> $subs
     *
     * @return array{0:int,1:list<int>}|null
     */
    private function parseColonColor(array $subs): ?array
    {
        $type = (int) ($subs[0] ?? '');
        if ($type === 5) {
            return [5, [self::sgrInt((string) ($subs[1] ?? '0'))]];
        }
        if ($type === 2) {
            $tail = \array_values(\array_filter(
                \array_slice($subs, 1),
                static fn (string $v): bool => $v !== '',
            ));
            $rgb = \array_slice($tail, -3);
            $rgb = \array_pad($rgb, 3, '0');
            return [2, \array_map(self::sgrInt(...), $rgb)];
        }
        return null;
    }

    /**
     * First sub-parameter of a group as int, or null for a missing/non-numeric group.
     */
    private function firstSub(?string $group): ?int
    {
        if ($group === null) {
            return null;
        }
        if ($group === '') {
            return 0;
        }
        $sub = \explode(':', $group)[0];
        return \ctype_digit($sub) ? self::sgrInt($sub) : null;
    }

    /**
     * Cast an SGR numeric literal, capping absurd magnitudes at 999 instead of
     * letting a >19-digit run overflow the int cast through INF (where a
     * "huge red" would silently come out as 0/black). Colour components clamp
     * to 255 downstream, so 999 saturates identically to PHP_INT_MAX.
     */
    private static function sgrInt(string $digits): int
    {
        $trimmed = \ltrim($digits, '0');
        if ($trimmed === '') {
            return 0;
        }
        return \strlen($trimmed) > 3 ? 999 : (int) $trimmed;
    }

    /**
     * Render the parsed colour as replacement SGR parameters for $target.
     *
     * At ANSI256 an in-range slot is already exactly addressable, so it is
     * passed through as the slot the theme picked — decoding to RGB and
     * re-quantising could knock a themeable basic slot (0-15) onto the fixed
     * cube and strip the user's palette. 38/48 at 16-colour depth collapse to
     * the 4-bit SGR; 58 keeps its introducer (underlines need a colour id a
     * 4-bit code cannot carry) pinned to the nearest of the 16 palette slots.
     *
     * @param list<int> $values
     */
    private function emitColor(int $intro, int $kind, array $values, Profile $target): string
    {
        $slot = $kind === 5 ? $values[0] : null;

        if ($target === Profile::ANSI256) {
            if ($slot !== null && $slot >= 0 && $slot <= 255) {
                return "{$intro};5;{$slot}";
            }
            $color = $kind === 2
                ? new Color($values[0], $values[1], $values[2])
                : Color::fromAnsi256Index($slot ?? 0);
            return "{$intro};5;{$color->toAnsi256Index()}";
        }

        // ANSI / Ascii.
        $color = $kind === 2
            ? new Color($values[0], $values[1], $values[2])
            : Color::fromAnsi256Index($slot ?? 0);
        $idx = $color->convert($target)->toAnsi16Index();

        if ($intro === 58) {
            return "58;5;{$idx}";
        }
        return (string) Color::ansi16Sgr($idx, $intro === 48);
    }
}
