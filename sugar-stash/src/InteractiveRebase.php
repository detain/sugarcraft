<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

/**
 * Represents a single action in an interactive rebase todo list.
 */
enum RebaseAction: string
{
    case Pick   = 'pick';
    case Reword = 'reword';
    case Edit   = 'edit';
    case Squash = 'squash';
    case Drop   = 'drop';
}

/**
 * A commit entry in the interactive rebase todo list.
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

    /**
     * Display line for the rebase todo list.
     */
    public function displayLine(): string
    {
        return $this->action->value . ' ' . $this->sha . ' ' . $this->subject;
    }
}

/**
 * Interactive rebase state — shows todo list and allows picking actions
 * per commit.
 *
 * Press 'i' to enter interactive rebase mode and select number of commits.
 * Then shows a list where you can change each commit's action.
 *
 * @readonly
 */
final readonly class InteractiveRebase
{
    /**
     * @param list<RebaseCommit> $commits   The todo list commits
     * @param int               $cursor      Current cursor
     * @param bool             $selectingN  Whether we're selecting number of commits
     * @param string           $countInput  The accumulated count being typed
     * @param bool             $done        Whether the rebase is complete
     * @param string|null       $error       Error message if any
     */
    public function __construct(
        public array $commits,
        public int $cursor = 0,
        public bool $selectingN = true,
        public string $countInput = '',
        public bool $done = false,
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
            done: false,
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
            return new self(commits: [], cursor: 0, selectingN: true, countInput: '', done: false, error: 'count must be > 0');
        }
        $commits = array_slice($logCommits, 0, $n);
        return new self(
            commits: self::buildFromLog($commits),
            cursor: 0,
            selectingN: false,
            countInput: '',
            done: false,
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
        return new self(commits: $this->commits, cursor: $newCursor, selectingN: false, countInput: '', done: $this->done, error: $this->error);
    }

    /**
     * Cycle the action for the current commit.
     */
    public function cycleAction(): self
    {
        if ($this->selectingN || $this->commits === []) return $this;
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

        return new self(commits: $newCommits, cursor: $this->cursor, selectingN: false, countInput: '', done: $this->done, error: $this->error);
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
        return new self(commits: $newCommits, cursor: $newCursor, selectingN: false, countInput: '', done: $this->done, error: $this->error);
    }

    /**
     * Mark rebase as complete.
     *
     * TODO: executeInteractiveRebase() — Interactive rebase execution is not yet
     * wired into App::update(). This sets done=true but no GitDriver method exists
     * to apply the built todo list. Full implementation requires:
     * - GitDriver::interactiveRebase(list<RebaseCommit> $commits): void
     * - Enter key binding in App.php to trigger execution when not selectingN
     * See: findings/plan_sugar-stash.md §4.1 (Option B)
     */
    public function markDone(): self
    {
        return new self(commits: $this->commits, cursor: $this->cursor, selectingN: false, countInput: '', done: true, error: $this->error);
    }

    /**
     * Mark an error.
     */
    public function withError(string $msg): self
    {
        return new self(commits: $this->commits, cursor: $this->cursor, selectingN: $this->selectingN, countInput: '', done: false, error: $msg);
    }

    /**
     * Cancel and exit.
     */
    public function cancel(): self
    {
        return new self(commits: [], cursor: 0, selectingN: true, countInput: '', done: false, error: null);
    }

    /**
     * Number of commits in the todo list.
     */
    public function count(): int
    {
        return count($this->commits);
    }
}
