<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

/**
 * One hook's decision about a pending tool call.
 *
 * Four actions: ALLOW, DENY, MODIFY and ASK. ALLOW/DENY/MODIFY settle on
 * their own; ASK does not — it is the opencode-style fallback where the hook
 * has no verdict and defers to the user, so the call may neither run nor be
 * reported as denied until an answer arrives (see
 * {@see HookManager::resolveAsk()}).
 *
 * Gate execution on {@see self::permitsExecution()}, never on `!isDenied()`:
 * an ASK — or a result carrying an action this class does not recognise — is
 * not permission, and reading "not explicitly denied" as permission is how a
 * hook gate silently fails open.
 *
 * THREE PAYLOAD CHANNELS, deliberately separate — conflating them is the bug
 * this shape exists to prevent:
 *   - {@see self::$message}          the HUMAN/deny reason. Both gates
 *     ({@see \SugarCraft\Crush\Runtime::gate()} and
 *     {@see \SugarCraft\Crush\Chat::gateToolCall()}) interpolate it only into
 *     a "Hook denied: …" string, so a value placed here on an ALLOW path is
 *     invisible to the model.
 *   - {@see self::$modifiedInput}    JSON TOOL ARGUMENTS (see
 *     {@see rewrittenArgs()}) — a rewrite the chain may run, never prose.
 *   - {@see self::$additionalContext} a MODEL-VISIBLE note appended to the
 *     tool result on both live tool-result paths
 *     ({@see \SugarCraft\Crush\Runtime::settle()} and
 *     {@see \SugarCraft\Crush\Chat::applyPostToolUse()}). This is where a
 *     permitting {@see ScriptHook}'s stdout now survives: previously
 *     {@see HookRegistry::executeHooks()} rebuilt a permitting verdict with an
 *     empty message and the output went nowhere.
 *
 * {@see self::$additionalContext} is capped at
 * {@see self::MAX_ADDITIONAL_CONTEXT_BYTES} bytes by its producer; overflow
 * spills to a retained file whose path is included in the context (see
 * {@see \SugarCraft\Crush\Support\HookContextFiles}).
 */
final readonly class HookResult
{
    public const ALLOW = 'allow';
    public const DENY = 'deny';
    public const MODIFY = 'modify';

    /**
     * The hook defers to the user: block the call and prompt, do not run it.
     */
    public const ASK = 'ask';

    /**
     * Byte ceiling on {@see self::$additionalContext}.
     *
     * BYTES, not characters: the plan step's original wording was "10,000
     * characters", but this whole subsystem is byte-denominated —
     * {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput} caps in bytes
     * (65536/16384/120) and {@see ScriptHook::clip()} cuts on byte boundaries
     * on purpose ("this is arbitrary program output, not guaranteed UTF-8").
     * A character cap over such output would let a multi-byte payload exceed
     * the bound it claims to impose. Producers bound the field with
     * {@see \SugarCraft\Crush\Support\HookContextFiles::bound()} (which uses
     * `mb_strcut(..., 'UTF-8')`, the TruncatesOutput idiom) so the value never
     * splits a codepoint and never exceeds this many bytes.
     */
    public const MAX_ADDITIONAL_CONTEXT_BYTES = 10000;

    /**
     * How much of a hook's name {@see self::withheldNotice()} shows.
     */
    public const MAX_NAMED_HOOK_CHARS = 60;

    public function __construct(
        public string $action,
        public string $message,
        public ?string $modifiedInput = null,
        public string $additionalContext = '',
        /**
         * The names of the hooks that ASKED in the chain this verdict settles
         * (audit F-P7/F-P9), in the order they asked, each once.
         *
         * Stamped by {@see HookRegistry::executeHooks()} on the ASK it
         * rebuilds — never taken from a hook: the registry overwrites whatever
         * a hook's own result carried, so a user hook cannot pass its question
         * off as {@see BuiltIn\PermissionGateHook}'s, and the gate's name is
         * reserved ({@see HookRegistry::isReserved()}) so no config hook can
         * be registered under it either. Empty on every non-ASK verdict.
         *
         * WHY IT EXISTS: a remembered approval (Runtime's per-turn Task memo,
         * Chat's "Always" grant) answers a question the user already answered.
         * That is true only of the gate's own policy question, which is the
         * same question for the same call. A user hook's ask ("confirm before
         * touching prod") is a question about THIS call's content and must be
         * put every time, so both memos consult {@see askedOnlyBy()} first.
         *
         * @var list<string>
         */
        public array $askedBy = [],
        /**
         * The name of the hook whose result REFUSED in the chain this verdict
         * settles (audit R6), or null when no single hook did.
         *
         * Stamped by {@see HookRegistry::executeHooks()} on the DENY (or
         * unrecognised action) it returns, from the hook it actually ran —
         * never taken from a hook's own result, which the registry
         * overwrites, so a hook cannot pass its refusal off as another's.
         * Null on every permitting verdict, on an ASK (whose askers are
         * {@see self::$askedBy}), and on a refusal the REGISTRY made itself:
         * a chain that ran out of clock or kept rewriting has no single hook
         * to blame, and a guessed name would be a lie on the line someone
         * reads when deciding which hook to fix.
         *
         * WHY IT EXISTS: a `PostToolUse` refusal withholds output the model
         * never sees again, and before this field the text that replaced it
         * ({@see self::withheldNotice()}) could not say which of several
         * configured hooks did it. Read through {@see self::refusingHook()}.
         */
        public ?string $refusedBy = null,
    ) {}

    public static function allow(string $message = '', string $additionalContext = ''): self
    {
        return new self(self::ALLOW, $message, null, $additionalContext);
    }

    public static function deny(string $message, string $additionalContext = ''): self
    {
        return new self(self::DENY, $message, null, $additionalContext);
    }

    public static function modify(string $newInput, string $message = '', string $additionalContext = ''): self
    {
        return new self(self::MODIFY, $message, $newInput, $additionalContext);
    }

    /**
     * Defer the decision to the user.
     *
     * @param string $message the question to put to the user (rendered as the
     *                        permission prompt's body)
     * @param string|null $modifiedInput arguments an earlier MODIFY in the
     *        same chain already rewrote, carried through the question so that
     *        an approval runs the REWRITTEN call rather than silently falling
     *        back to the originals the rewrite existed to replace. Only
     *        {@see HookRegistry::executeHooks()} usefully sets it; see
     *        {@see HookManager::resolveAsk()} for where it is picked up again.
     *
     *        A HOOK MAY PASS ONE, and it is treated as a PROPOSAL rather than
     *        honoured: {@see HookRegistry::executeHooks()} re-scans it exactly
     *        as it re-scans a MODIFY, and REBUILDS the ASK it finally returns
     *        so that the only rewrite leaving the loop is one the whole chain
     *        settled on. Honouring a hook's own directly dispatched arguments
     *        no guard behind it had seen — `resolveAsk()` turns an approval
     *        into precisely the MODIFY that runs them — which is why the loop
     *        enforces this rather than trusting the caller.
     * @param string $additionalContext a model-visible note the chain collected
     *        before the question was raised; {@see HookManager::resolveAsk()}
     *        threads it through whichever verdict the answer settles to, so an
     *        approved ASK does not drop it.
     */
    public static function ask(string $message, ?string $modifiedInput = null, string $additionalContext = ''): self
    {
        return new self(self::ASK, $message, $modifiedInput, $additionalContext);
    }

    /**
     * The rewritten ARGUMENT MAP this result carries, or null when it carries
     * nothing usable as one.
     *
     * The `{` test is made on the JSON TEXT because `json_decode()` throws the
     * distinction away: `{}` and `[]` both decode to `[]`, so `is_array()`
     * alone accepted a top-level JSON LIST — `["rm","-rf","/"]` — as an
     * argument map. That set `toolArgs` to a positional list in which no
     * guard's `$args['command']` exists, so every argument-reading hook
     * (including {@see BuiltIn\PermissionGateHook}) went quiet on a call it
     * could no longer see. {@see ScriptHook::modifyOrDeny()} and
     * {@see \SugarCraft\Crush\Cli\Bootstrap::permissionConfig()} already drew
     * the same distinction on the same evidence; this is the one place EVERY
     * consumer of a rewrite now shares it —
     * {@see \SugarCraft\Crush\Runtime::rewrittenArguments()},
     * {@see \SugarCraft\Crush\Runtime::asAsked()},
     * {@see \SugarCraft\Crush\Chat::applyRewrite()},
     * {@see HookDispatcher::rewrite()} and {@see HookRegistry::executeHooks()}.
     *
     * Deliberately NOT gated on {@see isModified()}: an ASK carries the
     * rewrite an earlier MODIFY in the same chain made (see
     * {@see HookRegistry::executeHooks()}), and that rewrite is exactly what
     * an approval has to dispatch. That is the whole widening — the callers
     * gate on `isModified() || isAsk()`, so an ALLOW hand-built with a
     * `modifiedInput` still carries nothing anybody will run, because nothing
     * re-scanned it.
     *
     * @return array<string, mixed>|null
     */
    public function rewrittenArgs(): ?array
    {
        if ($this->modifiedInput === null) {
            return null;
        }

        $decoded = json_decode($this->modifiedInput, true);

        return is_array($decoded) && str_starts_with(ltrim($this->modifiedInput), '{')
            ? $decoded
            : null;
    }

    public function isAllowed(): bool
    {
        return $this->action === self::ALLOW;
    }

    /**
     * A copy whose {@see self::$additionalContext} IS $context (verbatim), keeping
     * action/message/modifiedInput.
     *
     * The settled verdict {@see HookRegistry::executeHooks()} returns takes its
     * context from a CHAIN-WIDE collection ({@see HookRegistry::scan()}'s 4th
     * slot), not from the one result the verdict happens to be built on — a
     * returned MODIFY's own note is already inside that collection, so APPENDING
     * it here would duplicate a hook's stdout into the model's view. SET (this
     * method), not append, is what keeps "one permitting hook printed N bytes" to
     * exactly N bytes of preview. No-op (returns `$this`) when the value already
     * matches, so the empty-context path stays byte-identical — which is the
     * no-op-when-unused guarantee the consumer tests assert.
     *
     * The producer bounds $context at {@see self::MAX_ADDITIONAL_CONTEXT_BYTES}
     * (see {@see \SugarCraft\Crush\Support\HookContextFiles::bound()}); this method
     * does not re-bound, it only relocates an already-bounded value onto the
     * settled result.
     */
    public function withContextSet(string $context): self
    {
        if ($context === $this->additionalContext) {
            return $this;
        }

        return new self(
            $this->action,
            $this->message,
            $this->modifiedInput,
            $context,
            $this->askedBy,
            $this->refusedBy,
        );
    }

    /**
     * A copy whose {@see self::$askedBy} IS $names (deduplicated, order kept).
     *
     * Only {@see HookRegistry::executeHooks()} should call this, on the ASK it
     * rebuilds from what the chain actually did; see the property's note.
     *
     * @param list<string> $names
     */
    public function withAskedBy(array $names): self
    {
        return new self(
            $this->action,
            $this->message,
            $this->modifiedInput,
            $this->additionalContext,
            array_values(array_unique($names)),
            $this->refusedBy,
        );
    }

    /**
     * A copy whose {@see self::$refusedBy} IS $name.
     *
     * Only {@see HookRegistry::executeHooks()} should call this, on the
     * refusal it returns, with the name of the hook that produced it; see the
     * property's note.
     */
    public function withRefusedBy(?string $name): self
    {
        if ($name === $this->refusedBy) {
            return $this;
        }

        return new self(
            $this->action,
            $this->message,
            $this->modifiedInput,
            $this->additionalContext,
            $this->askedBy,
            $name,
        );
    }

    /**
     * The hook that kept this verdict from permitting the call, or null when
     * it permits or no single hook is known to have refused.
     *
     * A refusal names its {@see self::$refusedBy}; an ASK names the hook whose
     * question it carries — the FIRST asker, since that is the question the
     * chain put ({@see HookRegistry::scan()} keeps the first ASK). Never read
     * on a permitting verdict, so a hand-built ALLOW carrying either field
     * names nothing.
     */
    public function refusingHook(): ?string
    {
        if ($this->permitsExecution()) {
            return null;
        }

        return $this->isAsk() ? ($this->askedBy[0] ?? null) : $this->refusedBy;
    }

    /**
     * The text that replaces a tool's output when a `PostToolUse` chain does
     * not permit it (audit F-H1, R6): one sentence both tool paths —
     * {@see \SugarCraft\Crush\Runtime::settle()} and
     * {@see \SugarCraft\Crush\Chat::applyPostToolUse()} — use, so a
     * transcript reads the same whichever pipeline ran the call.
     *
     *     [output withheld by PostToolUse hook "<name>": <reason>] The call ran; its output is not shown.
     *     [output withheld by the PostToolUse hook chain: <reason>] The call ran; its output is not shown.
     *
     * The second shape is for a refusal no single hook owns (see
     * {@see self::$refusedBy}) and for a chain that threw before it could
     * name anyone. The hook name comes from configuration — a YAML entry
     * without `name:` is named after its whole `command` — so it is clipped
     * to {@see self::MAX_NAMED_HOOK_CHARS} and has its control characters
     * replaced: it is model-visible text, and a command line is the likeliest
     * place for a token to have been pasted.
     */
    public static function withheldNotice(?string $hook, string $reason): string
    {
        $reason = trim($reason);
        $by = $hook === null
            ? 'the PostToolUse hook chain'
            : 'PostToolUse hook "' . self::clipHookName($hook) . '"';

        return sprintf(
            '[output withheld by %s: %s] The call ran; its output is not shown.',
            $by,
            $reason === '' ? 'no reason given' : $reason,
        );
    }

    /**
     * The reason {@see self::withheldNotice()} gives when the chain THREW
     * instead of answering (audit R6): a crashed hook has vetted nothing, so
     * the output it was reading is withheld exactly as a refusal's is.
     */
    public static function failureReason(\Throwable $e): string
    {
        return sprintf('hook failed: %s: %s', $e::class, $e->getMessage());
    }

    private static function clipHookName(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', mb_scrub($name, 'UTF-8'));

        return mb_strlen($name) <= self::MAX_NAMED_HOOK_CHARS
            ? $name
            : mb_substr($name, 0, self::MAX_NAMED_HOOK_CHARS) . '…';
    }

    /**
     * True when this is an ASK and $hookName is the ONLY hook in the chain
     * that asked.
     *
     * The test a remembered approval has to pass before it may answer this
     * question without the user (F-P7/F-P9): an ASK with no recorded asker —
     * one a caller built by hand rather than one the registry settled — is
     * NOT treated as the gate's, so an unattributed question always reaches
     * the user.
     */
    public function askedOnlyBy(string $hookName): bool
    {
        return $this->isAsk() && $this->askedBy === [$hookName];
    }

    public function isDenied(): bool
    {
        return $this->action === self::DENY;
    }

    public function isModified(): bool
    {
        return $this->action === self::MODIFY;
    }

    /**
     * True when the decision is still waiting on a user answer.
     */
    public function isAsk(): bool
    {
        return $this->action === self::ASK;
    }

    /**
     * True only when this decision, on its own, permits the tool call to run.
     *
     * Deliberately an allow-list of the two permitting actions rather than a
     * deny-list: ASK and any unrecognised or malformed action must read as
     * "no permission granted" so a future action string cannot widen
     * permission just by not being DENY.
     */
    public function permitsExecution(): bool
    {
        return $this->action === self::ALLOW || $this->action === self::MODIFY;
    }
}
