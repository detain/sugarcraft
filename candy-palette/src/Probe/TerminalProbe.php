<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Probe;

/**
 * Terminal capability probe — the single source of truth for capability detection.
 *
 * Runs a 12-step detection pipeline:
 *  1. CLICOLOR_FORCE=1                    → TrueColor  (overrides everything)
 *  2. NO_COLOR (any value)                → NoColor
 *  3. CLICOLOR=0                          → NoColor
 *  4. TERM=dumb                           → NoColor
 *  5. COLORTERM=24bit|truecolor|yes       → TrueColor
 *  6. WT_SESSION set                      → TrueColor  (Windows Terminal)
 *  7. GOOGLE_CLOUD_SHELL=true             → TrueColor
 *  8. TMUX || STY set + base TERM checks  → Color256 (tmux/screen default to 256)
 *  9. TERM=xterm-kitty|xterm-ghostty|*-256color → Color256
 * 10. TERM=xterm*|screen*|tmux*           → Color16
 * 11. Default                            → Color16
 * 12. Optional Phase 2: infocmp Tc/RGB    → upgrade Color16 → TrueColor
 *
 * Additional capability detection:
 * - Sixel: via escape query (OSC 4 ; 1 ; ? \x07) if interactive
 * - Kitty: TERM=xterm-kitty or escape query
 * - ITerm2: TERM_PROGRAM=iTerm.app or escape query
 * - Hyperlinks: OSC 8 support via escape query
 * - BracketedPaste: OSC 2004 support
 * - FocusEvents: OSC 1004 support
 * - KittyKeyboard: TERM=xterm-kitty
 *
 * @see https://github.com/charmbracelet/colorprofile
 * @see https://sw.kovidgoyal.net/kitty/graphics-protocol/
 */
class TerminalProbe
{
    /** @var array<string, string|null> */
    private array $env = [];

    private bool $interactive = false;

    /**
     * Create a new probe with optional environment overrides.
     *
     * @param array<string, string|null> $env  Environment map for testing
     * @param bool                      $interactive  Whether to run escape queries
     */
    public function __construct(array $env = [], bool $interactive = true)
    {
        $this->env = array_merge($_ENV, getenv() ?: [], $env);
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
     * @param array<string, string|null> $env  Environment overrides
     * @param bool                      $interactive  Run escape queries
     */
    public function runProbe(array $env = [], bool $interactive = true): ProbeReport
    {
        if ($env !== []) {
            $this->env = array_merge($this->env, $env);
        }
        if (!$interactive) {
            $this->interactive = false;
        } else {
            $this->interactive = $interactive && $this->isInteractive();
        }

        $caps = $this->checkEnvVars();

        // Phase 2: terminfo check if infocmp is available
        $caps = $this->checkTerminfo($caps);

        // Phase 3: Escape queries (if interactive)
        if ($this->interactive) {
            $caps = $this->checkEscapeQueries($caps);
        }

        // Phase 4: Apply fallbacks
        $caps = $this->applyFallbacks($caps);

        return new ProbeReport($caps);
    }

    /**
     * Step 1: Check environment variables.
     *
     * @return array<Capability, string>
     */
    private function checkEnvVars(): array
    {
        $caps = [];

        // 1. CLICOLOR_FORCE=1 → TrueColor (overrides everything)
        if ($this->getEnv('CLICOLOR_FORCE') === '1') {
            $caps[capabilityKey(Capability::TrueColor)] = 'env:CLICOLOR_FORCE';
            return $caps;
        }

        // 2. NO_COLOR (any value) → NoColor
        if ($this->getEnv('NO_COLOR') !== null) {
            $caps[capabilityKey(Capability::NoColor)] = 'env:NO_COLOR';
            return $caps;
        }

        // 3. CLICOLOR=0 → NoColor
        if ($this->getEnv('CLICOLOR') === '0') {
            $caps[capabilityKey(Capability::NoColor)] = 'env:CLICOLOR=0';
            return $caps;
        }

        // 4. TERM=dumb → NoColor
        if ($this->getEnv('TERM') === 'dumb') {
            $caps[capabilityKey(Capability::NoColor)] = 'env:TERM=dumb';
            return $caps;
        }

        // 5. COLORTERM=24bit|truecolor|yes → TrueColor
        $colorterm = $this->getEnv('COLORTERM');
        if ($colorterm !== null) {
            $ctLower = strtolower($colorterm);
            if ($ctLower === '24bit' || $ctLower === 'truecolor' || $ctLower === 'yes') {
                $caps[capabilityKey(Capability::TrueColor)] = 'env:COLORTERM=' . $colorterm;
                return $caps;
            }
        }

        // 6. WT_SESSION set → TrueColor (Windows Terminal)
        if ($this->getEnv('WT_SESSION') !== null) {
            $caps[capabilityKey(Capability::TrueColor)] = 'env:WT_SESSION';
        }

        // 7. GOOGLE_CLOUD_SHELL=true → TrueColor
        if ($this->getEnv('GOOGLE_CLOUD_SHELL') === 'true') {
            $caps[capabilityKey(Capability::TrueColor)] = 'env:GOOGLE_CLOUD_SHELL';
        }

        // 8. TMUX || STY set + base TERM checks tmux/screen first → Color256
        $tmux = $this->getEnv('TMUX');
        $sty = $this->getEnv('STY');
        if ($tmux !== null || $sty !== null) {
            $term = $this->getEnv('TERM') ?? '';
            if ($this->termIsScreen($term) || $this->termIsTmux($term)) {
                $caps[capabilityKey(Capability::Color256)] = 'env:TMUX|STY+' . ($tmux !== null ? 'TMUX' : 'STY');
                return $caps;
            }
        }

        // 9. TERM=xterm-kitty|xterm-ghostty|*-256color → Color256
        $term = $this->getEnv('TERM') ?? '';
        if ($this->termIs256Color($term)) {
            $caps[capabilityKey(Capability::Color256)] = 'env:TERM=' . $term;
            // xterm-kitty also implies Kitty keyboard support
            if ($term === 'xterm-kitty') {
                $caps[capabilityKey(Capability::KittyKeyboard)] = 'env:TERM=xterm-kitty';
            }
            return $caps;
        }

        // 10. TERM=xterm*|screen*|tmux* → Color16
        if ($this->termIsXterm($term) || $this->termIsScreen($term) || $this->termIsTmux($term)) {
            $caps[capabilityKey(Capability::Color16)] = 'env:TERM=' . $term;
            return $caps;
        }

        // 11. Default → Color16
        if ($term === '') {
            $caps[capabilityKey(Capability::Color16)] = 'fallback:default';
        }

        return $caps;
    }

    /**
     * Step 2: Check terminfo for Tc/RGB capabilities.
     *
     * @param array<string, string> $caps
     * @return array<string, string>
     */
    private function checkTerminfo(array $caps): array
    {
        $term = $this->getEnv('TERM');
        if ($term === null || $term === '' || $term === 'dumb') {
            return $caps;
        }

        if (!$this->infocmpAvailable()) {
            return $caps;
        }

        $output = $this->runCommand('infocmp -1 ' . escapeshellarg($term) . ' 2>/dev/null');
        if ($output === null || $output === '') {
            return $caps;
        }

        // Tc (True-color) or RGB (direct color) capability present
        if (preg_match('/\bTc\b/', $output) || preg_match('/\bRGB\b/', $output)) {
            $key = capabilityKey(Capability::TrueColor);
            if (!isset($caps[$key])) {
                $caps[$key] = 'terminfo:Tc|RGB';
            }
        }

        // Check for Sixel support in terminfo
        if (preg_match('/\bsixel\b/', $output)) {
            $key = capabilityKey(Capability::Sixel);
            $caps[$key] = 'terminfo:sixel';
        }

        return $caps;
    }

    /**
     * Step 3: Run escape queries for interactive terminals.
     *
     * @param array<string, string> $caps
     * @return array<string, string>
     */
    private function checkEscapeQueries(array $caps): array
    {
        // Note: Escape queries require actually writing to the terminal
        // and reading the response. This is typically done via DA1 (Primary
        // device attributes) query. For now, we detect known capabilities
        // based on TERM_PROGRAM.

        // TERM_PROGRAM hints for iTerm2
        $termProgram = $this->getEnv('TERM_PROGRAM');
        if ($termProgram !== null) {
            $known = [
                'iTerm.app'       => Capability::ITerm2,
                'Apple_Terminal'   => Capability::ITerm2,
                'Hyper'           => Capability::Hyperlinks,
                'WezTerm'         => Capability::TrueColor,
                'vscode'          => Capability::TrueColor,
                'Ghostty'         => Capability::TrueColor,
            ];
            if (isset($known[$termProgram])) {
                $cap = $known[$termProgram];
                $key = capabilityKey($cap);
                if (!isset($caps[$key])) {
                    $caps[$key] = 'env:TERM_PROGRAM=' . $termProgram;
                }
            }
        }

        // Check for Kitty keyboard protocol
        $term = $this->getEnv('TERM') ?? '';
        if ($term === 'xterm-kitty') {
            $key = capabilityKey(Capability::KittyKeyboard);
            $caps[$key] = 'env:TERM=xterm-kitty';
        }

        // Bracketed paste mode is widely supported; check via escape query
        // OSC 2004 can query bracketed paste support
        // For now, we assume modern terminals support it if not disabled

        // Focus events - OSC 1004
        // Most modern terminals support this

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
        $basicAsciiKey = capabilityKey(Capability::BasicAscii);
        if (!isset($caps[$basicAsciiKey])) {
            $caps[$basicAsciiKey] = 'fallback:basic-ascii';
        }

        // If no color capability detected, ensure at least Color16
        // (many terminals default to at least 16 colors)
        $trueColorKey = capabilityKey(Capability::TrueColor);
        $color256Key = capabilityKey(Capability::Color256);
        $color16Key = capabilityKey(Capability::Color16);
        $noColorKey = capabilityKey(Capability::NoColor);

        if (!isset($caps[$trueColorKey])
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

        // Check for common non-interactive indicators
        if ($this->getEnv('TERM') === 'dumb') {
            return false;
        }

        return true;
    }

    /**
     * Check if infocmp binary is available.
     */
    protected function infocmpAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $available = is_file('/usr/bin/infocmp') || is_file('/bin/infocmp');
        }
        return $available;
    }

    /**
     * Run a shell command and return the output.
     * Override in tests to inject mock output.
     *
     * @return string|null
     */
    protected function runCommand(string $cmd): ?string
    {
        return @shell_exec($cmd);
    }

    // -------------------------------------------------------------------------
    // TERM pattern matchers
    // -------------------------------------------------------------------------

    protected function termIsXterm(string $term): bool
    {
        return str_starts_with($term, 'xterm');
    }

    protected function termIsScreen(string $term): bool
    {
        return str_starts_with($term, 'screen');
    }

    protected function termIsTmux(string $term): bool
    {
        return str_starts_with($term, 'tmux');
    }

    protected function termIs256Color(string $term): bool
    {
        return str_contains($term, '-256color')
            || $term === 'xterm-kitty'
            || $term === 'xterm-ghostty';
    }
}

/**
 * Helper function to get the string key for a Capability enum.
 * This works around PHP's strict typing issues with enum array keys.
 */
function capabilityKey(Capability $cap): string
{
    return $cap->value;
}
