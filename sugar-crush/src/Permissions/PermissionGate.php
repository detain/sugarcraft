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
 * - Plan:        reads and non-redirecting `Bash` Allow; every other write Deny
 * - Auto:        everything runs gated by SafetyClassifier; 3-strike / 20-total circuit breaker
 *
 * That Plan line used to read "all writes Deny", which {@see evaluatePlan()}
 * has never done and does not claim to: a `Bash` call is allowed for
 * exploration and denied only when {@see isBashWriteCommand()} sees a
 * redirection in its arguments. The summary is corrected rather than the
 * evaluator because exploration under Plan is the deliberate behaviour (and
 * the tested one) — but the difference matters to a caller reasoning about a
 * `Bash` DECLARATION, which carries no arguments to redirect: see
 * {@see refuses()}.
 *
 * The `rm -rf /` / `rm -rf ~` circuit breaker (R3) is evaluated unconditionally in
 * `evaluate()`, before rules and before mode dispatch — no rule and no mode can
 * override it.
 *
 * TWO ENTRY POINTS, and the difference between them is state:
 * - {@see evaluate()} settles a real {@see ToolCall} and, in Auto, RECORDS the
 *   outcome in this instance's circuit-breaker counters.
 * - {@see refuses()} answers a {@see ToolDeclaration} — a name a definition
 *   claims it will use — and touches nothing. Read that method before adding
 *   any other read-only caller.
 *
 * Both go through the one private {@see decide()}, so no mode's policy exists
 * in two places; the Auto arm is the only branch between them.
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
     * Evaluate a REAL tool call and return the permission decision for the
     * current mode.
     *
     * MUTATES in {@see PermissionMode::Auto}: the decision advances (or resets)
     * this instance's circuit-breaker counters, which is how a third
     * consecutive block of one category escalates to `Ask`. Never call this to
     * ask a hypothetical question — a call that did not really happen must not
     * move a counter a real one is judged by. {@see refuses()} is the read-only
     * question, and it takes a type this method will not accept so the two
     * cannot be mixed up at a call site.
     */
    public function evaluate(ToolCall $call): PermissionDecision
    {
        return $this->decide($call, commitAutoStrikes: true);
    }

    /**
     * Would this gate refuse a tool a definition merely DECLARES?
     *
     * The read-only half of this class, for a caller holding a declaration
     * rather than a call: a workflow stage's `tools: [Bash, Write]`, an agent
     * preset's allow-list — untrusted text naming capability the session's own
     * policy may refuse. Answers `true` only for {@see PermissionDecision::Deny};
     * an `Ask` is not a refusal, because settling one needs the blocking
     * permission prompt and a caller that cannot show one must not turn "would
     * have asked" into "no".
     *
     * DOES NOT MUTATE, and that is the whole reason it exists rather than
     * callers using {@see evaluate()} with a name-only {@see ToolCall}: doing
     * that in `Auto` reset the strike counter (see {@see ToolDeclaration} for
     * the measured sequence).
     *
     * Exactly what a declaration can and cannot be refused by, because an
     * over-claimed check is worse than an absent one:
     *
     * - Name-pattern rules DO apply, in every mode: an explicit
     *   `Deny Bash` / `Deny Bash*` / `Deny mcp__git__*` refuses the
     *   declaration. This is the ONLY refusal available under `Auto`.
     * - Argument-sensitive rules (`Bash(rm *)`) cannot match: a declaration has
     *   no arguments. Left to the call site that has them.
     * - The `rm -rf /` breaker cannot fire either, for the same reason — it
     *   reads `arguments['command']`.
     * - `Plan` refuses `Edit`, `Write` and every `mcp__*` declaration, but NOT
     *   `Bash`: what makes a `Bash` call a write under Plan is a redirection in
     *   its arguments ({@see isBashWriteCommand()}), so a bare `Bash` name is
     *   allowed there exactly as an exploratory `git log` is.
     * - `DontAsk` refuses every declaration that is not a read-only tool.
     * - `Auto` refuses NOTHING through its mode evaluator, and this is
     *   structural rather than an oversight: Auto's judgement is
     *   {@see SafetyClassifier}'s, and the classifier reads
     *   `arguments['command']` — a name alone is never dangerous to it. A
     *   declaration under Auto is therefore only refusable by a Deny rule
     *   (above). Auto's real enforcement is per-call, at whichever layer runs
     *   the call, through {@see evaluate()}.
     * - `Default` / `AcceptEdits` refuse nothing (they `Ask`), and
     *   `BypassPermissions` refuses nothing by definition.
     */
    public function refuses(ToolDeclaration $declaration): bool
    {
        return $this->decide(
            $declaration->asNamedCallForGateOnly(),
            commitAutoStrikes: false,
        ) === PermissionDecision::Deny;
    }

    /**
     * The one copy of the decision path, shared by both entry points.
     *
     * Shared rather than duplicated because the alternative — a second
     * "read-only" evaluator with its own mode table — is a policy that drifts
     * from the enforced one silently, and a security check that no longer
     * matches the thing it claims to predict is worse than no check.
     *
     * @param bool $commitAutoStrikes Whether an Auto-mode outcome may be
     *        recorded in the circuit-breaker counters. TRUE only for a real
     *        call: {@see evaluate()} passes true, {@see refuses()} passes
     *        false. It is the ONLY difference between the two paths.
     */
    private function decide(ToolCall $call, bool $commitAutoStrikes): PermissionDecision
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
            PermissionMode::Auto => $commitAutoStrikes
                ? $this->evaluateAuto($call)
                : $this->autoDeclarationDecision(),
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
     * The ONLY stateful evaluator in this class, and the reason
     * {@see refuses()} exists: every outcome here is recorded, including the
     * safe one, which resets `$consecutiveBlocks`. That reset is correct for a
     * real call (a safe command genuinely breaks a run of blocked ones) and
     * corrupting for a hypothetical one — hence {@see autoDeclarationDecision()}.
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
     * Auto's answer for a {@see ToolDeclaration} — the one call path that must
     * leave the circuit-breaker counters exactly as it found them.
     *
     * Takes no {@see ToolCall} on purpose. A declaration carries no arguments,
     * and {@see SafetyClassifier::classify()} reads `arguments['command']`, so
     * the classifier returns null for every possible declaration — running it
     * would be theatre, and reproducing {@see evaluateAuto()}'s counter
     * arithmetic below a category that cannot occur would be unreachable code
     * dressed as rigour. Auto's declaration policy is the single statement
     * below, and {@see refuses()} says so where a caller will read it: under
     * Auto only an explicit Deny RULE (matched before this method, in
     * {@see decide()}) refuses a declaration.
     *
     * Fail-closed parity with {@see evaluateAuto()} is kept for the
     * missing-classifier case even though `refuses()` treats Ask and Allow
     * alike, so that a misconfigured Auto gate never reads as a confident
     * "allow" from either entry point.
     */
    private function autoDeclarationDecision(): PermissionDecision
    {
        if ($this->classifier === null) {
            return PermissionDecision::Ask;
        }

        return PermissionDecision::Allow;
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
