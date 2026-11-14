<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Core\Util\ColorProfile;

/**
 * Per-writer rendering context. Mirrors lipgloss's `Renderer` —
 * a small value object that bundles a {@see ColorProfile} and a
 * "dark background" flag so callers can branch on those without
 * threading them through every {@see Style} construction.
 *
 * Lipgloss exposes `lipgloss.NewRenderer(out)` to associate a
 * rendering context with an output writer. PHP's stream model is
 * coarser, so this implementation is a pure value object — no
 * embedded writer. Pair with {@see Output::fprint()} when you need
 * to write to a specific stream.
 *
 * ```php
 * $r = Renderer::new()
 *     ->withColorProfile(ColorProfile::Ansi256)
 *     ->withHasDarkBackground(true);
 *
 * // Hand a Style the renderer's profile in one call.
 * $bold = $r->newStyle()->bold()->render('hi');
 *
 * // Branch a colour pick on dark background:
 * $col  = ($r->lightDark())($lightCol, $darkCol);
 * ```
 */
final class Renderer
{
    /**
     * Cap on {@see self::$environmentMemo} entries: a churning environment
     * (test suites that loop putenv) must not grow the memo forever. The
     * detect() answer is deterministic per fingerprint, so eviction only
     * ever costs a recompute.
     */
    private const ENVIRONMENT_MEMO_CAP = 32;

    /**
     * Per-process memo of {@see fromEnvironment()} detections keyed by the
     * ambient environment fingerprint (finding 063: every construction re-ran
     * the detection ladder — twelve getenv() reads plus the chain walk — for
     * an answer that cannot change while the environment behind it does not).
     *
     * Keyed rather than a single `??=` slot: hosts and tests legitimately flip
     * environment variables mid-process, and a fingerprint-keyed memo simply
     * recomputes for the new environment while reusing the identical immutable
     * Renderer for a repeated one. The key is the FULL merged environment
     * rather than a copy of core's private variable roster: the roster is a
     * core detail, and if core ever widened it a 12-var key would serve stale
     * answers, while a superset key only over-invalidates (a recompute is the
     * work every call already paid today). Same idiom as
     * {@see \SugarCraft\Style\Layout\SolverFactory} and {@see Theme::$adaptiveMemo},
     * separate statics.
     *
     * @var array<string, self>
     */
    private static array $environmentMemo = [];

    private function __construct(
        public readonly ColorProfile $colorProfile,
        public readonly bool $hasDarkBackground,
    ) {}

    /**
     * Default renderer: TrueColor profile, dark background.
     * Convenience for the common case (most modern terminals).
     */
    public static function new(): self
    {
        return new self(ColorProfile::TrueColor, true);
    }

    /**
     * Auto-detect the color profile from the environment via
     * {@see ColorProfile::detect()}, memoised per process and per distinct
     * environment fingerprint ({@see self::$environmentMemo}). The
     * dark-background flag stays at its default (true) since that's not
     * detectable without an OSC 11 round trip — toggle it explicitly via
     * {@see withHasDarkBackground()} once the program has the answer.
     */
    public static function fromEnvironment(): self
    {
        $key = self::environmentFingerprint();
        if (isset(self::$environmentMemo[$key])) {
            return self::$environmentMemo[$key];
        }

        if (count(self::$environmentMemo) >= self::ENVIRONMENT_MEMO_CAP) {
            self::$environmentMemo = [];
        }

        return self::$environmentMemo[$key] = new self(ColorProfile::detect(), true);
    }

    /**
     * Drop every memoised environment detection. Same seam shape as
     * {@see \SugarCraft\Style\Layout\SolverFactory::resetEnvironmentResolutionForTesting()},
     * separate statics — tests that flip colour variables call this to
     * start from a cold memo.
     */
    public static function resetEnvironmentMemoForTesting(): void
    {
        self::$environmentMemo = [];
    }

    /**
     * Fingerprint of the ambient environment as {@see ColorProfile::detect()}
     * would read it: the merged `$_ENV` + `getenv()` map that sugar-core's
     * roster gate and sugar-colorprofile's chain both resolve against,
     * key-sorted so the memo never splits on map ordering.
     */
    private static function environmentFingerprint(): string
    {
        /** @var array<string, string> $env */
        $env = array_merge($_ENV, getenv() ?: []);
        ksort($env);

        return md5(serialize($env));
    }

    /** Override the color profile (downsamples colours when rendering). */
    public function withColorProfile(ColorProfile $p): self
    {
        return new self($p, $this->hasDarkBackground);
    }

    /**
     * Override the dark-background flag. Bubble Tea programs set this
     * from a `BackgroundColorMsg::isDark()` reply on startup; for
     * non-interactive output, leave at the default.
     */
    public function withHasDarkBackground(bool $isDark): self
    {
        return new self($this->colorProfile, $isDark);
    }

    /**
     * Create a fresh {@see Style} pre-configured with this renderer's
     * color profile. Equivalent to `Style::new()->colorProfile($p)`.
     */
    public function newStyle(): Style
    {
        return Style::new()->colorProfile($this->colorProfile);
    }

    /**
     * Bind this renderer's `hasDarkBackground` to a {@see LightDark}
     * picker. Equivalent to `LightDark::picker($r->hasDarkBackground)`.
     *
     * @return \Closure(\SugarCraft\Core\Util\Color, \SugarCraft\Core\Util\Color): \SugarCraft\Core\Util\Color
     */
    public function lightDark(): \Closure
    {
        return LightDark::picker($this->hasDarkBackground);
    }

    /**
     * Resolve an {@see AdaptiveColor} with this renderer's dark-bg
     * flag. Mirrors lipgloss `Renderer.HasDarkBackground` + adaptive
     * pick.
     */
    public function resolveAdaptive(AdaptiveColor $color): \SugarCraft\Core\Util\Color
    {
        return $color->pick($this->hasDarkBackground);
    }
}
