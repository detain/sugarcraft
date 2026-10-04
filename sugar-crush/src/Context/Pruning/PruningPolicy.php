<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * The limits every {@see PruningStrategy} works inside (roadmap 2.2-1, DCP
 * §13.2 D): which tool outputs are never pruned, and the age rule's three
 * figures — opencode's `PRUNE_PROTECT` / `PRUNE_MINIMUM` and its two-turn
 * guard.
 *
 * PROTECTED TOOLS. `Task` and `Skill` results are what the model cannot get
 * back by re-running a cheap call: a delegated run's report is the only record
 * of minutes of sub-agent work, and a skill body is the instruction the model
 * is following. `Edit` and `Write` answer with a one-line receipt, so pruning
 * them would save nothing and lose the record of a change.
 *
 * THE FIGURES. The newest {@see PROTECT_TOKENS} of tool output and everything
 * from the {@see PROTECT_USER_TURNS}th-last user prompt on stay verbatim; older
 * output is pruned only when that frees at least {@see MIN_FREED_TOKENS}. The
 * floor is the batching rule: a prune rewrites bytes the provider has cached,
 * so it happens rarely and in bulk rather than a few rows per step (Cline's
 * "batch the rewrite" lesson; 20k estimated tokens is well past its 64 KB).
 */
final readonly class PruningPolicy
{
    /** @var list<string> */
    public const PROTECTED_TOOLS = ['Task', 'Skill', 'Edit', 'Write'];

    /** Newest tool output, in estimated tokens, the age rule never prunes. */
    public const PROTECT_TOKENS = 40_000;

    /** User turns, counted back from the newest, the age rule never prunes. */
    public const PROTECT_USER_TURNS = 2;

    /** A prune that frees less than this, in estimated tokens, is not made. */
    public const MIN_FREED_TOKENS = 20_000;

    /**
     * @param list<string> $protectedTools tool names whose output is never pruned
     */
    private function __construct(
        public array $protectedTools,
        public int $protectTokens,
        public int $protectUserTurns,
        public int $minFreedTokens,
    ) {
    }

    public static function new(): self
    {
        return new self(self::PROTECTED_TOOLS, self::PROTECT_TOKENS, self::PROTECT_USER_TURNS, self::MIN_FREED_TOKENS);
    }

    /** @param list<string> $tools */
    public function withProtectedTools(array $tools): self
    {
        return $this->mutate(protectedTools: array_values($tools));
    }

    public function withProtectTokens(int $tokens): self
    {
        return $this->mutate(protectTokens: max(0, $tokens));
    }

    public function withProtectUserTurns(int $turns): self
    {
        return $this->mutate(protectUserTurns: max(0, $turns));
    }

    public function withMinFreedTokens(int $tokens): self
    {
        return $this->mutate(minFreedTokens: max(0, $tokens));
    }

    /** Whether $tool's output must never be pruned. */
    public function isProtected(string $tool): bool
    {
        return in_array($tool, $this->protectedTools, true);
    }

    private function mutate(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
