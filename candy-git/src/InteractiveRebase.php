<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Interactive rebase todo state — a READ-ONLY preview list built from the
 * recent commits. Action cycling and dropping were preview theatre
 * (candy-git-011): the todo is discarded on Esc and nothing is ever executed.
 * Parked per gate G1 (2026-11-14): the App refuses the mutating keys with the
 * preview-only notice; the model methods below stay as the building blocks for
 * the wire arm (temp todo + GIT_SEQUENCE_EDITOR) should the owner commission it.
 * Wiring it needs a GitDriver execution method and an Enter arm; the completion,
 * error and cancel plumbing that anticipated them is deleted as speculation
 * (candy-git-029 part 3).
 *
 * Press 'i' to open, choose a commit count, read the todo, leave with Esc.
 *
 * @readonly
 *
 * Mirrors jesseduffield/lazygit commit-panel interactive rebase todo — action cycling (execution deferred). */
final readonly class InteractiveRebase
{
    /**
     * @param list<RebaseCommit> $commits   The todo list commits
     * @param int               $cursor      Current cursor
     * @param bool             $selectingN  Whether we're selecting number of commits
     * @param string           $countInput  The accumulated count being typed
     * @param string|null       $error       Error message if any
     */
    public function __construct(
        public array $commits,
        public int $cursor = 0,
        public bool $selectingN = true,
        public string $countInput = '',
        public ?string $error = null,
    ) {}

    /**
     * Build the todo list from recent commits.
     *
     * @param list<array{sha:string,subject:string}> $commits
     * @return list<RebaseCommit>
     */
    public static function buildFromLog(array $commits): array
    {
        $result = [];
        foreach ($commits as $c) {
            $result[] = new RebaseCommit(
                sha: $c['sha'] ?? '',
                subject: $c['subject'] ?? '',
                action: RebaseAction::Pick,
            );
        }
        return $result;
    }

    /**
     * Start interactive rebase by selecting number of commits.
     */
    public static function selectingN(): self
    {
        return new self(commits: [], cursor: 0, selectingN: true, countInput: '');
    }

    /**
     * Return a new InteractiveRebase with an added digit to the count.
     */
    public function withCountDigit(string $digit): self
    {
        if (!ctype_digit($digit)) return $this;
        return new self(
            commits: $this->commits,
            cursor: $this->cursor,
            selectingN: true,
            countInput: $this->countInput . $digit,
            error: null,
        );
    }

    /**
     * Finalize count selection and build the todo list from log.
     *
     * @param list<array{sha:string,subject:string}> $logCommits
     */
    public function confirmCount(array $logCommits): self
    {
        $n = (int) $this->countInput;
        if ($n <= 0) {
            return new self(commits: [], cursor: 0, selectingN: true, countInput: '', error: Lang::t('rebase_i.count_positive'));
        }
        $commits = array_slice($logCommits, 0, $n);
        return new self(
            commits: self::buildFromLog($commits),
            cursor: 0,
            selectingN: false,
            countInput: '',
            error: null,
        );
    }

    /**
     * Move cursor up/down through the commit list.
     */
    public function withCursor(int $dir): self
    {
        $count = count($this->commits);
        if ($count === 0) return $this;
        $newCursor = max(0, min($count - 1, $this->cursor + $dir));
        return new self(commits: $this->commits, cursor: $newCursor, selectingN: false, countInput: '', error: $this->error);
    }

    /**
     * Cycle the action for the current commit.
     *
     * Model API only while parked (gate G1): the App's space/l keys answer
     * with the preview-only refusal instead of calling this.
     */
    public function cycleAction(): self
    {
        if ($this->selectingN === true || $this->commits === []) return $this;
        $current = $this->commits[$this->cursor] ?? null;
        if ($current === null) return $this;

        $nextAction = match ($current->action) {
            RebaseAction::Pick   => RebaseAction::Reword,
            RebaseAction::Reword => RebaseAction::Edit,
            RebaseAction::Edit   => RebaseAction::Squash,
            RebaseAction::Squash => RebaseAction::Drop,
            RebaseAction::Drop   => RebaseAction::Pick,
        };

        $newCommits = $this->commits;
        $newCommits[$this->cursor] = $current->withAction($nextAction);

        return new self(commits: $newCommits, cursor: $this->cursor, selectingN: false, countInput: '', error: $this->error);
    }

    /**
     * Remove (drop) the current commit from the list.
     */
    public function dropCurrent(): self
    {
        if ($this->selectingN || $this->commits === []) return $this;
        $newCommits = $this->commits;
        array_splice($newCommits, $this->cursor, 1);
        $newCursor = min($this->cursor, max(0, count($newCommits) - 1));
        return new self(commits: $newCommits, cursor: $newCursor, selectingN: false, countInput: '', error: $this->error);
    }

}
