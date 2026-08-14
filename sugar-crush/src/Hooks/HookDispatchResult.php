<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

/**
 * Result of dispatching an event to all registered hooks.
 *
 * Represents the aggregated outcome across all hooks for a single event.
 * The exit code semantics (consistent across all events):
 * - 0 (allow): action proceeds
 * - 1 (deny): non-blocking deny — stderr shown, execution continues
 * - 2 (block): hard block — effect depends on event type via continueOnBlock flag
 *
 * A result also carries the CALL it settled on — `$context`, and `$modifiedInput`
 * when a `PreToolUse` hook rewrote it. Both used to be dropped on the floor:
 * every factory took a {@see HookContext} and ignored it, and no factory ever
 * populated `$modifiedInput`, so {@see HookDispatcher::dispatch()} could
 * re-scan a rewrite, agree with it, and then hand its caller an ALLOW that
 * described the arguments the rewrite REPLACED. A sanitizing hook rewriting
 * `rm -rf /` into `rm -rf ./build` was silently defeated on that path, since
 * MODIFY is the documented way to say "allowed, with rewritten input".
 *
 * @see HookEvent for event-specific block semantics
 */
final readonly class HookDispatchResult
{
    /**
     * Exit codes:
     * 0 = allowed, 1 = non-blocking deny, 2 = hard block
     */
    public const EXIT_ALLOW = 0;
    public const EXIT_DENY = 1;
    public const EXIT_BLOCK = 2;

    /**
     * @param HookContext   $context       the call this result settled on — on an ALLOW
     *                                     after a rewrite, the REWRITTEN one
     * @param ?string       $modifiedInput the rewriting hook's own JSON text for those
     *                                     arguments, kept verbatim so what a consumer
     *                                     decodes is byte-identical to what the hook
     *                                     emitted; null when nothing rewrote the call.
     *                                     Mirrors {@see HookResult::$modifiedInput}, so
     *                                     the consumers that already know how to apply
     *                                     one need no second convention
     */
    private function __construct(
        public int $exitCode,
        public HookEvent $event,
        public string $message,
        public bool $continueOnBlock,
        public HookContext $context,
        public ?string $modifiedInput = null,
    ) {}

    /**
     * Action was allowed by all hooks (or no hooks were registered), with the
     * arguments $context describes.
     */
    public static function allow(HookEvent $event, HookContext $context, string $message = ''): self
    {
        return new self(
            exitCode: self::EXIT_ALLOW,
            event: $event,
            message: $message,
            continueOnBlock: false,
            context: $context,
        );
    }

    /**
     * Allowed, and a `PreToolUse` hook REWROTE the arguments: $rewritten is
     * the call that should actually run, not the one the model proposed.
     *
     * Separate from {@see allow()} rather than a flag on it because the two
     * are different instructions to the caller — "run what you already have"
     * versus "run this instead" — and the plain factory silently meaning the
     * first is what made the dispatcher's re-scan loop pointless.
     */
    public static function allowRewritten(HookEvent $event, HookContext $rewritten, string $message = ''): self
    {
        return new self(
            exitCode: self::EXIT_ALLOW,
            event: $event,
            message: $message,
            continueOnBlock: false,
            context: $rewritten,
            modifiedInput: $rewritten->toolInput,
        );
    }

    /**
     * Action was denied by a hook (non-blocking).
     * Stderr is shown to user but execution continues.
     *
     * No `modifiedInput`, here or on {@see block()}: a call that was not
     * permitted has no arguments to run, so reporting a rewrite for it would
     * only invite a caller to run the very thing that was just refused.
     */
    public static function deny(HookEvent $event, HookContext $context, string $message): self
    {
        return new self(
            exitCode: self::EXIT_DENY,
            event: $event,
            message: $message,
            continueOnBlock: false,
            context: $context,
        );
    }

    /**
     * Action was blocked by a hook (hard block).
     *
     * @param bool $continueOnBlock If true, the action already happened but we're
     *                              flagging a problem via continueOnBlock semantics.
     *                              If false, the action is stopped outright.
     */
    public static function block(HookEvent $event, HookContext $context, string $message, bool $continueOnBlock = false): self
    {
        return new self(
            exitCode: self::EXIT_BLOCK,
            event: $event,
            message: $message,
            continueOnBlock: $continueOnBlock,
            context: $context,
        );
    }

    /**
     * The argument map this result says should actually run, or null when no
     * hook rewrote the call (in which case the caller's own arguments stand).
     *
     * Read off `$context` rather than re-decoded from `$modifiedInput`: the
     * context is what the re-scan actually judged, so a consumer that decoded
     * the text itself could reach a different answer from the one the hook
     * chain approved. Nothing undecodable ever gets this far —
     * {@see HookDispatcher::rewrite()} discards those with
     * {@see HookResult::rewrittenArgs()} before a rewrite can settle.
     *
     * @return array<string, mixed>|null
     */
    public function rewrittenArgs(): ?array
    {
        return $this->modifiedInput === null ? null : $this->context->toolArgs;
    }

    /**
     * Returns true if the action is allowed to proceed.
     */
    public function isAllowed(): bool
    {
        return $this->exitCode === self::EXIT_ALLOW;
    }

    /**
     * Returns true if this is a non-blocking deny (exit code 1).
     */
    public function isDeny(): bool
    {
        return $this->exitCode === self::EXIT_DENY;
    }

    /**
     * Returns true if this is a hard block (exit code 2).
     */
    public function isBlock(): bool
    {
        return $this->exitCode === self::EXIT_BLOCK;
    }

    /**
     * Returns true if the block uses continueOnBlock semantics
     * (action happened, surface error without discarding result).
     */
    public function shouldContinueOnBlock(): bool
    {
        return $this->isBlock() && $this->continueOnBlock;
    }
}
