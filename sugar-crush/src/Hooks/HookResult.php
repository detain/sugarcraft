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

    public function __construct(
        public string $action,
        public string $message,
        public ?string $modifiedInput = null,
    ) {}

    public static function allow(string $message = ''): self
    {
        return new self(self::ALLOW, $message);
    }

    public static function deny(string $message): self
    {
        return new self(self::DENY, $message);
    }

    public static function modify(string $newInput, string $message = ''): self
    {
        return new self(self::MODIFY, $message, $newInput);
    }

    /**
     * Defer the decision to the user.
     *
     * @param string $message the question to put to the user (rendered as the
     *                        permission prompt's body)
     */
    public static function ask(string $message): self
    {
        return new self(self::ASK, $message);
    }

    public function isAllowed(): bool
    {
        return $this->action === self::ALLOW;
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
