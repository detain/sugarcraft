<?php

declare(strict_types=1);

namespace CandyCore\Core\Util;

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
     * Detect the active terminal's color profile from environment variables.
     *
     * @param array<string,string>|null $env defaults to $_SERVER + getenv()
     */
    public static function detect(?array $env = null): self
    {
        $env ??= self::defaultEnv();

        if (self::truthy($env['NO_COLOR'] ?? '')) {
            return self::Ascii;
        }
        if (self::truthy($env['CLICOLOR_FORCE'] ?? '')) {
            return self::TrueColor;
        }

        $term     = strtolower($env['TERM']      ?? '');
        $colorTerm = strtolower($env['COLORTERM'] ?? '');

        if ($term === '' || $term === 'dumb') {
            return self::Ascii;
        }

        if ($colorTerm === 'truecolor' || $colorTerm === '24bit') {
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

        return self::Ascii;
    }

    public function supportsAnsi(): bool      { return $this->value >= self::Ansi->value; }
    public function supports256(): bool       { return $this->value >= self::Ansi256->value; }
    public function supportsTrueColor(): bool { return $this->value >= self::TrueColor->value; }

    /** @return array<string,string> */
    private static function defaultEnv(): array
    {
        $out = [];
        foreach (['NO_COLOR', 'CLICOLOR_FORCE', 'TERM', 'COLORTERM'] as $k) {
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
