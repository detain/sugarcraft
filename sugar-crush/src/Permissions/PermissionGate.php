<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\ToolCall;

/**
 * PermissionGate evaluates every ToolCall against the active PermissionMode
 * and returns a PermissionDecision (Allow / Deny / Ask).
 *
 * PermissionRule matching is glob-style: Bash(composer update *), Read(./.env), mcp__git__*
 * A rule matches when the ToolCall name matches the pattern portion.
 *
 * Four modes are implemented here (P2B.S2 + P2B.S3):
 * - Default:     reads silently; writes/networking always Ask
 * - AcceptEdits: scoped filesystem writes auto-Allow; everything else Ask
 * - Plan:        all writes Deny; reads Allow
 * - Auto:        everything runs gated by SafetyClassifier; 3-strike / 20-total circuit breaker
 *
 * The `rm -rf /` / `rm -rf ~` circuit breaker (R3) is evaluated unconditionally in
 * `evaluate()`, before rules and before mode dispatch — no rule and no mode can
 * override it.
 *
 * @see PermissionMode for the full set of modes
 */
final class PermissionGate
{
    /**
     * Filesystem primitive commands that AcceptEdits may auto-approve, via Bash,
     * when scoped to the working directory. Real tool calls route these through
     * Bash(command: "mkdir ..."); there is no dedicated "mkdir" tool at runtime.
     */
    private const SCOPED_WRITE_COMMANDS = ['mkdir', 'touch', 'mv', 'cp', 'rm', 'rmdir'];

    /**
     * Circuit-breaker thresholds for Auto mode.
     */
    private const STRIKE_THRESHOLD = 3;
    private const TOTAL_BLOCK_THRESHOLD = 20;

    // -------------------------------------------------------------------------
    // Auto-mode circuit breaker state
    // -------------------------------------------------------------------------
    private int $consecutiveBlocks = 0;
    private int $totalBlocks = 0;
    private ?string $lastBlockedCategory = null;

    public function __construct(
        private readonly PermissionMode $mode,
        /** @var PermissionRule[] */
        private readonly array $rules = [],
        private readonly ?SafetyClassifier $classifier = null,
    ) {}

    /**
     * Returns the permission mode this gate was configured with.
     */
    public function mode(): PermissionMode
    {
        return $this->mode;
    }

    /**
     * Evaluate a tool call and return the permission decision for the current mode.
     */
    public function evaluate(ToolCall $call): PermissionDecision
    {
        // 0. Circuit breaker: `rm -rf /` / `rm -rf ~` is refused unconditionally,
        // in every mode, before rules are considered — no Allow rule and no mode
        // (including BypassPermissions) can talk this gate into a self-destruct.
        if ($this->isRmRfRootOrHome($call)) {
            return PermissionDecision::Deny;
        }

        // 1. Check explicit rules first (highest priority)
        $ruleDecision = $this->evaluateRules($call);
        if ($ruleDecision !== null) {
            return $ruleDecision;
        }

        // 2. Mode-specific logic
        return match ($this->mode) {
            PermissionMode::Default => $this->evaluateDefault($call),
            PermissionMode::AcceptEdits => $this->evaluateAcceptEdits($call),
            PermissionMode::Plan => $this->evaluatePlan($call),
            PermissionMode::Auto => $this->evaluateAuto($call),
            // P2B.S4: DontAsk and BypassPermissions have dedicated evaluators
            PermissionMode::DontAsk => $this->evaluateDontAsk($call),
            PermissionMode::BypassPermissions => $this->evaluateBypassPermissions($call),
        };
    }

    /**
     * Check rules in order; first match wins.
     */
    private function evaluateRules(ToolCall $call): ?PermissionDecision
    {
        foreach ($this->rules as $rule) {
            if ($this->ruleMatches($rule, $call)) {
                return $this->actionToDecision($rule->action);
            }
        }
        return null;
    }

    /**
     * Glob-match a rule pattern against a tool call name.
     * Supports: Bash* (prefix match), Bash (exact match).
     */
    private function ruleMatches(PermissionRule $rule, ToolCall $call): bool
    {
        $pattern = $rule->pattern;

        // Glob wildcard: match tool name by prefix
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);
            return str_starts_with($call->name, $prefix);
        }

        return $call->name === $pattern;
    }

    private function actionToDecision(PermissionAction $action): PermissionDecision
    {
        return match ($action) {
            PermissionAction::Allow => PermissionDecision::Allow,
            PermissionAction::Deny => PermissionDecision::Deny,
            PermissionAction::Ask => PermissionDecision::Ask,
        };
    }

    // -------------------------------------------------------------------------
    // Mode evaluators
    // -------------------------------------------------------------------------

    /**
     * Default: reads are silent, everything else prompts.
     */
    private function evaluateDefault(ToolCall $call): PermissionDecision
    {
        if ($this->isReadOnlyTool($call)) {
            return PermissionDecision::Allow;
        }
        return PermissionDecision::Ask;
    }

    /**
     * AcceptEdits: scoped filesystem writes auto-approve; protected paths still prompt.
     */
    private function evaluateAcceptEdits(ToolCall $call): PermissionDecision
    {
        // Reads always allow in AcceptEdits
        if ($this->isReadOnlyTool($call)) {
            return PermissionDecision::Allow;
        }

        // Scoped filesystem writes (mkdir, touch, mv, cp, rm, rmdir) auto-allow
        if ($this->isScopedWriteTool($call)) {
            return PermissionDecision::Allow;
        }

        // Everything else (network, shell commands, non-scoped writes) asks
        return PermissionDecision::Ask;
    }

    /**
     * Auto: everything runs gated by SafetyClassifier; circuit breaker triggers Ask after
     * 3 consecutive blocks of the same category OR 20 total blocks in the session.
     *
     * @see SafetyClassifier for the 13 dangerous-action categories.
     */
    private function evaluateAuto(ToolCall $call): PermissionDecision
    {
        // SafetyClassifier is the gatekeeper for Auto mode. Fail CLOSED when it's
        // missing — a misconfigured gate must never silently become "allow everything";
        // that would turn a config bug into a security hole. Ask instead of Allow.
        if ($this->classifier === null) {
            return PermissionDecision::Ask;
        }

        $category = $this->classifier->classify($call);

        // Action is safe — reset counters and allow
        if ($category === null) {
            $this->consecutiveBlocks = 0;
            $this->lastBlockedCategory = null;
            return PermissionDecision::Allow;
        }

        // Dangerous action blocked — update circuit breaker state
        if ($category === $this->lastBlockedCategory) {
            ++$this->consecutiveBlocks;
        } else {
            $this->consecutiveBlocks = 1;
            $this->lastBlockedCategory = $category;
        }
        ++$this->totalBlocks;

        // Circuit breaker thresholds
        if ($this->consecutiveBlocks >= self::STRIKE_THRESHOLD) {
            return PermissionDecision::Ask;
        }
        if ($this->totalBlocks >= self::TOTAL_BLOCK_THRESHOLD) {
            return PermissionDecision::Ask;
        }

        return PermissionDecision::Deny;
    }

    /**
     * Plan: reads/shell exploration freely, but no edits land until approved.
     */
    private function evaluatePlan(ToolCall $call): PermissionDecision
    {
        // Reads are always allowed in Plan mode
        if ($this->isReadOnlyTool($call)) {
            return PermissionDecision::Allow;
        }

        // Bash commands are allowed for exploration (e.g. git log, find, grep)
        // but denied when they redirect output (writing to files)
        if ($call->name === 'Bash') {
            if ($this->isBashWriteCommand($call)) {
                return PermissionDecision::Deny;
            }
            return PermissionDecision::Allow;
        }

        // All other writes are denied in Plan mode — nothing edits until the plan is approved
        if ($this->isWriteTool($call)) {
            return PermissionDecision::Deny;
        }

        // Default: ask
        return PermissionDecision::Ask;
    }

    /**
     * DontAsk: auto-denies anything not pre-approved. Read-only tools (Read/Grep/Glob/WebFetch)
     * are implicitly allowed without an explicit rule. Hook-approved calls would also be allowed
     * via the hook system, but in practice: no explicit Allow rule + non-read-only tool → Deny.
     *
     * Explicit rules always take priority — if a rule matches, its action wins.
     * (The rules check happens before this method is called in evaluate().)
     */
    private function evaluateDontAsk(ToolCall $call): PermissionDecision
    {
        // Read-only tools are implicitly allowed in DontAsk mode
        if ($this->isReadOnlyTool($call)) {
            return PermissionDecision::Allow;
        }

        // Everything else (not read-only and no explicit rule) is denied
        return PermissionDecision::Deny;
    }

    /**
     * BypassPermissions: allows everything EXCEPT explicit Deny rules. The `rm -rf /`
     * / `rm -rf ~` circuit breaker no longer lives here — it's evaluated unconditionally
     * in evaluate() before this method (or any rule) ever runs.
     *
     * Explicit rules always take priority — if a rule matches, its action wins.
     * (The rules check happens before this method is called in evaluate().)
     */
    private function evaluateBypassPermissions(ToolCall $call): PermissionDecision
    {
        // Everything else is allowed in BypassPermissions mode
        return PermissionDecision::Allow;
    }

    /**
     * Detect the `rm -rf /` or `rm -rf ~` circuit-breaker pattern (R3).
     *
     * Deliberately tolerant of evasion via flag reordering (`-fr`), flag splitting
     * (`-r -f`), long-form flags (`--recursive --force`), `--no-preserve-root`
     * riding along, and the target being wrapped in matching quotes (`"/"`, `'~'`)
     * — a quoted path is a routine shell habit, not an unusual evasion, and the
     * literal token comparison must see through it. Case-insensitive; handles
     * prefixes like `sudo`. Command chains (`foo && rm -rf /`) are checked
     * segment-by-segment.
     *
     * Matches: rm -rf /, rm -fr /, rm -r -f /, rm --recursive --force /,
     * rm -rf --no-preserve-root /, rm -rf "/", rm -rf '/', rm -rf ~,
     * sudo rm -rf /, SUDO RM -RF ~
     */
    private function isRmRfRootOrHome(ToolCall $call): bool
    {
        // Only applies to Bash tool calls
        if ($call->name !== 'Bash') {
            return false;
        }

        $args = $call->arguments;
        if (!isset($args['command']) || !is_string($args['command'])) {
            return false;
        }

        foreach (preg_split('/[;&|]+/', $args['command']) as $segment) {
            if ($this->segmentIsRmRfRootOrHome($segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tokenize a single shell command segment and ask: is this an `rm` invocation
     * that combines recursive + force flags (in any spelling/order/split) against
     * a `/` or `~` target?
     */
    private function segmentIsRmRfRootOrHome(string $segment): bool
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/', trim($segment)) ?: [],
            static fn (string $t): bool => $t !== '',
        ));
        if ($tokens === []) {
            return false;
        }

        $i = 0;
        $count = count($tokens);

        // Skip leading prefixes like `sudo` (case-insensitive).
        while ($i < $count && strtolower($tokens[$i]) === 'sudo') {
            ++$i;
        }

        if ($i >= $count || strtolower($tokens[$i]) !== 'rm') {
            return false;
        }
        ++$i;

        $recursive = false;
        $force = false;
        $target = null;

        for (; $i < $count; ++$i) {
            $token = $tokens[$i];
            $lower = strtolower($token);

            if ($lower === '--recursive') {
                $recursive = true;
                continue;
            }
            if ($lower === '--force') {
                $force = true;
                continue;
            }
            if ($lower === '--no-preserve-root' || $lower === '--') {
                // Modifier flags that don't affect the recursive/force determination.
                continue;
            }
            if (str_starts_with($token, '--')) {
                // Unrecognized long flag — ignore, don't treat as the target.
                continue;
            }
            if ($token !== '-' && str_starts_with($token, '-')) {
                // Short flag cluster, e.g. -rf, -fr, -r, -f, -Rf (case-insensitive).
                $flags = strtolower(substr($token, 1));
                if (str_contains($flags, 'r')) {
                    $recursive = true;
                }
                if (str_contains($flags, 'f')) {
                    $force = true;
                }
                continue;
            }

            // First non-flag token is the target path.
            $target = $token;
            break;
        }

        if (!$recursive || !$force || $target === null) {
            return false;
        }

        $target = $this->stripMatchingQuotes($target);

        return $target === '/' || $target === '~';
    }

    /**
     * Strip a single pair of matching surrounding quotes (`"/"` -> `/`, `'~'` -> `~`)
     * so a quoted target can't evade the literal token comparison — a quoted path is
     * a routine shell habit, not an unusual evasion.
     */
    private function stripMatchingQuotes(string $token): string
    {
        $length = strlen($token);
        if ($length >= 2) {
            $first = $token[0];
            $last = $token[$length - 1];
            if (($first === '"' || $first === "'") && $first === $last) {
                return substr($token, 1, -1);
            }
        }

        return $token;
    }

    // -------------------------------------------------------------------------
    // Tool classifiers
    // -------------------------------------------------------------------------

    /**
     * Read-only built-in tools (@see src/Tools/BuiltIn/): Read, Grep, Glob, WebFetch
     * fetch/inspect without mutating local state. `Find` was never a real tool name.
     */
    private function isReadOnlyTool(ToolCall $call): bool
    {
        return in_array($call->name, ['Read', 'Grep', 'Glob', 'WebFetch'], true);
    }

    /**
     * Write-capable tools: `Edit`/`Write` mutate files directly, `Bash` can do
     * anything a shell can, and MCP tools follow the `mcp__<server>__<tool>`
     * naming convention (@see PermissionRule) — their capability is
     * server-defined and unknowable here, so they're treated conservatively as
     * writes. `McpTool` was never a real tool name.
     *
     * `Write` is named even though nothing dispatched under that name for most
     * of this lib's life, because the cost of the two mistakes is not
     * symmetric: a name listed here that no tool ever uses costs one dead
     * `in_array` entry, while a real write tool missing from the list falls
     * through to Ask in Plan mode instead of the Deny that mode promises.
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook} has always
     * matched on `^(Bash|Edit|Write|Read)$` for the same reason.
     */
    private function isWriteTool(ToolCall $call): bool
    {
        if (in_array($call->name, ['Bash', 'Edit', 'Write'], true)) {
            return true;
        }

        return str_starts_with($call->name, 'mcp__');
    }

    /**
     * Detect if a Bash command writes to files via shell redirection or tee.
     */
    private function isBashWriteCommand(ToolCall $call): bool
    {
        $args = $call->arguments;

        if (!isset($args['command']) || !is_string($args['command'])) {
            return false;
        }

        $cmd = $args['command'];

        // File redirection operators: > >> | tee
        return (bool) preg_match('/\s+>\s+/', $cmd)
            || (bool) preg_match('/\s+>>\s+/', $cmd)
            || (bool) preg_match('/\|\s*tee(\s+|$)/', $cmd);
    }

    /**
     * AcceptEdits allows safe filesystem primitives (mkdir, touch, mv, cp, rm, rmdir)
     * scoped to the working directory. Real tool calls route these through
     * Bash(command: "mkdir ..."), never through a dedicated tool named "mkdir".
     *
     * Every non-flag argument must be a relative path — e.g. `mv /etc/passwd ./x`
     * is NOT scoped, even though the destination is relative, because the source
     * reaches outside the working directory.
     */
    private function isScopedWriteTool(ToolCall $call): bool
    {
        if ($call->name !== 'Bash') {
            return false;
        }

        $args = $call->arguments;
        if (!isset($args['command']) || !is_string($args['command'])) {
            return false;
        }

        $tokens = array_values(array_filter(
            preg_split('/\s+/', trim($args['command'])) ?: [],
            static fn (string $t): bool => $t !== '',
        ));
        if ($tokens === []) {
            return false;
        }

        $command = strtolower(array_shift($tokens));
        if (!in_array($command, self::SCOPED_WRITE_COMMANDS, true)) {
            return false;
        }

        // Every remaining non-flag token is a path argument; all must be relative.
        $paths = array_filter($tokens, static fn (string $t): bool => !str_starts_with($t, '-'));
        if ($paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            if ($this->isAbsolutePath($path)) {
                return false;
            }
        }

        return true;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '~');
    }
}
