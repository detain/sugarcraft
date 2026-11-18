<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Terminal color capability tiers, matching charmbracelet/colorprofile.
 *
 * @deprecated since CP-22 — `SugarCraft\ColorProfile\ColorProfile` is the
 *             single vocabulary and detector. This enum stays as the
 *             five-rung projection (`ColorProfile::toCore()` / `fromCore()`)
 *             and `detect()` delegates to the chain when it is installed.
 *             Without the chain there is no colour: see {@see fromEnvironment()}.
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
     * With sugar-colorprofile installed this is its DetectionChain, and the
     * decision order is documented on that class. Without it there is no
     * local ladder: a non-terminal answers `NoTty`, and a terminal answers
     * `Ascii` (no colour, decoration kept). See {@see fromEnvironment()}.
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
        // No stream to ask means "assume a terminal": the TTY gate is skipped,
        // matching the documented $stdout = null contract.
        $isTty = $stdout === null || !is_resource($stdout) || stream_isatty($stdout);

        return self::fromEnvironment($env, $isTty);
    }

    /**
     * The one colour-negotiation entry point: the answer for an explicit
     * environment and a TTY fact, with no ambient reads.
     *
     * Pure. It reads neither `getenv()` nor any stream and runs no probe, so
     * the same arguments always give the same tier. `[]` is hermetic: it reads
     * no ambient environment. {@see detect()} is this method fed the process
     * environment and the stdout stream.
     *
     * `$isTty` replaces the stream check. A non-terminal answers `NoTty` unless
     * a force variable is set, because a pipe gets no escape bytes at all.
     *
     * Without sugar-colorprofile there is no environment negotiation at all,
     * so a terminal answers `Ascii` whatever `$env` says (declared behaviour
     * change, P1-10a: the local fallback ladder was deleted).
     *
     * `$override` is an explicit caller decision, such as a `--color` flag.
     * It outranks the environment:
     *  - `null`  negotiate from `$env` as {@see detect()} does.
     *  - `true`  colour is forced on: `TrueColor`, whatever the TTY and env say.
     *  - `false` colour is forced off: `Ascii` on a terminal (decoration kept),
     *            `NoTty` otherwise (no escape bytes).
     *
     * @param array<string,string> $env  the complete environment; `[]` is hermetic
     * @param bool                 $isTty whether the destination is a terminal
     * @param bool|null            $override null negotiates; true or false forces
     */
    public static function fromEnvironment(array $env, bool $isTty, ?bool $override = null): self
    {
        if ($override === true) {
            return self::TrueColor;
        }
        if ($override === false) {
            return $isTty ? self::Ascii : self::NoTty;
        }

        $force = self::truthy($env['CLICOLOR_FORCE'] ?? '')
              || self::truthy($env['FORCE_COLOR']    ?? '');

        if (!$force && !$isTty) {
            return self::NoTty;
        }

        // CP-22: when sugar-colorprofile is installed it owns the env ladder
        // (one SSOT for the fleet). sugar-core cannot require it (colorprofile
        // requires sugar-core, a cycle), hence the class_exists guard and the
        // `suggest` entry. The TTY gate above stays local: it is a stream
        // question, not an environment one. An empty array must not reach the
        // chain as-is, because the chain reads the ambient env for [].
        if (class_exists(\SugarCraft\ColorProfile\DetectionChain::class)) {
            return \SugarCraft\ColorProfile\DetectionChain::detect($env === [] ? ['TERM' => ''] : $env)
                ->toColorProfile()
                ->toCore();
        }

        // No chain and no local ladder: colour is off. The TTY gate above has
        // already answered NoTty for a non-terminal without a force variable.
        return self::Ascii;
    }

    /**
     * {@see detect()} plus the dumb-terminal full-screen veto.
     *
     * CP-22 moved the env ladder into sugar-colorprofile's chain, which
     * answers `TERM=dumb` as `Ascii` (a colour refusal) where the legacy
     * ladder answered `NoTty`. Apps that gate the alt screen, mouse and mode
     * switches on `NoTty` ("this device cannot honor addressing") must ask
     * THIS method: it maps an unforced dumb terminal back to `NoTty` whichever
     * ladder answered, so the veto does not depend on which siblings happen
     * to be installed. With the chain installed, a force variable lifts the
     * answer above `Ascii` and is left alone. Without it nothing lifts
     * `Ascii`, so a forced dumb terminal also answers `NoTty` (declared
     * behaviour change, P1-10a).
     *
     * @param array<string,string>|null $env
     * @param resource|null             $stdout
     */
    public static function detectTerminal(?array $env = null, $stdout = null): self
    {
        $env ??= self::defaultEnv();
        $profile = self::detect($env, $stdout);
        if ($profile === self::Ascii && strtolower($env['TERM'] ?? '') === 'dumb') {
            return self::NoTty;
        }
        return $profile;
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
            'NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR',
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
