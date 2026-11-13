<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Resolves the colour capability for non-interactive output from the
 * standard negotiation family (NO_COLOR / CLICOLOR / CLICOLOR_FORCE /
 * FORCE_COLOR / TERM=dumb) plus the global `--ansi` / `--no-ansi`
 * flags, and pins it as sugar-style's ambient profile so every `Style`
 * minted afterwards — flag-built styles, log badges, and the
 * sugar-markdown theme styles used by `format` — renders inside the
 * resolved tier instead of emitting escapes the terminal would ignore.
 *
 * Tier meanings are sugar-core's ColorProfile semantics (documented on
 * {@see \SugarCraft\Style\Style::colorProfile()}):
 *   - `Ascii`  drops colour but keeps decoration (bold, underline, …);
 *   - `NoTty`  emits no escape sequences at all;
 *   - `Ansi` / `Ansi256` / `TrueColor` re-quantise colours to the tier.
 *
 * Precedence (first decisive rule wins): `--no-ansi` > `--ansi` >
 * `FORCE_COLOR` > `CLICOLOR_FORCE` > `NO_COLOR` > `CLICOLOR=0` >
 * `TERM=dumb` > default. Being a pipe does NOT disable colour on its
 * own — styled bytes flow to redirected output like they always have;
 * `CLICOLOR=0` opts into the pipe-aware behaviour instead.
 *
 * The gate deliberately does not call ColorProfile::detect(): that
 * ladder treats "stdout is not a TTY" as NoTty, which would strip the
 * styled bytes from redirected output by default and contradict both
 * the shipped goldens and the reference CLI's behaviour.
 */
final class ColourGate
{
    public const FORCE_COLOR_16    = '1';
    public const FORCE_COLOR_256   = '2';
    public const FORCE_COLOR_TC    = '3';

    /**
     * The narrowest colour capability in force for this invocation.
     * `ColorProfile::TrueColor` means "no restriction".
     */
    public static function cap(?InputInterface $input = null): ColorProfile
    {
        if ($input !== null) {
            if ($input->hasParameterOption('--no-ansi')) {
                return ColorProfile::NoTty;
            }
            if ($input->hasParameterOption('--ansi')) {
                return ColorProfile::TrueColor;
            }
        }

        $force = self::env('FORCE_COLOR');
        if (self::truthy($force)) {
            return match ($force) {
                self::FORCE_COLOR_16  => ColorProfile::Ansi,
                self::FORCE_COLOR_256 => ColorProfile::Ansi256,
                default               => ColorProfile::TrueColor,
            };
        }

        if (self::truthy(self::env('CLICOLOR_FORCE'))) {
            return ColorProfile::TrueColor;
        }

        if (self::truthy(self::env('NO_COLOR'))) {
            return ColorProfile::Ascii;
        }

        $clicolor = self::env('CLICOLOR');
        if ($clicolor !== null && !self::truthy($clicolor) && !self::stdoutIsTerminal()) {
            return ColorProfile::Ascii;
        }

        if (self::termIsDumb()) {
            return ColorProfile::NoTty;
        }

        return ColorProfile::TrueColor;
    }

    /**
     * scriptui-36 (finding 111): ONE shared dumb-terminal predicate over
     * TERM — unset, set-but-empty, or literally `dumb`. termenv treats all
     * three as dumb-equivalent; the old two sites each eyeballed
     * `=== 'dumb'`, so a `TERM=''` session skipped both the colour route and
     * the MODE posture and shipped DEC-private bytes into an environment
     * that never advertised them. Both the colour ladder above and
     * {@see InteractiveRuntime::programOptions()} now consume this.
     */
    public static function termIsDumb(): bool
    {
        $term = self::env('TERM');

        return $term === null || $term === '' || $term === 'dumb';
    }

    /**
     * Resolve the capability and pin it as the process-ambient profile
     * for every `Style::new()` created afterwards. Returns the resolved
     * cap so callers (tests) can assert it.
     */
    public static function install(?InputInterface $input = null): ColorProfile
    {
        $cap = self::cap($input);
        Style::setDefaultProfile($cap);

        return $cap;
    }

    /**
     * Clamp a requested profile to the ambient capability: styles that
     * pin their own (higher) tier — e.g. a parsed 0-15 index claiming
     * `Ansi` — must not re-widen colour past a gate that says `Ascii`.
     */
    public static function clamp(ColorProfile $requested, ?ColorProfile $ambient = null): ColorProfile
    {
        $ambient ??= Style::defaultProfile() ?? ColorProfile::TrueColor;

        return $ambient->value < $requested->value ? $ambient : $requested;
    }

    /** Restore the unrestricted ambient (test isolation helper). */
    public static function resetAmbient(): void
    {
        Style::setDefaultProfile(null);
    }

    private static function env(string $name): ?string
    {
        $v = getenv($name);

        return $v === false ? null : $v;
    }

    /** Same truthiness shape sugar-core's ColorProfile detection uses. */
    private static function truthy(?string $v): bool
    {
        return $v !== null && $v !== '' && $v !== '0' && strtolower($v) !== 'false';
    }

    private static function stdoutIsTerminal(): bool
    {
        return defined('STDOUT') && is_resource(STDOUT) && stream_isatty(STDOUT);
    }
}
