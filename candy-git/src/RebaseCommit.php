<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * A commit entry in the interactive rebase todo list.
 *
 * Mirrors jesseduffield/lazygit rebase todo line — a commit carrying its
 * current action label (preview today; execution parked behind gate G1).
 */
final readonly class RebaseCommit
{
    /**
     * @param string       $sha     Short SHA
     * @param string       $subject Commit subject line
     * @param RebaseAction $action Current action for this commit
     */
    public function __construct(
        public readonly string $sha,
        public readonly string $subject,
        public readonly RebaseAction $action = RebaseAction::Pick,
    ) {}

    /**
     * Return a new RebaseCommit with a different action.
     */
    public function withAction(RebaseAction $action): self
    {
        return new self(sha: $this->sha, subject: $this->subject, action: $action);
    }
}
