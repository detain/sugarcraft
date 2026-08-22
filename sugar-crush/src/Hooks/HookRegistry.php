<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

final class HookRegistry
{
    /** @var array<string, array<string, HookInterface>> hooks by event type */
    private array $hooks = [
        'PreToolUse' => [],
        'PostToolUse' => [],
        'Stop' => [],
        'SubagentStop' => [],
        'SessionStart' => [],
        'SessionEnd' => [],
        'UserPromptSubmit' => [],
        'PreCompact' => [],
        'Notification' => [],
        'TeammateIdle' => [],
        'TaskCreated' => [],
        'TaskCompleted' => [],
    ];

    /** @var array<string, bool> disabled hooks */
    private array $disabled = [];

    /** @var array<string, string> matcher => the PCRE error evaluating it raised */
    private array $matcherFailures = [];

    /**
     * @throws \InvalidArgumentException when a hook that is not the permission
     *         gate claims the gate's reserved name — see
     *         {@see self::isReserved()}
     */
    public function register(HookInterface $hook): void
    {
        $event = $hook->event()->value;
        $name = $hook->name();

        if (self::isReserved($name) && !$hook instanceof BuiltIn\PermissionGateHook) {
            throw new \InvalidArgumentException(
                "'{$name}' is reserved for the permission gate and cannot be used as a hook name.",
            );
        }

        $this->hooks[$event][$name] = $hook;
    }

    public function unregister(string $name): void
    {
        if (self::isReserved($name)) {
            return;
        }

        foreach ($this->hooks as $event => $hooks) {
            unset($this->hooks[$event][$name]);
        }
    }

    public function get(string $event, string $name): ?HookInterface
    {
        return $this->hooks[$event][$name] ?? null;
    }

    public function getForEvent(string $event): array
    {
        return array_values($this->hooks[$event] ?? []);
    }

    /**
     * A reserved name is silently ignored here rather than refused: unlike
     * {@see register()}, which is fed a hook someone deliberately constructed,
     * this takes a bare string and is the natural implementation of a "disable
     * hook X" command. Throwing would make a mistyped disable kill the caller;
     * ignoring keeps the gate installed, which is the outcome that matters.
     */
    public function disable(string $name): void
    {
        if (self::isReserved($name)) {
            return;
        }

        $this->disabled[$name] = true;
    }

    public function enable(string $name): void
    {
        unset($this->disabled[$name]);
    }

    public function isDisabled(string $name): bool
    {
        return $this->disabled[$name] ?? false;
    }

    /**
     * True for the one hook name a user-supplied hook may not claim.
     *
     * This registry keys hooks by `name()` and {@see disable()} takes a bare
     * string, so without the reservation a YAML entry called `permission-gate`
     * would silently REPLACE — or, via a disable, UNINSTALL — the launch's
     * {@see BuiltIn\PermissionGateHook}. That is a config file switching the
     * whole permission system off, which is the opposite of what a hook file
     * is allowed to do. The reservation landed ahead of the hole:
     * {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()} now reads
     * `.sugar-crush/hooks.yaml` through {@see HookManager::loadFromFile()},
     * which additionally refuses a config hook that would displace ANY
     * already-registered hook — this reservation is the part that cannot be
     * bypassed by loading order.
     */
    public static function isReserved(string $name): bool
    {
        return $name === BuiltIn\PermissionGateHook::NAME;
    }

    /**
     * Find matching hooks for a tool call.
     *
     * @return array<HookInterface>
     */
    public function findMatches(string $event, string $toolName): array
    {
        $matches = [];

        foreach ($this->getForEvent($event) as $hook) {
            if ($this->isDisabled($hook->name())) {
                continue;
            }

            if ($this->matcherMatches($hook->matcher(), $toolName)) {
                $matches[] = $hook;
            }
        }

        return $matches;
    }

    /**
     * Returns true if the given pattern matches the tool name.
     *
     * Validates the pattern compiles before use to avoid PREG_* errors
     * from malformed regex patterns in hook matcher() implementations.
     *
     * Delimited by {@see HookConfig::pattern()} rather than by a `/` written
     * here, so the delimiter this matches under is the same one
     * {@see HookConfig::parse()} validated under. Two spellings of that would
     * mean a matcher containing the other's delimiter either passed validation
     * and never fired, or failed validation and stopped the launch over a
     * pattern this method would have run happily.
     */
    private function matcherMatches(string $pattern, string $toolName): bool
    {
        $compiled = HookConfig::pattern($pattern);

        // Validate the pattern compiles before use
        if (@preg_match($compiled, '') === false) {
            return false;
        }

        $matched = @preg_match($compiled, $toolName);

        // `false` HERE IS NOT `0`. The compile check above passed, so the
        // pattern is valid; a false at this point is a RUNTIME failure —
        // pcre.backtrack_limit or pcre.recursion_limit, reached while matching
        // this particular subject. `'(a+)+$'` is a matcher
        // {@see HookConfig::parse()} accepts, and against a long tool name it
        // backtracks catastrophically. Reading that as "did not match" makes a
        // guard SILENTLY NOT FIRE on the one call it was chosen for, which is
        // the failure mode a guard may not have — the call would then run
        // ungated with nothing said anywhere.
        //
        // So it fails closed: the hook is treated as matching and gets to
        // decide. A hook that runs on a tool call it did not mean to judge can
        // still allow it (a permitting result does not stop the scan — see
        // {@see executeHooks()}); a hook that never runs cannot deny anything.
        // The failure is also kept rather than swallowed, because "your matcher
        // is too expensive to evaluate" is not something the user can act on if
        // nobody records it — see {@see matcherFailures()}.
        if ($matched === false) {
            $this->matcherFailures[$pattern] = preg_last_error_msg();

            return true;
        }

        return $matched === 1;
    }

    /**
     * Every matcher this registry could not EVALUATE, keyed by the matcher, with
     * the PCRE error as the value.
     *
     * Kept rather than logged for the reason
     * {@see \SugarCraft\Crush\Skills\SkillLoader::skipped()} keeps its skips:
     * this is reached mid-session, on a tool call, where the TUI owns the
     * screen and a stderr line lands inside a frame the renderer believes it
     * owns. A doctor report or a debug pane can ask; the frame is not written
     * to.
     *
     * @return array<string, string>
     */
    public function matcherFailures(): array
    {
        return $this->matcherFailures;
    }

    /**
     * How many times a chain may rewrite the arguments before the call is
     * refused outright.
     *
     * Two mutually-rewriting hooks (`ls` => `ls -l`, `ls -l` => `ls`) would
     * otherwise re-scan forever, so the loop needs a terminal state and DENY
     * is the only safe one: a chain that cannot agree on what it is about to
     * run has not approved anything. A rewrite that converges — the chain
     * re-proposing the rewrite it was just given — is a fixed point and
     * settles without consuming the budget, so this bound is reached only by
     * genuine ping-pong.
     */
    public const MAX_REWRITE_PASSES = 4;

    /**
     * How many hooks the chain-expiry deny reason may name as spenders.
     *
     * The reason has to be actionable, and the actionable part is WHICH hook
     * held the clock — but a chain is as long as its author made it and a
     * matcher can select all of it, so "name them all" is an unbounded string
     * built from configuration, the shape {@see ScriptHook}'s own clip
     * doctrine exists to refuse. The ledger is sorted by spend and cut here,
     * announcing the cut, so the entries that survive are the ones worth
     * acting on.
     */
    private const MAX_NAMED_SPENDERS = 4;

    /**
     * Per-name ceiling inside that reason. A hook's name comes from a YAML
     * file ({@see HookConfig}) and is therefore user-supplied, so it is
     * clipped for the same reason its output is.
     */
    private const MAX_SPENDER_NAME_CHARS = 60;

    /**
     * Execute all matching hooks for an event.
     *
     * Precedence: DENY (and any action that does not permit execution) wins
     * outright. Everything else is settled by the re-scan loop below rather
     * than by ranking the results of a single pass against each other.
     *
     * NEITHER an ASK nor a MODIFY stops the scan — every hook still gets to
     * see the call, so a later hook's hard DENY is always reached. Returning
     * early on a MODIFY was a real fail-open: the gate
     * ({@see BuiltIn\PermissionGateHook}) is registered LAST, so any hook
     * ahead of it that rewrote the arguments handed back a permitting result
     * before the gate had evaluated anything at all.
     *
     * REWRITES ARE RE-SCANNED, which is the other half of that same
     * fail-open and the one the early-return fix did not close. Every hook in
     * a scan is handed the same {@see HookContext}, so a hook that rewrote
     * `Bash{command:"ls"}` into `Bash{command:"rm -rf /"}` was still evaluated
     * by ConfirmRemoveHook and by the gate against `ls` — both allowed, and
     * the rewritten command ran having been judged by nobody. So a pass that
     * produces a usable rewrite is followed by another pass against the
     * REWRITTEN arguments, until the chain stops proposing anything new,
     * re-proposes the rewrite it was given (a fixed point), or exhausts
     * {@see self::MAX_REWRITE_PASSES} and is denied.
     *
     * A REWRITE MADE IN THE SAME PASS AS A QUESTION IS RE-SCANNED FIRST, and
     * the question is put only once the arguments have stopped moving. Ranking
     * ASK above MODIFY inside one pass — the obvious reading of "a rewrite is
     * worthless until the call is permitted at all" — threw that pass's
     * rewrite away, and this is the SHIPPED chain's normal configuration:
     * {@see BuiltIn\PermissionGateHook}'s ASK is mode-driven, not
     * argument-driven, so in Default mode it asks about every write tool on
     * pass 1 and there is never a pass 2 for a carried rewrite to arrive on.
     * A sanitising hook ahead of it (`/etc/passwd` => `./build/out.txt`) was
     * therefore defeated outright: the approver saw `/etc/passwd`, and
     * {@see HookManager::resolveAsk()} settled the approval onto the originals
     * the rewrite existed to replace. Carrying the pass's own rewrite onto the
     * ASK would fix the dispatch but still ask the question about arguments no
     * hook behind the rewriter had seen; re-scanning fixes both, and is the
     * same rule the loop already applies to every other rewrite.
     *
     * AN ASK'S OWN `modifiedInput` IS A PROPOSAL, JUDGED LIKE A MODIFY, and it
     * leaves this loop only if the loop settled on it. WHERE a rewrite came
     * from cannot decide whether the chain gets to see it, and an ASK carried
     * the only rewrite that skipped the re-scan: a hook returning
     * `ask('Allow Bash to run?', '{"command":"rm -rf /"}')` was answered by a
     * user who had been shown `ls` — or, on a tool already granted
     * {@see \SugarCraft\Crush\Permissions\PermissionReply::Always}, by nobody at
     * all — and {@see HookManager::resolveAsk()} then settled that approval
     * into exactly the MODIFY that ran it, past three built-ins that hard-deny
     * it. Judging it costs one more pass and produces ConfirmRemoveHook's DENY.
     * A hook that sanitises AND asks is therefore still supported, and gets its
     * question put about the sanitised call — the only version worth asking;
     * dropping an ASK's rewrite outright would close the same hole by SILENTLY
     * DISCARDING a sanitisation, which is the failure mode {@see scan()}'s
     * fixed-point rule exists to stop.
     *
     * The cost is that a user-supplied PreToolUse hook's side effects run once
     * per pass on the rare call that gets rewritten. That is the right trade:
     * the built-in chain's only side-effecting hook is `AuditHook`, which is
     * PostToolUse and so never re-scanned, and a guard being consulted twice
     * is a far smaller problem than a guard being consulted about arguments
     * that are not the ones about to run.
     *
     * THE OTHER COST OF THAT RE-SCAN IS WALL CLOCK, AND IT IS BOUNDED HERE.
     * {@see ScriptHook::DEFAULT_TIMEOUT_SECONDS} bounds ONE hook run; this loop
     * runs every matching hook and does it again up to
     * {@see self::MAX_REWRITE_PASSES} times, so "a hook cannot freeze the CLI"
     * was true of one hook and merely rate-limiting for a chain — hooks x
     * passes x 60 seconds, on the TUI's own thread. A chain deadline is armed
     * ONCE, before the first pass, and every bounded hook is charged against
     * what is left of it: the same shape
     * {@see \SugarCraft\Crush\Commands\CommandSpec::expandTemplate()} uses for
     * its shell budget, and for the same reason — a budget that resets per item
     * is not a budget.
     *
     * A CHAIN THAT RUNS OUT IS A DENY, which is the one decision this adds and
     * it is the same one {@see ScriptHook::execute()} already made for a single
     * expired hook: the guards still queued behind the expiry have said nothing,
     * and "allow" would skip them invisibly. It costs the model one retry and
     * names the budget it exceeded.
     */
    public function executeHooks(string $event, HookContext $context): HookResult
    {
        $modified = null;
        $passes = 0;

        // ARMED ONCE, OUTSIDE THE LOOP — see chainBudgetSeconds(). Inside it,
        // every re-scan would get a fresh budget and the multiplication by
        // MAX_REWRITE_PASSES this exists to remove would still be there.
        $chainBudget = $this->chainBudgetSeconds($event, $context->toolName);
        $armedAt = microtime(true);
        $chainDeadline = $chainBudget === null ? null : $armedAt + $chainBudget;

        // WHO SPENT THE CLOCK, accumulated ACROSS passes because the deadline
        // is armed across passes. A per-pass ledger would name only the
        // spenders of the pass that happened to hit the wall, and on a
        // rewriting chain that is routinely not the pass that spent the time.
        $spend = [];

        while (true) {
            [$blocking, $rewrite, $inertRewrite] = $this->scan(
                $event,
                $context,
                $modified?->modifiedInput,
                $chainDeadline,
                $chainBudget,
                $spend,
                $armedAt,
            );

            if ($blocking !== null && !$blocking->isAsk()) {
                // DENY, or an action this class does not recognise as
                // permitting. Nothing the pass also turned up outranks it.
                //
                // Returned VERBATIM, `modifiedInput` included, and that is
                // safe for the same reason the ASK below is not: this result
                // permits nothing, and no consumer of a rewrite honours one on
                // a non-permitting action. {@see Runtime::rewrittenArguments()}
                // gates on `isModified()`, {@see \SugarCraft\Crush\Chat::applyRewrite()}
                // on `isModified() || isAsk()`, and {@see HookManager::resolveAsk()}
                // refuses anything that is not an ASK — so a DENY's carried
                // rewrite reaches no dispatcher. Rewriting it away here would
                // also have to invent an action to return it under, which is
                // exactly the widening {@see HookResult::permitsExecution()}'s
                // allow-list exists to prevent.
                return $blocking;
            }

            $decoded = $rewrite?->rewrittenArgs();

            // Something new to judge: re-scan before anything settles — before
            // a pending ASK is returned as well, so the question is about the
            // arguments that will actually run. The chain re-proposing the
            // rewrite it was just handed is a fixed point, not ping-pong: it
            // has agreed, and settles without spending the budget.
            if ($decoded !== null && $rewrite->modifiedInput !== $modified?->modifiedInput) {
                if (++$passes > self::MAX_REWRITE_PASSES) {
                    return HookResult::deny(
                        'Hooks kept rewriting the arguments for ' . $context->toolName
                        . ' (' . self::MAX_REWRITE_PASSES . ' rewrites without settling); refusing to run any of them.',
                    );
                }

                $modified = $rewrite;
                $context = $context->withRewrittenArgs($decoded, (string) $rewrite->modifiedInput);
                continue;
            }

            if ($blocking !== null) {
                // An ASK, with the arguments now settled. The rewrite has to
                // travel with the question, or an approval would run the
                // originals the rewrite existed to replace.
                // {@see HookManager::resolveAsk()} picks it up again.
                //
                // REBUILT UNCONDITIONALLY — never returned verbatim — so the
                // only rewrite that can leave this loop is $modified, one the
                // loop itself put through the re-scan. That is what makes the
                // "only executeHooks() sets it" line on {@see HookResult::ask()}
                // true rather than merely asked for, and an ASK's own
                // `modifiedInput` used to be the one rewrite NOTHING re-scanned:
                // {@see scan()} filed an asking result as the pending question
                // and recorded a rewrite only for an `isModified()` result, so
                // the `$modified === null` arm handed the hook's result straight
                // back with its rewrite intact — and
                // {@see HookManager::resolveAsk()} settled an approval into a
                // MODIFY carrying it. `ask('Allow Bash to run?',
                // '{"command":"rm -rf /"}')` therefore ran `rm -rf /` on
                // approval, judged by neither ConfirmRemoveHook,
                // ProtectFilesHook nor {@see BuiltIn\PermissionGateHook} — all
                // three of which had been handed `ls`. Worse in
                // {@see \SugarCraft\Crush\Chat}: a prior
                // `PermissionReply::Always` for the tool turns that ASK into
                // permission with no prompt at all, so not even a human is
                // shown the wrong command. It is now a proposal that goes
                // through the loop like any other, so that same hook produces a
                // DENY from ConfirmRemoveHook on pass 2.
                return HookResult::ask($blocking->message, $modified?->modifiedInput);
            }

            // ALLOW, settled against the arguments in $context. A permitting
            // verdict on a rewritten call is reported as the rewrite, since
            // that is what the caller has to execute — and a rewrite the whole
            // chain already agreed on outranks an inert one a later pass threw
            // up, which would otherwise discard it and send every consumer
            // back to the originals nobody proposed. {@see HookDispatcher} has
            // always kept the settled rewrite here; the two loops agree again.
            return $modified ?? $inertRewrite ?? HookResult::allow();
        }
    }

    /**
     * What the WHOLE chain for this event+tool gets, in seconds — or null when
     * there is nothing here a deadline could be charged to.
     *
     * THE SUM OF THE DECLARED TIMEOUTS, and that figure is derived rather than
     * invented, which is the whole reason it is defensible. Every other
     * candidate is a guess: a fresh constant is a number somebody eventually
     * raises for every chain at once (the objection
     * {@see ScriptHook::DEFAULT_TIMEOUT_SECONDS} already makes about being a
     * default and not a cap), and reusing the 60-second default as a chain cap
     * would silently break the legitimate two-hook file whose entries each
     * asked for 60. Summing honours every author's per-entry figure EXACTLY on
     * a single pass and takes away only the multiplication by
     * {@see self::MAX_REWRITE_PASSES} — which no author asked for and none can
     * see. So the sentence a user can rely on is "at worst, the total of what I
     * wrote, once".
     *
     * NULL RATHER THAN ZERO when no hook in the chain implements
     * {@see BoundedHookInterface}. A hand-written PHP hook is a synchronous
     * call in this process with no deadline to honour, so a chain of those is
     * bounded by nothing here — exactly as it always was — and arming a
     * zero-second deadline over them would deny every call in the built-in
     * chain instead. The gap is real and it is named rather than papered over:
     * see {@see BoundedHookInterface}.
     */
    private function chainBudgetSeconds(string $event, string $toolName): ?float
    {
        $budget = null;

        foreach ($this->findMatches($event, $toolName) as $hook) {
            if ($hook instanceof BoundedHookInterface) {
                $budget = ($budget ?? 0.0) + $hook->timeoutSeconds();
            }
        }

        return $budget;
    }

    /**
     * The refusal a chain that ran out of clock is denied with.
     *
     * THE ONE THING THIS HAS TO GET RIGHT is separating the hook that was
     * STOPPED from the hooks that SPENT the time, because they are almost never
     * the same hook and the old wording conflated them. It names, in order:
     *
     * 1. elapsed against budget — two numbers, because a chain overruns by
     *    exactly as much as its last unbounded hook overran, and that gap is
     *    the evidence that the sum was not what ran out;
     * 2. where the sum came from, so the figure is checkable against the YAML
     *    rather than looking like a constant somebody chose;
     * 3. the spenders, largest first, each marked as counted-in-the-sum or not;
     * 4. the hook that was stopped, stated as having run for zero seconds;
     * 5. what the user can actually do — and, when an unbounded hook is
     *    implicated, that raising a `timeout:` is NOT it. That sentence is the
     *    whole point of the change: the old message pointed at the one lever
     *    that cannot move this outcome.
     *
     * BOUNDED, per {@see MAX_NAMED_SPENDERS}/{@see MAX_SPENDER_NAME_CHARS} and
     * for the same reason {@see ScriptHook} clips its own reasons: every name
     * in here is user-supplied and the list is as long as the matcher selected.
     *
     * @param string $stoppedHook the hook denied without being run — the one
     *        whose timeout the OLD message named as the budget
     * @param list<array{name: string, seconds: float, bounded: bool}> $spend
     */
    private function chainExpiryReason(
        string $toolName,
        string $stoppedHook,
        float $chainBudget,
        float $elapsed,
        array $spend,
    ): string {
        $unbounded = array_filter($spend, static fn (array $entry): bool => !$entry['bounded']);
        $unboundedSeconds = array_sum(array_column($unbounded, 'seconds'));

        // Largest spender first: the list is cut, so it has to be cut at the
        // end that matters. usort is stable enough here — ties are hooks that
        // cost the same, and their order carries no information.
        usort($spend, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);
        $named = array_slice($spend, 0, self::MAX_NAMED_SPENDERS);

        $rows = [];
        foreach ($named as $entry) {
            $rows[] = sprintf(
                '%s (%ss, %s)',
                self::clipName($entry['name']),
                self::seconds($entry['seconds']),
                $entry['bounded']
                    ? 'declared a timeout, counted in the budget'
                    : 'NO declared timeout, spends the budget without adding to it',
            );
        }

        $omitted = count($spend) - count($named);
        if ($omitted > 0) {
            $rows[] = sprintf('… and %d more hook(s), not listed', $omitted);
        }

        $reason = sprintf(
            'Hooks for %s ran %ss against a %ss budget and were stopped. '
            . 'That budget is the sum of the declared timeouts of the bounded hooks matching this call, '
            . 'not a constant. ',
            $toolName,
            self::seconds($elapsed),
            self::seconds($chainBudget),
        );

        $reason .= $rows === []
            // REACHED, and measured rather than assumed — but NOT by the route
            // that first suggested itself. A `timeout: 0` does not get here:
            // {@see ScriptHook::timeoutSeconds()} reads zero as "unset" and
            // answers its 60-second default, so the chain is given a minute.
            // What does get here is a POSITIVE sub-microsecond timeout
            // (`timeout: 0.000001`, verified): the budget is then smaller than
            // the walk from arming the deadline to reaching the first hook, so
            // the chain expires having run nothing at all.
            ? 'No hook in the chain had run yet: the budget is smaller than the chain\'s own setup cost. '
            : 'Clock spent by: ' . implode('; ', $rows) . '. ';

        $reason .= sprintf(
            'Stopped at %s, which ran for 0s and consumed none of the budget its own timeout contributed. ',
            self::clipName($stoppedHook),
        );

        if ($spend === []) {
            // Said separately from the all-bounded case below, which would
            // otherwise state "every hook that RAN declared a timeout" about a
            // chain in which none did — true and useless, and the exact shape
            // of claim this plan's §5 is about.
            $reason .= 'Raise that timeout above the chain\'s scheduling overhead; no hook\'s own runtime is implicated. ';
        } elseif ($unbounded !== []) {
            $reason .= sprintf(
                'Raising a `timeout:` will NOT fix this: %ss of the elapsed time was spent by hand-written '
                . 'hook(s) with no declared timeout, which run in-process and cannot be bounded here '
                . '(see BoundedHookInterface) — a larger budget would be overrun the same way. '
                . 'Remove or speed up the unbounded hook(s) instead. ',
                self::seconds($unboundedSeconds),
            );
        } else {
            $reason .= 'Every hook that ran declared a timeout, so raising those timeouts raises this budget. ';
        }

        return $reason . 'A hook chain that has not answered has not allowed anything.';
    }

    /**
     * Seconds, trimmed of trailing zeroes so `1.000` reads as `1`.
     *
     * Three decimals because a hook chain's overrun is routinely sub-second
     * and an integer-second rendering would print `Hooks ran 2s against a 2s
     * budget` for a real 200ms overrun — a message that refutes itself.
     *
     * AND A NONZERO VALUE NEVER RENDERS AS `0`, which three decimals alone does
     * not give: the sub-microsecond-timeout chain above is real, and at three
     * decimals its refusal read `ran 0s against a 0s budget` — self-refuting in
     * the other direction. Anything that would round away falls back to a
     * fixed-significand form, which is ugly and honest rather than tidy and
     * wrong.
     */
    private static function seconds(float $value): string
    {
        $rendered = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        if ($rendered === '0' && $value > 0.0) {
            return rtrim(rtrim(sprintf('%.9f', $value), '0'), '.');
        }

        return $rendered;
    }

    /** A hook name is YAML-supplied, so it is clipped like any foreign string. */
    private static function clipName(string $name): string
    {
        return mb_strlen($name) <= self::MAX_SPENDER_NAME_CHARS
            ? $name
            : mb_substr($name, 0, self::MAX_SPENDER_NAME_CHARS) . '…[name truncated]';
    }

    /**
     * One full pass over the matching hooks, reporting what it found rather
     * than ranking it: {@see executeHooks()} owns the precedence, because a
     * rewrite and a question found in the SAME pass are not two candidates for
     * one slot — the rewrite is re-scanned and then the question is asked
     * about its result.
     *
     * WHICH REWRITE WINS: the first one that decodes to an argument map AND
     * proposes something other than the rewrite already settled — the same
     * "first usable one" rule {@see HookDispatcher::scan()} applies, stated
     * here so the two loops can be read against each other. "First MODIFY,
     * decodable or not" looked simpler and was worse: an inert rewrite (one
     * that will not decode to an argument map,
     * {@see HookResult::rewrittenArgs()}) is a no-op for every consumer, so
     * letting it win SILENTLY DISCARDED the real rewrite a later hook in the
     * same pass made, and the call then ran with the originals nobody had
     * proposed. An inert rewrite is still reported, because when it is the only
     * thing the chain produced it is evidence of a misconfigured hook and
     * swallowing it into a plain ALLOW would hide that too.
     *
     * $settled IS WHY A FIXED POINT DOES NOT WIN THE PASS OUTRIGHT. The loop
     * reads "the chain has stopped proposing anything new" off the rewrite this
     * returns, so returning the pass's first usable rewrite unconditionally
     * decided that from ONE hook. A hook that re-proposes the same rewrite on
     * every pass (`* => ls -l`) is a fixed point from pass 2 onward, and being
     * first it settled the chain while a SANITISER behind it — which had by
     * then seen `ls -l` and asked for `ls -l --safe` — was silently dropped and
     * the call ran unsanitised. Swapping the two hooks' registration order gave
     * the `deny` the pair deserves, so the outcome was order-dependent and the
     * permissive order was the unsanitised one: round 5's MAJOR again, in a
     * different disguise. A fixed point is therefore kept only as the FALLBACK,
     * used when the pass turned up nothing else — which is the case the loop's
     * own fixed-point test is written for.
     *
     * @param ?string $settled the `modifiedInput` the loop has already settled
     *        on, or null on the first pass
     * @param ?float $chainDeadline `microtime(true)`-based instant the WHOLE
     *        chain — every hook, every re-scan — has to be finished by. Null
     *        when no hook in the chain has a bound to charge (see
     *        {@see chainBudgetSeconds()}).
     * @param ?float $chainBudget what that deadline was armed with, carried
     *        only so the refusal can name a number the user recognises
     * @param list<array{name: string, seconds: float, bounded: bool}> $spend
     *        BY REFERENCE, and accumulated across passes by
     *        {@see executeHooks()}: what every hook that has already run cost,
     *        and whether it was one the budget was derived from. The refusal
     *        below is unreadable without it — see the note on its own arm.
     * @param float $armedAt `microtime(true)` at the instant the chain
     *        deadline was armed, so the refusal can state ELAPSED rather than
     *        only BUDGETED time. The two differ by however long the last
     *        unbounded hook overran, and that difference is the evidence.
     *
     * @return array{0: ?HookResult, 1: ?HookResult, 2: ?HookResult} [the
     *     result that blocks the call outright — a DENY, or the pass's first
     *     ASK; the pass's first USABLE rewrite, preferring one that is not
     *     already $settled; the pass's first inert rewrite]
     */
    private function scan(
        string $event,
        HookContext $context,
        ?string $settled = null,
        ?float $chainDeadline = null,
        ?float $chainBudget = null,
        array &$spend = [],
        float $armedAt = 0.0,
    ): array {
        $pendingAsk = null;
        $pendingModify = null;
        $pendingFixedPoint = null;
        $pendingInertModify = null;

        foreach ($this->findMatches($event, $context->toolName) as $hook) {
            if ($chainDeadline !== null && $hook instanceof BoundedHookInterface) {
                $remaining = $chainDeadline - microtime(true);

                if ($remaining <= 0.0) {
                    // A DENY, and for the same reason one expired hook is a
                    // DENY ({@see ScriptHook::execute()}): a chain that has run
                    // out of clock has not approved anything, and letting the
                    // call through would silently skip whatever guards are
                    // still queued behind this point.
                    //
                    // WHAT THIS MESSAGE USED TO SAY, and why it changed: it
                    // read "did not finish within the N seconds their timeouts
                    // add up to" and named nothing else. Every noun in it was
                    // true and the sentence was still misleading, because the
                    // hook holding N is $hook — the one being stopped HERE,
                    // which has consumed exactly zero of it. The clock is
                    // spent by whatever ran earlier, and a hand-written
                    // HookInterface contributes NOTHING to the sum while
                    // spending it freely (it is not a BoundedHookInterface, so
                    // chainBudgetSeconds() never asks it and this charge never
                    // shortens it). A user reading the old text would raise
                    // this hook's `timeout:`, which cannot help: the budget
                    // would grow, the unbounded spender would still overrun
                    // it, and the same hook would be denied again. So the
                    // reason now names the spenders, says which of them the
                    // budget was derived from, and states elapsed next to
                    // budgeted.
                    return [HookResult::deny($this->chainExpiryReason(
                        $context->toolName,
                        $hook->name(),
                        (float) $chainBudget,
                        microtime(true) - $armedAt,
                        $spend,
                    )), null, null];
                }

                // Charged, not granted: withTimeoutSeconds() only ever shortens.
                $hook = $hook->withTimeoutSeconds($remaining);
            }

            $startedAt = microtime(true);
            $result = $hook->execute($context);
            // LEDGERED UNCONDITIONALLY, before any early return: a hook that
            // denies still spent the clock, and on a rewriting chain the pass
            // that spends the budget is often not the pass that hits the wall.
            $spend[] = [
                'name' => $hook->name(),
                'seconds' => microtime(true) - $startedAt,
                'bounded' => $hook instanceof BoundedHookInterface,
            ];

            if (!$result->isAsk() && !$result->permitsExecution()) {
                return [$result, null, null];
            }

            $proposal = null;

            if ($result->isAsk()) {
                // First question asked wins; a second ASK adds nothing since
                // one unanswered prompt already blocks the call.
                $pendingAsk ??= $result;

                // AN ASK'S OWN REWRITE IS A PROPOSAL, NOT A VERDICT — see the
                // note in executeHooks(). Recorded as a MODIFY so it takes
                // exactly the path a MODIFY takes (re-scanned, then settled or
                // denied) and so nothing downstream has to care which action
                // proposed it. An inert one is skipped rather than filed under
                // $pendingInertModify: that slot is what a pass with NOTHING
                // else reports, and a pass with an ASK in it never reaches the
                // line that reads it.
                if ($result->rewrittenArgs() !== null) {
                    $proposal = HookResult::modify((string) $result->modifiedInput);
                }
            } elseif ($result->isModified()) {
                $proposal = $result;
            }

            // ...and a plain ALLOW is deliberately NOT a source of proposals,
            // even though the constructor lets one be hand-built carrying a
            // `modifiedInput`. A hook that means to change the arguments says
            // MODIFY (or ASK, and gets asked about them); honouring a rewrite
            // on a result that asked for nothing would make every consumer's
            // `isModified() || isAsk()` gate the thing standing between the
            // chain and arguments nobody declared.

            if ($proposal === null) {
                continue;
            }

            // Rewrites do not COMPOSE within one pass: both hooks were handed
            // the same arguments, so a second rewrite was computed against
            // input the first one already replaced. The first USABLE one wins;
            // the re-scan in executeHooks() is where the rest of the chain gets
            // to see what actually happened.
            if ($proposal->rewrittenArgs() === null) {
                $pendingInertModify ??= $proposal;
            } elseif ($settled !== null && $proposal->modifiedInput === $settled) {
                // Proposes exactly what the chain already settled on: keep it,
                // but keep looking — see $settled above.
                $pendingFixedPoint ??= $proposal;
            } else {
                $pendingModify ??= $proposal;
            }
        }

        return [$pendingAsk, $pendingModify ?? $pendingFixedPoint, $pendingInertModify];
    }
}
