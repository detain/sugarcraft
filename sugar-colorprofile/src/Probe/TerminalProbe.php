<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Probe;

use SugarCraft\ColorProfile\DetectionChain;
use SugarCraft\ColorProfile\Env;

/**
 * Terminal capability probe — the full capability report (color depth plus
 * Sixel/Kitty/iTerm2/Hyperlinks/keyboard flags).
 *
 * The suppression and color-depth answer delegate their entire core ladder to
 * {@see DetectionChain} (its canonical steps 1-13: CLICOLOR_FORCE,
 * FORCE_COLOR, NO_COLOR, CLICOLOR=0, TERM=dumb, COLORTERM, TERM_PROGRAM,
 * WT_SESSION, GOOGLE_CLOUD_SHELL, TMUX/STY, TERM depth rungs, default), so a
 * "XTERM-256COLOR" never means 256 colors to Palette but 16 to this class —
 * the old local case-sensitive re-implementations drifted exactly that far.
 * This class then records richer capability detail (Sixel, KittyKeyboard,
 * ITerm2, Hyperlinks) and runs the terminfo phase that the chain's
 * single-level answer does not carry.
 *
 * Pipeline:
 *  1. Environment phase  → chain ladder mapped to capability entries
 *  2. Terminfo phase     → `infocmp -1` name-position Tc/RGB and Sixel caps
 *  3. Environment upgrade table (interactive only — issues no raw OSC query
 *     round-trips; see checkEscapeQueries)
 *  4. Fallbacks          → BasicAscii floor
 *
 * Extension seams (protected on purpose): {@see self::getEnv()},
 * {@see self::isInteractive()}, {@see self::infocmpAvailable()} and
 * {@see self::runCommand()} are the documented injection points consumers'
 * tests override (sugar-image's DetectTerminfoTest fakes the last two). The
 * class is deliberately NOT final for that reason.
 *
 * Additional capability detection (all env/terminfo lookups):
 * - Sixel: terminfo (`infocmp -1` Sixel capability name)
 * - KittyKeyboard: TERM=xterm-kitty (case-insensitive)
 * - ITerm2: TERM_PROGRAM=iTerm.app (Apple_Terminal excluded — no OSC 1337)
 * - Hyperlinks: TERM_PROGRAM=Hyper table
 * - TrueColor upgrade: TERM_PROGRAM=WezTerm|vscode|Ghostty table
 * - BracketedPaste/FocusEvents: RESERVED — reporting them requires the
 *   raw-mode query round-trip this library leaves to the TUI layer
 *
 * @see https://github.com/charmbracelet/colorprofile
 * @see https://sw.kovidgoyal.net/kitty/graphics-protocol/
 */
class TerminalProbe
{
    /** @var array<string, string|null> */
    private array $env = [];

    private bool $interactive = false;

    /** True when the env phase answered "ASCII floor" — gates phases 2-3. */
    private bool $asciiAnswered = false;

    /**
     * Create a new probe with optional environment overrides.
     *
     * Resolution follows the single {@see Env} rule: a NON-EMPTY $env map is
     * a complete hermetic replacement (ambient variables do not leak in);
     * [] reads the ambient snapshot.
     *
     * @param array<string, string|null> $env  Environment map for testing
     * @param bool                      $interactive  Whether the environment
     *                                                upgrade table (Phase 3)
     *                                                may run; ANDed with
     *                                                isInteractive() here and
     *                                                honored by runProbe() unless
     *                                                that call overrides it
     */
    public function __construct(array $env = [], bool $interactive = true)
    {
        $this->env = Env::resolve($env);
        $this->interactive = $interactive && $this->isInteractive();
    }

    /**
     * Run the full probe pipeline and return a report.
     */
    public static function run(): ProbeReport
    {
        $probe = new self();

        return $probe->runProbe();
    }

    /**
     * Run the full probe pipeline with optional environment overrides.
     *
     * $env overlays are applied for THIS run only — the instance state is
     * restored afterwards, so a probe reused across runs cannot have run N's
     * suppression leak into run N+1 (the old code merged overrides into
     * $this->env permanently).
     *
     * @param array<string, string|null> $env          Environment overrides
     * @param bool|null                  $interactive  null (default) keeps the
     *                                                 constructor's decision;
     *                                                 true re-evaluates
     *                                                 isInteractive(); false
     *                                                 forces Phase 3 off
     */
    public function runProbe(array $env = [], ?bool $interactive = null): ProbeReport
    {
        $baseEnv = $this->env;
        if ($env !== []) {
            $this->env = array_merge($this->env, $env);
        }
        $this->interactive = match ($interactive) {
            false => false,
            true => $this->isInteractive(),
            null => $this->interactive,
        };

        try {
            $caps = $this->checkEnvVars();

            // Gate: NO_COLOR / CLICOLOR=0 / TERM=dumb from the env phase
            // terminate the whole capability ladder, per the de-facto NO_COLOR
            // spec ("…disable colored output…entirely"). Running terminfo or
            // escape queries after a suppression verdict re-added TrueColor/Sixel
            // and silently defeated the user's explicit "no color" — suppression
            // is a decision, not one input among many. A FORCE_COLOR falsy
            // (ascii floor) verdict gates the same way: its whole point is
            // "no color beyond decoration".
            $gated = $this->asciiAnswered
                || isset($caps[Capability::key(Capability::NoColor)]);

            // Phase 2: terminfo check if infocmp is available
            if (!$gated) {
                $caps = $this->checkTerminfo($caps);
            }

            // Phase 3: Environment upgrade table (if interactive)
            if (!$gated && $this->interactive) {
                $caps = $this->checkEscapeQueries($caps);
            }

            // Phase 4: Apply fallbacks
            $caps = $this->applyFallbacks($caps);

            return new ProbeReport($caps);
        } finally {
            $this->env = $baseEnv;
        }
    }

    /**
     * Step 1: Environment phase — canonical chain ladder mapped onto
     * capability entries, plus the detail this report shape carries.
     *
     * @return array<string, string>
     */
    private function checkEnvVars(): array
    {
        $caps = [];
        $this->asciiAnswered = false;

        // CLICOLOR_FORCE=1 → TrueColor (overrides everything, including NO_COLOR)
        if ($this->getEnv('CLICOLOR_FORCE') === '1') {
            $caps[Capability::key(Capability::TrueColor)] = 'env:CLICOLOR_FORCE';
            return $caps;
        }

        $chain = DetectionChain::detect($this->env);

        // Suppressions (NO_COLOR, CLICOLOR=0, TERM=dumb) terminate the ladder.
        if (!$chain->allowsColor()) {
            $source = match (true) {
                str_contains($chain->source(), 'NO_COLOR') => 'env:NO_COLOR',
                str_contains($chain->source(), 'CLICOLOR=0') => 'env:CLICOLOR=0',
                str_contains($chain->source(), 'TERM=dumb') => 'env:TERM=dumb',
                default => $chain->source(),
            };
            $caps[Capability::key(Capability::NoColor)] = $source;
            return $caps;
        }

        // FORCE_COLOR falsy spelling → ascii floor, no color capability.
        if ($chain->level() === DetectionChain::LEVEL_ASCII) {
            $caps[Capability::key(Capability::BasicAscii)] = $chain->source();
            $this->asciiAnswered = true;
            return $caps;
        }

        // Color depth taken from the chain (case-insensitive like every other
        // surface): truecolor → TrueColor, ansi256 → Color256, ansi → Color16.
        match ($chain->level()) {
            DetectionChain::LEVEL_TRUECOLOR => $caps[Capability::key(Capability::TrueColor)] = $chain->source(),
            DetectionChain::LEVEL_ANSI256 => $caps[Capability::key(Capability::Color256)] = $chain->source(),
            default => $caps[Capability::key(Capability::Color16)] = $chain->source(),
        };

        // xterm-kitty also implies Kitty keyboard support (env detail, kept
        // here so both interactive and non-interactive shapes report it).
        if (strtolower($chain->term()) === 'xterm-kitty') {
            $caps[Capability::key(Capability::KittyKeyboard)] = 'env:TERM=xterm-kitty';
        }

        return $caps;
    }

    /**
     * Step 2: Check terminfo for Tc/RGB capabilities and Sixel support.
     *
     * @param array<string, string> $caps
     * @return array<string, string>
     */
    private function checkTerminfo(array $caps): array
    {
        $term = $this->getEnv('TERM');
        if ($term === null || $term === '' || strtolower($term) === 'dumb') {
            return $caps;
        }

        if (!$this->infocmpAvailable()) {
            return $caps;
        }

        // Exec gate (shared predicate with Probe/AsyncProbe): a TERM infocmp
        // would parse as its own option never reaches the binary.
        if (!InfocmpBinary::isSafeTermName($term)) {
            return $caps;
        }

        // Exec the SAME absolute path the availability gate checked — a bare
        // relative `infocmp` would re-resolve through PATH at exec time and
        // let a planted PATH entry answer a question the gate said it could not.
        // A null path fails CLOSED here: `?? ''` used to splice an empty
        // binary onto the command line, which left the escaped TERM alone as
        // the COMMAND WORD — and infocmpAvailable() is an overridable seam,
        // so a subclass answering "true" could outlive the roster's verdict.
        $binary = InfocmpBinary::path();
        if ($binary === null) {
            return $caps;
        }

        $output = $this->runCommand($binary . ' -1 ' . escapeshellarg($term) . ' 2>/dev/null');
        if ($output === null || $output === '') {
            return $caps;
        }

        // Tc (true-color) or RGB (direct color string cap) at capability-NAME
        // position. The old /\bTc\b/ whole-output regex also matched the word
        // inside descriptions and other values — shared parser now.
        if (TerminfoParser::hasCapName($output, TerminfoParser::TRUECOLOR_NAMES)) {
            $key = Capability::key(Capability::TrueColor);
            if (!isset($caps[$key])) {
                $caps[$key] = 'terminfo:Tc|RGB';
            }
        }

        // Sixel support at capability-name position (see TerminfoParser for
        // why name-matching replaced the old case-sensitive word regex).
        if (TerminfoParser::hasCapName($output, self::SIXEL_TERMCAPS)) {
            $key = Capability::key(Capability::Sixel);
            $caps[$key] = 'terminfo:sixel';
        }

        return $caps;
    }

    /**
     * Terminfo capability names that advertise Sixel graphics output.
     *
     * ncurses and downstream vendors spell the Sixel capability differently
     * across releases: the boolean `sixel`/`Sixel` (and the abbreviated `sixl`),
     * the numeric-as-boolean `Sixel#1`, the string `Sixel=true`, and the
     * `SixelScreenMode` numeric form shipped by sixel terminals (foot, mlterm,
     * xterm). The legacy `Ss1`/`Ss2` user caps are NOT sixel markers — they
     * initialize X3.64 status lines — so they sit outside the accepted set
     * ({@see TerminfoParser::SIXEL_NAMES}).
     * Names are matched after lowercasing and stripping underscores, so
     * `SixelScreenMode` and `sixel_screen_mode` collapse to one token
     * ({@see TerminfoParser::hasCapName()}).
     *
     * @see terminfo(5) user-defined capabilities
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (Sixel)
     */
    public const SIXEL_TERMCAPS = TerminfoParser::SIXEL_NAMES;

    /**
     * Step 3: Interactive-only capability upgrade from the advertised environment.
     *
     * Despite the historical name this issues no raw OSC query round-trips
     * (DA1, OSC 4;1;?, OSC 8, 2004, 1004): the implementation is a
     * TERM_PROGRAM lookup table. Real query I/O would need raw-mode read-back
     * owned by the TUI layer; until then terminfo (Phase 2) is the
     * authoritative deep probe.
     *
     * @param array<string, string> $caps
     * @return array<string, string>
     */
    private function checkEscapeQueries(array $caps): array
    {
        // TERM_PROGRAM hints for terminal families that advertise a capability
        // they actually implement — table lookup, no query. Apple_Terminal is
        // deliberately absent: Terminal.app does not speak OSC 1337 inline
        // images, and mapping it here made image consumers (sugar-image's
        // Mosaic) pick the iTerm2 protocol on a terminal that renders garbage.
        $termProgram = $this->getEnv('TERM_PROGRAM');
        if ($termProgram !== null) {
            $known = [
                'iTerm.app'       => Capability::ITerm2,
                'Hyper'           => Capability::Hyperlinks,
                'WezTerm'         => Capability::TrueColor,
                'vscode'          => Capability::TrueColor,
                'Ghostty'         => Capability::TrueColor,
            ];
            if (isset($known[$termProgram])) {
                $cap = $known[$termProgram];
                $key = Capability::key($cap);
                if (!isset($caps[$key])) {
                    $caps[$key] = Env::sanitize('env:TERM_PROGRAM=' . $termProgram);
                }
            }
        }

        // BracketedPaste (OSC 2004) and FocusEvents (OSC 1004) are NOT probed
        // here: reporting them requires the raw-mode query round-trip described
        // above, which this library deliberately leaves to the TUI layer.

        return $caps;
    }

    /**
     * Step 4: Apply fallbacks to ensure BasicAscii minimum.
     *
     * @param array<string, string> $caps
     * @return array<string, string>
     */
    private function applyFallbacks(array $caps): array
    {
        // BasicAscii is always available as the floor
        $basicAsciiKey = Capability::key(Capability::BasicAscii);
        if (!isset($caps[$basicAsciiKey])) {
            $caps[$basicAsciiKey] = 'fallback:basic-ascii';
        }

        // If no color capability detected, ensure at least Color16
        // (many terminals default to at least 16 colors) — except when the
        // env phase deliberately answered the ascii floor (FORCE_COLOR falsy
        // = "no color beyond decoration"); smuggling Color16 back in there
        // would override the operator's explicit spelling.
        if ($this->asciiAnswered) {
            return $caps;
        }

        $trueColorKey = Capability::key(Capability::TrueColor);
        $color256Key = Capability::key(Capability::Color256);
        $color16Key = Capability::key(Capability::Color16);
        $noColorKey = Capability::key(Capability::NoColor);

        if (
            !isset($caps[$trueColorKey])
            && !isset($caps[$color256Key])
            && !isset($caps[$color16Key])
            && !isset($caps[$noColorKey])
        ) {
            $caps[$color16Key] = 'fallback:color16-default';
        }

        return $caps;
    }

    // -------------------------------------------------------------------------
    // Environment accessors (protected for testability)
    // -------------------------------------------------------------------------

    /**
     * Get an environment variable value.
     *
     * @return string|null
     */
    protected function getEnv(string $name): ?string
    {
        $value = $this->env[$name] ?? null;

        return $value === false ? null : $value;
    }

    /**
     * Check if the terminal appears to be interactive.
     */
    protected function isInteractive(): bool
    {
        // Check if stdout is a TTY
        if (function_exists('posix_isatty')) {
            if (!@posix_isatty(STDOUT)) {
                return false;
            }
        } elseif (function_exists('stream_isatty')) {
            if (!@stream_isatty(STDOUT)) {
                return false;
            }
        }

        // Check for common non-interactive indicators (case-insensitive:
        // TERM=DUMB is as dumb as TERM=dumb).
        $term = $this->getEnv('TERM');
        if ($term !== null && strtolower($term) === 'dumb') {
            return false;
        }

        return true;
    }

    /**
     * Check if infocmp binary is available.
     *
     * Delegates to the shared sniff ({@see InfocmpBinary::path()});
     * the method stays a protected seam so tests can override the answer.
     */
    protected function infocmpAvailable(): bool
    {
        return InfocmpBinary::path() !== null;
    }

    /**
     * Run a shell command and return the output.
     * Override in tests to inject mock output.
     *
     * The base implementation launches under the shared minimal environment
     * ({@see InfocmpBinary::childEnvironment()}) — the terminfo child must not
     * inherit parent variables that steer its database lookup. An override
     * takes over that responsibility too: the README's trust-boundary
     * paragraph says the minimal environment is part of the contract.
     *
     * @return string|null
     */
    protected function runCommand(string $cmd): ?string
    {
        $handle = @\proc_open(
            $cmd,
            [1 => ['pipe', 'w']],
            $pipes,
            null,
            InfocmpBinary::childEnvironment($this->getEnv('TERM')),
        );

        if (!\is_resource($handle)) {
            return null;
        }

        $output = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \proc_close($handle);

        return $output === false ? null : $output;
    }

    // -------------------------------------------------------------------------
    // TERM pattern matchers (case-insensitive — the chain lowercases too, so
    // these seams and their subclasses answer like every other surface)
    // -------------------------------------------------------------------------

    protected function termIsXterm(string $term): bool
    {
        return str_starts_with(strtolower($term), 'xterm');
    }

    protected function termIsScreen(string $term): bool
    {
        return str_starts_with(strtolower($term), 'screen');
    }

    protected function termIsTmux(string $term): bool
    {
        return str_starts_with(strtolower($term), 'tmux');
    }

    protected function termIs256Color(string $term): bool
    {
        $lower = strtolower($term);

        return str_contains($lower, '-256color')
            || $lower === 'xterm-kitty'
            || $lower === 'xterm-ghostty';
    }
}
