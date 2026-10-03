<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Terminal color capability tiers, matching charmbracelet/colorprofile.
 */
enum ColorProfile: int
{
    case NoTty     = 0;
    case Ascii     = 1;
    case Ansi      = 2;
    case Ansi256   = 3;
    case TrueColor = 4;

    /**
     * Detect the active terminal's color profile.
     *
     * Mirrors charmbracelet/colorprofile.Detect / colorProfile. Decision order:
     *   1. Output is not a TTY → `NoTty` — checked FIRST, so `NO_COLOR` on a
     *      pipe is `NoTty` (no escape bytes at all), not `Ascii`. A truthy
     *      `CLICOLOR_FORCE`/`FORCE_COLOR` says "emit escapes anyway" and so
     *      counts as a terminal here.
     *   2. `TERM=dumb` → `NoTty` unless forced (upstream: "TERM=dumb is always
     *      treated as NoTTY unless CLICOLOR_FORCE=1 is set").
     *   3. `NO_COLOR` set → `Ascii`: colour off, text decoration (bold, faint,
     *      italic …) kept, per no-color.org. It outranks the force variables.
     *   4. `CLICOLOR_FORCE`/`FORCE_COLOR` truthy → `TrueColor`
     *   5. `COLORTERM` ∈ {truecolor, 24bit} → `TrueColor`
     *   6. Known `TERM_PROGRAM` values → matched tier
     *   7. `WT_SESSION` (Windows Terminal) set → `TrueColor`
     *   8. `TERM` substring match: `*direct*`/`*truecolor*` → `TrueColor`,
     *      `*256*` → `Ansi256`, `*color*`/xterm/screen/tmux → `Ansi`
     *   9. CI environment → `Ansi` (most CI logs render 16-color OK)
     *  10. fallback → `Ascii`
     *
     * @param array<string,string>|null $env  defaults to a snapshot of getenv()
     * @param resource|null             $stdout used to query `stream_isatty`;
     *                                          pass `null` to skip TTY detection
     *                                          (downstream code that already
     *                                          knows the destination is a TTY).
     */
    public static function detect(?array $env = null, $stdout = null): self
    {
        $env ??= self::defaultEnv();

        $force = self::truthy($env['CLICOLOR_FORCE'] ?? '')
              || self::truthy($env['FORCE_COLOR']    ?? '');

        if (!$force && $stdout !== null && is_resource($stdout)) {
            if (!stream_isatty($stdout)) {
                return self::NoTty;
            }
        }

        $term      = strtolower($env['TERM']         ?? '');
        $colorTerm = strtolower($env['COLORTERM']    ?? '');
        $program   = strtolower($env['TERM_PROGRAM'] ?? '');

        if ($term === 'dumb' && !$force) {
            return self::NoTty;
        }

        if (self::truthy($env['NO_COLOR'] ?? '')) {
            return self::Ascii;
        }

        if ($force) {
            return self::TrueColor;
        }

        if ($colorTerm === 'truecolor' || $colorTerm === '24bit') {
            return self::TrueColor;
        }

        if ($program !== '') {
            $match = match (true) {
                str_contains($program, 'iterm')         => self::TrueColor,
                str_contains($program, 'wezterm')       => self::TrueColor,
                str_contains($program, 'vscode')        => self::TrueColor,
                str_contains($program, 'kitty')         => self::TrueColor,
                str_contains($program, 'alacritty')     => self::TrueColor,
                str_contains($program, 'ghostty')       => self::TrueColor,
                str_contains($program, 'apple_terminal') => self::Ansi256,
                str_contains($program, 'hyper')         => self::TrueColor,
                default                                 => null,
            };
            if ($match !== null) {
                return $match;
            }
        }

        if (($env['WT_SESSION'] ?? '') !== '') {
            return self::TrueColor;
        }

        if (str_contains($term, 'truecolor') || str_contains($term, 'direct')) {
            return self::TrueColor;
        }

        if (str_contains($term, '256')) {
            return self::Ansi256;
        }

        if (str_contains($term, 'color') || $term === 'xterm' || $term === 'screen' || $term === 'tmux') {
            return self::Ansi;
        }

        // CI runners usually produce ANSI-coloured logs.
        if (self::truthy($env['CI']        ?? '')
         || self::truthy($env['GITHUB_ACTIONS'] ?? '')
         || self::truthy($env['GITLAB_CI'] ?? '')
         || self::truthy($env['BUILDKITE'] ?? '')) {
            return self::Ansi;
        }

        if ($term === '') {
            return self::Ascii;
        }

        return self::Ascii;
    }

    public function supportsAnsi(): bool
    {
        return $this->value >= self::Ansi->value;
    }
    public function supports256(): bool
    {
        return $this->value >= self::Ansi256->value;
    }
    public function supportsTrueColor(): bool
    {
        return $this->value >= self::TrueColor->value;
    }

    /** @return array<string,string> */
    private static function defaultEnv(): array
    {
        $out = [];
        foreach ([
            'NO_COLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR',
            'TERM', 'COLORTERM', 'TERM_PROGRAM',
            'WT_SESSION',
            'CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE',
        ] as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function truthy(string $v): bool
    {
        return $v !== '' && $v !== '0' && strtolower($v) !== 'false';
    }
}
