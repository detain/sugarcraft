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

        return new self($this->action, $this->message, $this->modifiedInput, $context, $this->askedBy);
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
        );
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
