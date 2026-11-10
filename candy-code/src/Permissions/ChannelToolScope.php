<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

/**
 * A resolved per-channel tool policy for ONE session — the D6 scope dimension.
 *
 * OpenClaw resolves `groups.resolveToolPolicy` at execution admission: the
 * conversation a request came from carries a tool allow/deny list, and the
 * tool layer refuses calls that list bars BEFORE any ordinary permission
 * reasoning speaks (openclaw research §4; code-chat.md §9.3 names this gate as
 * candy-code's shape, riding the rule chain rather than a second engine).
 * This value object is that resolved answer for one session: the effective
 * name-level allow list, alsoAllow additions, and deny list for whichever
 * level — sender, group, or channel — the resolver landed on, already merged
 * with the levels it inherits from.
 *
 * THE SCOPE ONLY EVER RESTRICTS. It has no voice that can ALLOW a call past
 * anything: a name its allow list carries simply produces no verdict, so the
 * call falls through to the configured rules and the mode exactly as it would
 * have without the scope. A name on its deny list — or missing from a live
 * allow list — refuses. That asymmetry is the security property: the channel
 * dimension composes with {@see PermissionGate} by taking capability away and
 * never granting it, so `bypassPermissions` inside a locked-down group is
 * still locked down (an operator channel policy is not a question the session
 * mode gets to re-answer).
 *
 * MATCHING IS BY TOOL NAME, not by `Name(arguments)` spelling: the openclaw
 * group lists carry names, and argument-scoped policy already lives in the
 * configured rule chain beneath this arm. A scope entry with parentheses is
 * the store's problem to reject — see {@see \SugarCraft\Code\Channels\ChannelToolPolicyService}.
 *
 * A MALFORMED policy resolves to {@see refusingAll()} — fail CLOSED. Broken
 * configuration must never silently UNRESTRICT a channel, which is the same
 * law that makes an empty allowlist block everything rather than allow it.
 */
final class ChannelToolScope
{
    /**
     * @param list<string> $allow       name patterns; empty = NO allowlist (deny-only scope)
     * @param list<string> $alsoAllow   additions consulted only when $allow is live
     * @param list<string> $deny        name patterns refused at this scope
     * @param bool         $blocksAll   the fail-closed shape: refuse every name
     * @param string       $brokenWhy   what was wrong with the stored policy, when it is
     */
    private function __construct(
        public readonly string $label,
        public readonly string $scopeKind,
        private readonly array $allow = [],
        private readonly array $alsoAllow = [],
        private readonly array $deny = [],
        private readonly bool $blocksAll = false,
        private readonly string $brokenWhy = '',
    ) {}

    /**
     * A resolved scope for the named level.
     *
     * @param list<string> $allow
     * @param list<string> $alsoAllow
     * @param list<string> $deny
     *
     * @throws \InvalidArgumentException on an entry that is not a non-empty
     *         trimmed string — the resolver screens spelling first, so a string
     *         reaching here still unparsed is a caller bug, not user input
     */
    public static function of(string $label, string $scopeKind, array $allow, array $alsoAllow, array $deny): self
    {
        foreach ([$allow, $alsoAllow, $deny] as $list) {
            foreach ($list as $entry) {
                if (!\is_string($entry) || $entry === '' || \trim($entry) !== $entry) {
                    throw new \InvalidArgumentException('A channel tool policy entry must be a non-empty trimmed string.');
                }
            }
        }

        return new self($label, $scopeKind, array_values($allow), array_values($alsoAllow), array_values($deny));
    }

    /**
     * The fail-closed scope: the stored policy was unreadable or malformed,
     * so every tool is refused until an operator repairs it.
     */
    public static function refusingAll(string $label, string $scopeKind, string $why): self
    {
        return new self($label, $scopeKind, blocksAll: true, brokenWhy: $why);
    }

    /**
     * The scope's verdict for a tool name, or null when the scope has no
     * objection — which is NOT an allow: the caller keeps evaluating.
     */
    public function verdict(string $toolName): ?string
    {
        if ($this->blocksAll) {
            return "the stored {$this->scopeKind} policy could not be honoured ({$this->brokenWhy}), and a broken policy refuses every tool";
        }

        foreach ($this->deny as $pattern) {
            if (PermissionRule::matchesToolName($pattern, $toolName)) {
                return "denied by the {$this->label} tool policy";
            }
        }

        // An empty allow list is no allowlist at all: a deny-only scope has no
        // opinion about names its deny does not carry. Only a LIVE list bars
        // what is missing from it (openclaw allowlist semantics).
        if ($this->allow === []) {
            return null;
        }

        foreach ([...$this->allow, ...$this->alsoAllow] as $pattern) {
            if (PermissionRule::matchesToolName($pattern, $toolName)) {
                return null;
            }
        }

        return "not in the {$this->label} tool allowlist";
    }

    /** Human-naming of the level this scope resolved to, for refusal text. */
    public function label(): string
    {
        return $this->label;
    }

    /** 'sender' | 'group' | 'channel' — which level the resolver landed on. */
    public function scopeKind(): string
    {
        return $this->scopeKind;
    }

    /** @return list<string> */
    public function allowPatterns(): array
    {
        return $this->allow;
    }

    /** @return list<string> */
    public function alsoAllowPatterns(): array
    {
        return $this->alsoAllow;
    }

    /** @return list<string> */
    public function denyPatterns(): array
    {
        return $this->deny;
    }

    /** Whether this is the fail-closed shape. */
    public function blocksEverything(): bool
    {
        return $this->blocksAll;
    }
}
