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

    private function __construct(
        public int $exitCode,
        public HookEvent $event,
        public string $message,
        public bool $continueOnBlock,
        public ?string $modifiedInput = null,
    ) {}

    /**
     * Action was allowed by all hooks (or no hooks were registered).
     */
    public static function allow(HookEvent $event, HookContext $context, string $message = ''): self
    {
        return new self(
            exitCode: self::EXIT_ALLOW,
            event: $event,
            message: $message,
            continueOnBlock: false,
        );
    }

    /**
     * Action was denied by a hook (non-blocking).
     * Stderr is shown to user but execution continues.
     */
    public static function deny(HookEvent $event, HookContext $context, string $message): self
    {
        return new self(
            exitCode: self::EXIT_DENY,
            event: $event,
            message: $message,
            continueOnBlock: false,
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
        );
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
