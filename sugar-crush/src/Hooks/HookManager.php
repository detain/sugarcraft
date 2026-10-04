<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

use SugarCraft\Crush\Permissions\ApprovalVerdict;

/**
 * Manages hook loading and execution.
 */
final class HookManager
{
    public function __construct(
        private HookRegistry $registry,
    ) {}

    /**
     * A clone gets its OWN registry, so registering on the copy leaves the
     * original's chain alone (audit F-J5: {@see
     * \SugarCraft\Crush\Backend\EngineBackend::withWorktreeRoot()} used to add
     * its worktree-scoped Bash guard to the parent backend's shared manager).
     * The registry's state is arrays of hook instances, so a shallow registry
     * clone is a full copy of the chain; the hook objects themselves are
     * shared, exactly as they are between two turns on one manager.
     */
    public function __clone()
    {
        $this->registry = clone $this->registry;
    }

    /**
     * Load hooks from a YAML hook file, adding each one to the chain.
     *
     * LIVE since crush_code.md Phase 2 item 5:
     * {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()} calls this for
     * `~/.sugar-crush/hooks.yaml` — and for `{root}/.sugar-crush/hooks.yaml`
     * only when the user has TRUSTED that project, since a hook entry is a
     * shell command and a project file arrives with whatever was cloned (see
     * {@see \SugarCraft\Crush\Cli\Bootstrap::hookFiles()}) — after
     * {@see registerBuiltIns()}, which is what makes {@see ScriptHook}'s
     * `exit 3`/`exit 4` (ASK and MODIFY) reachable from configuration rather
     * than only from hand-written PHP — the reachability
     * {@see HookRegistry::executeHooks()} and {@see HookRegistry::isReserved()}
     * are written against. Embedders still call it directly for their own
     * files.
     *
     * A LOADED HOOK MAY ONLY ADD TO THE CHAIN, NEVER REPLACE ANYTHING IN IT.
     * {@see HookRegistry::register()} keys by event+name and overwrites, so
     * without the guard below a file saying `name: confirm-rm` on
     * `event: PreToolUse` would UNINSTALL {@see BuiltIn\ConfirmRemoveHook} — a
     * config file switching a guard off by naming it, which is the same hole
     * {@see HookRegistry::isReserved()} closes for the permission gate, and
     * which a file the CLI now reads by convention makes reachable. Refusing
     * BOTH HALVES OF THAT EXAMPLE ARE LOAD-BEARING, and this line spent its
     * whole life naming the wrong one. It read `confirm-remove` — the CLASS
     * name's spelling, not the hook's. {@see BuiltIn\ConfirmRemoveHook::name()}
     * returns `confirm-rm`, so `confirm-remove` is the one name here that
     * collides with nothing and is quietly ACCEPTED as an additional hook.
     * The example demonstrated the opposite of the outcome it claimed, which
     * `docs/HOOKS.md`'s own table has stated correctly the whole time
     * (`confirm-rm` refused, `confirm-remove` accepted). The event half matters
     * for the same reason: the key is event PLUS name, so `confirm-rm` on
     * `PostToolUse` is also accepted. A guard example that names neither
     * coordinate precisely is not an example of the guard.
     *
     * The guard itself was never wrong — it asks
     * {@see HookRegistry::get()} for `$event` + `$name` and hardcodes no list,
     * so it protects whatever is registered. Only this prose was.
     *
     * Refusing is also what keeps the outcome independent of WHICH file was
     * loaded first: a project `.sugar-crush/hooks.yaml` cannot disarm a hook the
     * user wrote in their home directory by reusing its name, in either
     * registration order.
     *
     * @throws \RuntimeException when the file exists and cannot be read
     * @throws \InvalidArgumentException when the file cannot be used, or when
     *         one of its hooks would displace an already-registered hook
     */
    public function loadFromFile(string $path): void
    {
        $this->loadEntries(HookConfig::loadFromFile($path), $path);
    }

    /**
     * {@see loadFromFile()} with the parse already done — same registration
     * contract, same refusals, $source only naming the file the entries came
     * from so the messages stay actionable.
     *
     * Split out for {@see \SugarCraft\Crush\Cli\Bootstrap::hookFileEntries()},
     * which reads each hook file ONCE PER LAUNCH and replays the entries into
     * every hook manager it builds, so a file appearing or changing mid-session
     * cannot install shell into a chain the launch already vetted. Embedders
     * that just want "load my file" keep calling loadFromFile().
     *
     * @param array<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool, timeout: float}> $configs
     *
     * @throws \InvalidArgumentException when an entry would displace an
     *         already-registered hook
     */
    public function loadEntries(array $configs, string $source): void
    {
        foreach ($configs as $config) {
            // `disabled: true` MEANS NOT IN THE CHAIN, and it means it by not
            // registering rather than by calling {@see HookRegistry::disable()}.
            // That registry method keys by NAME ALONE, across every event, so
            // routing a config file's disable through it would let an entry
            // named after a hook registered on a DIFFERENT event switch that
            // one off — a config file disarming a guard by naming it, which is
            // the hole {@see HookRegistry::isReserved()} and the
            // already-registered refusal below exist to close, re-opened by
            // the back door. The entry is still fully VALIDATED first (see
            // {@see HookConfig::parse()}), so `disabled: true` beside a
            // misspelled key or an uncompilable matcher still stops the launch.
            if ($config['disabled'] ?? false) {
                continue;
            }

            $hook = ScriptHook::fromConfig($config);
            $event = $hook->event()->value;
            $name = $hook->name();

            if ($this->registry->get($event, $name) !== null) {
                throw new \InvalidArgumentException(
                    "{$source}: a hook named '{$name}' is already registered for {$event}; "
                    . 'a hook file may add to the chain but may not replace what is already in it.',
                );
            }

            $this->registry->register($hook);
        }
    }

    /**
     * Register built-in hooks.
     */
    public function registerBuiltIns(): void
    {
        $this->registry->register(new BuiltIn\ProtectFilesHook());
        $this->registry->register(new BuiltIn\ConfirmRemoveHook());
        $this->registry->register(new BuiltIn\AuditHook());
    }

    /**
     * Register a custom hook.
     */
    public function register(HookInterface $hook): void
    {
        $this->registry->register($hook);
    }

    /**
     * The hook registered for $event under $name, or null.
     *
     * A reader rather than an exposed registry, so a caller can find the one
     * hook it needs — {@see \SugarCraft\Crush\Chat} recovering the launch's
     * {@see BuiltIn\PermissionGateHook} to carry its gate across a provider
     * switch — without gaining the ability to re-key the chain from outside.
     */
    public function hook(string $event, string $name): ?HookInterface
    {
        return $this->registry->get($event, $name);
    }

    /**
     * Pre-tool-use hook execution.
     *
     * A HookResult::ask() decision is passed through verbatim, not collapsed
     * into allow/deny: only the caller owns the UI that can answer it. Until
     * the blocking permission-request flow lands, Runtime's gate treats ASK as
     * not-permitted (HookResult::permitsExecution() is false for it), so an
     * unanswered ASK fails closed rather than running the tool.
     */
    public function preToolUse(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::PreToolUse->value, $context);
    }

    /**
     * Turn an answered ASK into a settled ALLOW or DENY.
     *
     * ASK is the one action that cannot settle itself: the PreToolUse gate
     * returns it, the UI puts the question to the user, and the answer comes
     * back here. This lives on HookManager rather than HookResult because a
     * HookResult is a readonly value with no notion of a user or a session.
     *
     * An {@see ApprovalVerdict} (roadmap 1.C-2) settles the same way as its
     * `permits()` bit, and a refusing verdict's feedback — a user's note, or
     * the reason nobody could answer — is appended to the hook's question
     * ({@see ApprovalVerdict::denialMessage()}) rather than replacing it, so
     * the model reads both what was asked and what came back. The `bool`
     * form is unchanged for every existing caller.
     *
     * @param HookResult $ask the ASK decision preToolUse() returned
     * @param bool|ApprovalVerdict $approved true (or a permitting verdict) when
     *                         the user permitted the call
     * @param string $feedback optional "reject with feedback" text that
     *                         replaces the hook's own prompt in the settled
     *                         result's message; ignored for a verdict, which
     *                         carries its own
     * @throws \InvalidArgumentException when $ask is not an ASK — an already
     *         settled decision must not be re-resolved, since doing so is a
     *         path from DENY to ALLOW
     */
    public function resolveAsk(HookResult $ask, bool|ApprovalVerdict $approved, string $feedback = ''): HookResult
    {
        if (!$ask->isAsk()) {
            throw new \InvalidArgumentException(
                "Cannot resolve a '{$ask->action}' hook result: only an ask awaits a user decision.",
            );
        }

        if ($approved instanceof ApprovalVerdict) {
            $feedback = $approved->permits() ? '' : $approved->denialMessage($ask->message);
            $approved = $approved->permits();
        }

        $message = $feedback !== '' ? $feedback : $ask->message;

        // The chain's collected `additionalContext` (see
        // {@see HookRegistry::executeHooks()}) travels with the settled verdict
        // through EVERY arm — deny, allow and modify alike — or approving an ASK
        // would silently drop the model-visible note the chain gathered before it
        // raised the question. This is the last of the four internal rebuild
        // points; the field is produced upstream and consumed downstream, and a
        // drop here (the settled verdict a real run returns to Runtime/Chat) is
        // the one that would make the whole slice dead in practice.
        if (!$approved) {
            return HookResult::deny($message, $ask->additionalContext);
        }

        // An ASK raised over a call an earlier hook already REWROTE settles as
        // that rewrite, not as a bare allow: the question was put about the
        // rewritten arguments ({@see HookRegistry::executeHooks()} re-scans
        // against them), so dropping the rewrite here would run the originals
        // the user was never asked about.
        return $ask->modifiedInput === null
            ? HookResult::allow($message, $ask->additionalContext)
            : HookResult::modify($ask->modifiedInput, $message, $ask->additionalContext);
    }

    /**
     * Post-tool-use hook execution.
     *
     * FAIL-CLOSED ON A THROW (audit R6). The call has already run, so a hook
     * that throws cannot unwind anything — but it has vetted nothing, and the
     * hooks queued behind it never ran. It is reported as a DENY naming the
     * hook that threw, which both tool paths turn into withheld output, exactly
     * as they treat a hook that timed out. It used to escape to the caller,
     * which noted it next to the output and showed the model every byte the
     * rest of the chain would have judged.
     *
     * The audit hook runs LAST in this chain (audit R7, see
     * {@see HookRegistry::findMatches()}), so a refusal or a throw reaches the
     * caller before it has logged an excerpt of the refused output.
     */
    public function postToolUse(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::PostToolUse->value, $context, failClosedOnThrow: true);
    }

    /**
     * Session-start hook execution (P7.S2).
     *
     * Anthropic fires `SessionStart` at the "start of conversation, before the
     * first prompt" — insertion-point table, prompt_expand.md §4.12 (Anthropic
     * hooks documentation, external-verified 2026-09-05) — and it is one of only
     * three events whose plain stdout reaches the model at all. Before this
     * method existed the event PARSED and REGISTERED and was then never
     * dispatched: {@see HookConfig::parse()} accepted the name, {@see HookRegistry}
     * put the hook in its `SessionStart` bucket, and the hook sat there silent.
     *
     * WHY THE CONTEXT IS TOOL-SHAPED, i.e. why a session event arrives in a value
     * whose fields are named `toolName`/`toolInput`/`toolOutput`: {@see HookContext}
     * is the shared readonly value every hook in the tree already reads, and the
     * script protocol already feeds it (`ScriptHook` hands the child
     * `CRUSH_TOOL_NAME`/`CRUSH_TOOL_INPUT` and nothing else — there is no stdin
     * payload). Adding `prompt`/`source` fields would ripple through the value's
     * three withers and every construction site for no reach a smuggled field does
     * not already give. So {@see \SugarCraft\Crush\Chat} packs the real payload the
     * same way {@see \SugarCraft\Crush\Chat::gateToolCall()} always has:
     *
     * - `toolName` carries the EVENT NAME as a sentinel, because that slot is what
     *   a hook's matcher is actually tested against
     *   ({@see HookRegistry::findMatches()}) — for these two events the matcher is
     *   the event itself.
     * - `toolInput` carries a JSON object: `{"prompt": "<opening text>",
     *   "source": "startup"}`.
     * - `model`/`provider` are EMPTY, per the `gateToolCall()` precedent — Chat has
     *   no model/provider identity to report at this seam, and guessing would be
     *   worse than reporting nothing.
     *
     * A DENY here does not stop the session: `HookEvent::stderrToUserOnly()` says
     * this event's block output is operator-facing, so the caller discards the note
     * and surfaces the reason.
     */
    public function sessionStart(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::SessionStart->value, $context);
    }

    /**
     * User-prompt-submit hook execution (P7.S2).
     *
     * Anthropic fires `UserPromptSubmit` "alongside the submitted prompt"
     * (prompt_expand.md §4.12, external-verified 2026-09-05) and a block there
     * DISCARDS the prompt itself — that is what `HookEvent::discardsOnBlock()`
     * declares, and it is the caller's decision to act on, not this method's: the
     * verdict passes through verbatim exactly as {@see preToolUse()} passes an ASK
     * through rather than collapsing it.
     *
     * Context smuggling is as described on {@see self::sessionStart()} —
     * `toolName` = the `'UserPromptSubmit'` sentinel, `toolInput` = JSON
     * `{"prompt": "<submitted text>"}`, `model`/`provider` empty.
     */
    public function userPromptSubmit(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::UserPromptSubmit->value, $context);
    }

    /**
     * Pre-compaction hook execution (roadmap 2.12).
     *
     * Context smuggling follows {@see self::sessionStart()}: `toolName` is the
     * `'PreCompact'` sentinel the matcher tests, `toolInput` is JSON
     * `{"trigger": "manual"|"auto", "custom_instructions": "<the /compact focus>"}`
     * (Claude Code's field names), `model`/`provider` are empty. A verdict that
     * does not permit is the caller's cue to SKIP the compaction; the verdict is
     * passed through verbatim, as every turn event's is.
     */
    public function preCompact(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::PreCompact->value, $context);
    }

    /**
     * Post-compaction hook execution (roadmap 2.12): `toolInput` is JSON
     * `{"trigger": …, "compact_summary": "<the state summary the model now reads>"}`.
     * Observe-only — the compaction has already been applied, so the caller
     * reports a refusal and changes nothing.
     */
    public function postCompact(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::PostCompact->value, $context);
    }

    /**
     * True when at least one enabled hook that would run for $event against
     * $matchSubject executes OUT OF PROCESS ({@see BoundedHookInterface}, i.e. a
     * {@see ScriptHook}) — the hooks whose run is a blocking `proc_open()` drain
     * of up to {@see ScriptHook::DEFAULT_TIMEOUT_SECONDS}.
     *
     * WHY A QUERY RATHER THAN AN ASYNC TWIN OF {@see userPromptSubmit()}: the
     * caller that needs it ({@see \SugarCraft\Crush\Chat}, audit 15b-04) runs
     * the chain in a forked child so the TUI keeps painting while a script
     * hook works, and a fork is only the right trade when something in the
     * chain actually leaves the process. A chain of hand-written PHP hooks runs
     * in-process exactly as before — forking it would run each hook in a copy
     * of memory and silently drop whatever state it keeps — so the caller needs
     * to ask first, and the answer belongs here beside the registry it reads.
     *
     * Read through {@see HookRegistry::findMatches()}, the same selection
     * {@see HookRegistry::executeHooks()} runs, so "would fork" and "would run"
     * cannot disagree about a disabled hook or a matcher.
     */
    public function runsOutOfProcess(HookEvent $event, string $matchSubject): bool
    {
        foreach ($this->registry->findMatches($event->value, $matchSubject) as $hook) {
            if ($hook instanceof BoundedHookInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when at least one enabled hook would run for $event against
     * $matchSubject — the same {@see HookRegistry::findMatches()} selection
     * {@see runsOutOfProcess()} reads. A caller that must take a slower route
     * only when something is actually wired ({@see \SugarCraft\Crush\Chat}'s
     * compaction gate, roadmap 2.12) asks this first, so an unhooked session's
     * path is unchanged.
     */
    public function hasHooksFor(HookEvent $event, string $matchSubject): bool
    {
        return $this->registry->findMatches($event->value, $matchSubject) !== [];
    }

    /**
     * Apply hooks to a tool call input.
     */
    public function applyPreHooks(
        string $toolName,
        string $input,
        HookContext $baseContext,
    ): HookResult {
        $context = $baseContext->withToolInput($input);
        return $this->preToolUse($context);
    }
}
