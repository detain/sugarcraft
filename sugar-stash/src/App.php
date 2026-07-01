<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Clamp;

/**
 * Three-pane git TUI: status (left), branches (top right), log (bottom
 * right). Tab cycles focus; up/down moves the cursor in the active
 * pane; `s` stages / unstages the highlighted status entry; `a`
 * stages all files; Space (branches pane) checks out the selected
 * branch; `c` opens inline commit message collection; `?` shows the
 * help overlay; `R` refreshes from disk. `u` / Ctrl+r for undo/redo.
 */
final class App implements Model
{
    /**
     * @param list<array<string,mixed>> $status
     * @param list<array{name:string,sha:string,current:bool}> $branches
     * @param list<array{sha:string,subject:string,author:string,ago:string}> $log
     */
    public function __construct(
        public readonly GitDriver $git,
        public readonly array $status   = [],
        public readonly array $branches = [],
        public readonly array $log      = [],
        public readonly string $branchSummary = '',
        public readonly Pane $pane = Pane::Status,
        public readonly int $statusCursor   = 0,
        public readonly int $branchesCursor = 0,
        public readonly int $logCursor      = 0,
        public readonly ?string $error = null,
        /** Whether the help overlay is currently displayed. */
        public readonly bool $showHelp = false,
        /** Whether the app is collecting a commit message character-by-character. */
        public readonly bool $collectingCommit = false,
        /** The accumulated commit message while collectingCommit is true. */
        public readonly string $commitMessage = '',
        /** Diff viewer overlay (shown when 'd' is pressed on a status file). */
        public readonly ?DiffViewer $diffViewer = null,
        /** Whether the app is collecting a branch name character-by-character. */
        public readonly bool $collectingBranchName = false,
        /** The accumulated branch name while collectingBranchName is true. */
        public readonly string $branchName = '',
        /** Transient success message shown briefly after an action (e.g. hunk staged). */
        public readonly ?string $successMessage = null,
        /** Whether the app is collecting a merge target branch name. */
        public readonly bool $collectingMergeTarget = false,
        /** The accumulated merge target branch name. */
        public readonly string $mergeTarget = '',
        /** Whether the rebase menu overlay is shown. */
        public readonly bool $showRebaseMenu = false,
        /** Command history for undo/redo. */
        public readonly ?HistoryManager $history = null,
        /** Stash manager overlay (shown when 'S' is pressed). */
        public readonly ?StashManager $stashManager = null,
        /** Cherry-pick state (shown when 'V' is pressed). */
        public readonly ?CherryPick $cherryPick = null,
        /** Worktrees manager overlay (shown when 'w' is pressed in branches pane). */
        public readonly ?Worktrees $worktrees = null,
        /** Interactive rebase overlay (shown when 'i' is pressed). */
        public readonly ?InteractiveRebase $interactiveRebase = null,
    ) {}

    public static function start(GitDriver $git): self
    {
        return (new self($git, history: new HistoryManager()))->refresh();
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        // Escape / q / Ctrl+C always quits, even during commit/branch/merge collection
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q')
            || ($msg->ctrl && $msg->rune === 'c')) {
            if ($this->collectingCommit) {
                return [$this->withCommitCollection(false, ''), null];
            }
            if ($this->collectingBranchName) {
                return [$this->withBranchCollection(false, ''), null];
            }
            if ($this->collectingMergeTarget) {
                return [$this->withMergeCollection(false, ''), null];
            }
            if ($this->showHelp) {
                return [$this->withShowHelp(false), null];
            }
            if ($this->diffViewer !== null) {
                return [$this->withDiffViewer(null), null];
            }
            if ($this->showRebaseMenu) {
                return [$this->withRebaseMenu(false), null];
            }
            if ($this->stashManager !== null) {
                return [$this->withStashManager(null), null];
            }
            if ($this->cherryPick !== null) {
                return [$this->withCherryPick(null), null];
            }
            if ($this->worktrees !== null) {
                return [$this->withWorktrees(null), null];
            }
            if ($this->interactiveRebase !== null) {
                return [$this->withInteractiveRebase(null), null];
            }
            return [$this, Cmd::quit()];
        }

        // During commit message collection
        if ($this->collectingCommit) {
            if ($msg->type === KeyType::Enter) {
                return [$this->executeCommit(), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune !== '') {
                return [$this->withCommitMessage($this->commitMessage . $msg->rune), null];
            }
            return [$this, null];
        }

        // Stash manager overlay
        if ($this->stashManager !== null) {
            if ($msg->type === KeyType::Escape) {
                return [$this->withStashManager(null), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'a') {
                return [$this->executeStashApply(), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'd') {
                return [$this->executeStashDrop(), null];
            }
            if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
                return [$this->navigateStash(-1), null];
            }
            if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
                return [$this->navigateStash(+1), null];
            }
            return [$this, null];
        }

        // Cherry-pick mode
        if ($this->cherryPick !== null && $this->cherryPick->collecting) {
            if ($msg->type === KeyType::Escape) {
                return [$this->withCherryPick(null), null];
            }
            if ($msg->type === KeyType::Enter) {
                return [$this->executeCherryPick(), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune !== '') {
                return [$this->withCherryPick($this->cherryPick->withChar($msg->rune)), null];
            }
            return [$this, null];
        }

        // Worktrees overlay
        if ($this->worktrees !== null) {
            // When adding, only Escape (cancel), Enter (confirm), and Char (append to path) are processed
            if ($this->worktrees->adding) {
                if ($msg->type === KeyType::Escape) {
                    return [$this->withWorktrees($this->worktrees->cancelAdding()), null];
                }
                if ($msg->type === KeyType::Enter) {
                    return [$this->executeWorktreeAdd(), null];
                }
                if ($msg->type === KeyType::Char && $msg->rune !== '') {
                    return [$this->withWorktrees($this->worktrees->withNewPath($this->worktrees->newPath . $msg->rune)), null];
                }
                return [$this, null];
            }
            // When not adding
            if ($msg->type === KeyType::Escape) {
                return [$this->withWorktrees(null), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'a') {
                return [$this->withWorktrees($this->worktrees->startAdding()), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'd' && !$this->worktrees->removing) {
                return [$this->withWorktrees($this->worktrees->startRemoving()), null];
            }
            if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
                return [$this->navigateWorktree(-1), null];
            }
            if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
                return [$this->navigateWorktree(+1), null];
            }
            // Confirm remove worktree
            if ($msg->type === KeyType::Enter && $this->worktrees->removing) {
                return [$this->executeWorktreeRemove(), null];
            }
            // Cancel add/remove
            if ($msg->type === KeyType::Char && $msg->rune === 'c') {
                return [$this->withWorktrees($this->worktrees->cancelAdding()->cancelRemoving()), null];
            }
            return [$this, null];
        }

        // Interactive rebase overlay
        if ($this->interactiveRebase !== null) {
            if ($msg->type === KeyType::Escape) {
                return [$this->withInteractiveRebase(null), null];
            }
            // Selecting N: enter digit
            if ($this->interactiveRebase->selectingN) {
                if ($msg->type === KeyType::Enter) {
                    return [$this->confirmRebaseCount(), null];
                }
                if ($msg->type === KeyType::Char && ctype_digit($msg->rune)) {
                    return [$this->withInteractiveRebase($this->interactiveRebase->withCountDigit($msg->rune)), null];
                }
                return [$this, null];
            }
            // Navigate commits
            if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
                return [$this->navigateRebaseCommit(-1), null];
            }
            if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
                return [$this->navigateRebaseCommit(+1), null];
            }
            // Cycle action (space or right arrow)
            if ($msg->type === KeyType::Space || ($msg->type === KeyType::Char && $msg->rune === 'l')) {
                return [$this->withInteractiveRebase($this->interactiveRebase->cycleAction()), null];
            }
            // Drop current commit
            if ($msg->type === KeyType::Char && $msg->rune === 'd') {
                return [$this->withInteractiveRebase($this->interactiveRebase->dropCurrent()), null];
            }
            return [$this, null];
        }

        // During branch name collection
        if ($this->collectingBranchName) {
            if ($msg->type === KeyType::Enter) {
                return [$this->executeCreateBranch(), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune !== '') {
                return [$this->withBranchName($this->branchName . $msg->rune), null];
            }
            return [$this, null];
        }

        // During merge target collection
        if ($this->collectingMergeTarget) {
            if ($msg->type === KeyType::Enter) {
                return [$this->executeMerge(), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune !== '') {
                return [$this->withMergeTarget($this->mergeTarget . $msg->rune), null];
            }
            return [$this, null];
        }

        // Rebase menu: handle c/a/s keys
        if ($this->showRebaseMenu) {
            if ($msg->type === KeyType::Char && $msg->rune === 'c') {
                return [$this->executeRebaseContinue(), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'a') {
                return [$this->executeRebaseAbort(), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 's') {
                return [$this->executeRebaseSkip(), null];
            }
            return [$this, null];
        }

        // Help overlay: only Escape closes it
        if ($this->showHelp && $msg->type === KeyType::Escape) {
            return [$this->withShowHelp(false), null];
        }

        // Diff viewer: Space stages the current hunk, Up/Down navigate hunks, Escape closes
        if ($this->diffViewer !== null) {
            if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'd')) {
                return [$this->withDiffViewer(null), null];
            }
            if ($msg->type === KeyType::Space) {
                return [$this->stageCurrentHunk(), null];
            }
            if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
                return [$this->navigateHunk(-1), null];
            }
            if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
                return [$this->navigateHunk(+1), null];
            }
            return [$this, null];
        }

        if ($msg->type === KeyType::Char && $msg->rune === '?') {
            return [$this->withShowHelp(true), null];
        }
        if ($msg->type === KeyType::Tab) {
            return [$this->withPane($this->pane->next()), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'R') {
            return [$this->refresh(), null];
        }
        if ($msg->type === KeyType::Up
            || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return [$this->moveCursor(-1), null];
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return [$this->moveCursor(+1), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 's') {
            return [$this->toggleStage(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'a' && $this->pane === Pane::Status) {
            return [$this->stageAll(), null];
        }
        if ($msg->type === KeyType::Space && $this->pane === Pane::Branches) {
            return [$this->checkoutBranch(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'c') {
            return [$this->startCommit(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'd' && $this->pane === Pane::Status) {
            return [$this->discard(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'P' && $this->pane === Pane::Status) {
            return [$this->showDiff(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'A') {
            return [$this->amendCommit(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'n') {
            return [$this->startCreateBranch(), null];
        }
        // Undo: u key
        if ($msg->type === KeyType::Char && $msg->rune === 'u') {
            return [$this->executeUndo(), null];
        }
        // Redo: Ctrl+r
        if ($msg->ctrl && $msg->rune === 'r') {
            return [$this->executeRedo(), null];
        }
        // Delete branch: D key (branches pane only, not current branch)
        if ($msg->type === KeyType::Char && $msg->rune === 'D' && $this->pane === Pane::Branches) {
            return [$this->executeDeleteBranch(), null];
        }
        // Merge: M key
        if ($msg->type === KeyType::Char && $msg->rune === 'M') {
            return [$this->startMerge(), null];
        }
        // Rebase options: r key
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this->handleRebaseKey(), null];
        }
        // Stash list: S key (capital S)
        if ($msg->type === KeyType::Char && $msg->rune === 'S') {
            return [$this->showStashManager(), null];
        }
        // Cherry-pick: V key
        if ($msg->type === KeyType::Char && $msg->rune === 'V') {
            return [$this->startCherryPick(), null];
        }
        // Worktrees: w key (branches pane)
        if ($msg->type === KeyType::Char && $msg->rune === 'w' && $this->pane === Pane::Branches) {
            return [$this->showWorktrees(), null];
        }
        // Interactive rebase: i key
        if ($msg->type === KeyType::Char && $msg->rune === 'i') {
            return [$this->startInteractiveRebase(), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    public function refresh(): self
    {
        try {
            $status   = $this->git->status();
            $branches = $this->git->branches();
            $log      = $this->git->log(20);
            $summary  = '';
            $rows = [];
            foreach ($status as $row) {
                if (isset($row['branch_summary'])) {
                    $summary = $row['branch_summary'];
                    continue;
                }
                $rows[] = $row;
            }
            return $this->withAll(
                status: $rows,
                branches: $branches,
                log: $log,
                branchSummary: $summary,
                statusCursor: min($this->statusCursor, max(0, count($rows) - 1)),
                branchesCursor: min($this->branchesCursor, max(0, count($branches) - 1)),
                logCursor: min($this->logCursor, max(0, count($log) - 1)),
                error: null,
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function withShowHelp(bool $v): self
    {
        return $this->withAll(showHelp: $v);
    }

    private function withCommitCollection(bool $collecting, string $message): self
    {
        return $this->withAll(collectingCommit: $collecting, commitMessage: $message);
    }

    private function withCommitMessage(string $msg): self
    {
        return $this->withAll(commitMessage: $msg);
    }

    private function withPane(Pane $p): self
    {
        return $this->withAll(pane: $p);
    }

    private function withError(string $msg): self
    {
        return $this->withAll(error: $msg);
    }

    /**
     * Helper to construct new App with multiple fields updated atomically.
     */
    private function withAll(
        array $status = null,
        array $branches = null,
        array $log = null,
        string $branchSummary = null,
        Pane $pane = null,
        int $statusCursor = null,
        int $branchesCursor = null,
        int $logCursor = null,
        ?string $error = null,
        bool $showHelp = null,
        bool $collectingCommit = null,
        string $commitMessage = null,
        ?DiffViewer $diffViewer = null,
        bool $collectingBranchName = null,
        string $branchName = null,
        ?string $successMessage = null,
        HistoryManager $history = null,
        bool $collectingMergeTarget = null,
        string $mergeTarget = null,
        bool $showRebaseMenu = null,
        ?StashManager $stashManager = null,
        ?CherryPick $cherryPick = null,
        ?Worktrees $worktrees = null,
        ?InteractiveRebase $interactiveRebase = null,
    ): self {
        return new self(
            git: $this->git,
            status: $status ?? $this->status,
            branches: $branches ?? $this->branches,
            log: $log ?? $this->log,
            branchSummary: $branchSummary ?? $this->branchSummary,
            pane: $pane ?? $this->pane,
            statusCursor: $statusCursor ?? $this->statusCursor,
            branchesCursor: $branchesCursor ?? $this->branchesCursor,
            logCursor: $logCursor ?? $this->logCursor,
            error: $error,
            showHelp: $showHelp ?? $this->showHelp,
            collectingCommit: $collectingCommit ?? $this->collectingCommit,
            commitMessage: $commitMessage ?? $this->commitMessage,
            diffViewer: $diffViewer ?? $this->diffViewer,
            collectingBranchName: $collectingBranchName ?? $this->collectingBranchName,
            branchName: $branchName ?? $this->branchName,
            successMessage: $successMessage,
            history: $history ?? $this->history,
            collectingMergeTarget: $collectingMergeTarget ?? $this->collectingMergeTarget,
            mergeTarget: $mergeTarget ?? $this->mergeTarget,
            showRebaseMenu: $showRebaseMenu ?? $this->showRebaseMenu,
            stashManager: $stashManager ?? $this->stashManager,
            cherryPick: $cherryPick ?? $this->cherryPick,
            worktrees: $worktrees ?? $this->worktrees,
            interactiveRebase: $interactiveRebase ?? $this->interactiveRebase,
        );
    }

    private function moveCursor(int $dir): self
    {
        return match ($this->pane) {
            Pane::Status => $this->withAll(
                statusCursor: Clamp::int($this->statusCursor + $dir, 0, count($this->status) - 1),
            ),
            Pane::Branches => $this->withAll(
                branchesCursor: Clamp::int($this->branchesCursor + $dir, 0, count($this->branches) - 1),
            ),
            Pane::Log => $this->withAll(
                logCursor: Clamp::int($this->logCursor + $dir, 0, count($this->log) - 1),
            ),
        };
    }

    /** Runs `git restore --worktree -- <path>` via the GitDriver, then refreshes. */
    private function discard(): self
    {
        if ($this->pane !== Pane::Status) {
            return $this;
        }
        $row = $this->status[$this->statusCursor] ?? null;
        if (!is_array($row) || !isset($row['path'])) {
            return $this;
        }
        try {
            $this->git->discard($row['path']);
            return $this->withAll(history: $this->history->push(HistoryEntry::discard($row['path'])))->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function toggleStage(): self
    {
        if ($this->pane !== Pane::Status) {
            return $this;
        }
        $row = $this->status[$this->statusCursor] ?? null;
        if (!is_array($row) || !isset($row['path'])) {
            return $this;
        }
        try {
            $isStaged = ($row['index_status'] ?? ' ') !== ' ';
            $newHistory = null;
            if ($isStaged) {
                $this->git->unstage($row['path']);
                $newHistory = $this->history->push(HistoryEntry::unstage($row['path']));
            } else {
                $this->git->stage($row['path']);
                $newHistory = $this->history->push(HistoryEntry::stage($row['path']));
            }
            return $this->withAll(history: $newHistory)->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Runs `git add -A` via the GitDriver, then refreshes the status list. */
    private function stageAll(): self
    {
        try {
            $this->git->stageAll();
            return $this->withAll(history: $this->history->push(HistoryEntry::stageAll()))->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Checks out the branch at the current branches cursor via GitDriver::checkout(). */
    private function checkoutBranch(): self
    {
        $branch = $this->branches[$this->branchesCursor] ?? null;
        if (!is_array($branch) || !isset($branch['name'])) {
            return $this->withError(Lang::t('checkout.no_branch'));
        }
        try {
            $this->git->checkout($branch['name']);
            return $this->withAll(history: $this->history->push(HistoryEntry::checkout($branch['name'])))->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Opens inline commit message collection — sets collectingCommit=true, clears commitMessage. */
    private function startCommit(): self
    {
        return $this->withCommitCollection(true, '');
    }

    /** Calls GitDriver::commit() with the accumulated message, then exits collection mode and refreshes. */
    private function executeCommit(): self
    {
        if ($this->commitMessage === '') {
            return $this->withError(Lang::t('commit.empty_message'));
        }
        try {
            $this->git->commit($this->commitMessage);
            return $this
                ->withAll(history: $this->history->push(HistoryEntry::commit($this->commitMessage)))
                ->withCommitCollection(false, '')
                ->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Opens the diff viewer for the currently selected status file. */
    private function showDiff(): self
    {
        if ($this->pane !== Pane::Status) {
            return $this;
        }
        $row = $this->status[$this->statusCursor] ?? null;
        if (!is_array($row) || !isset($row['path'])) {
            return $this;
        }
        try {
            $lines = $this->git->diff($row['path']);
            $diffViewer = DiffViewer::fromRawDiff($row['path'], $lines);
            return $this->withDiffViewer($diffViewer);
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Dismiss the diff viewer. */
    private function withDiffViewer(?DiffViewer $dv): self
    {
        // Bypass withAll to avoid ?? operator treating explicit null as "keep existing"
        return new self(
            git: $this->git,
            status: $this->status,
            branches: $this->branches,
            log: $this->log,
            branchSummary: $this->branchSummary,
            pane: $this->pane,
            statusCursor: $this->statusCursor,
            branchesCursor: $this->branchesCursor,
            logCursor: $this->logCursor,
            error: $this->error,
            showHelp: $this->showHelp,
            collectingCommit: $this->collectingCommit,
            commitMessage: $this->commitMessage,
            diffViewer: $dv,
            collectingBranchName: $this->collectingBranchName,
            branchName: $this->branchName,
            successMessage: $this->successMessage,
            history: $this->history,
            collectingMergeTarget: $this->collectingMergeTarget,
            mergeTarget: $this->mergeTarget,
            showRebaseMenu: $this->showRebaseMenu,
            stashManager: $this->stashManager,
            cherryPick: $this->cherryPick,
            worktrees: $this->worktrees,
            interactiveRebase: $this->interactiveRebase,
        );
    }

    /** Navigate up/down through hunks in the diff viewer. */
    private function navigateHunk(int $dir): self
    {
        $dv = $this->diffViewer;
        if ($dv === null) {
            return $this;
        }
        $count = $dv->hunkCount();
        if ($count <= 1) {
            return $this;
        }
        // hunkCursor is a line-index into hunkStarts; find current index and move
        $currentIdx = array_search($dv->hunkCursor, $dv->hunkStarts, true);
        if ($currentIdx === false) {
            $currentIdx = 0;
        }
        $newIdx = max(0, min($count - 1, $currentIdx + $dir));
        return $this->withDiffViewer($dv->withHunkCursor($newIdx));
    }

    /** Stage the currently selected hunk in the diff viewer. */
    private function stageCurrentHunk(): self
    {
        $dv = $this->diffViewer;
        if ($dv === null) {
            return $this;
        }
        try {
            $patch = $dv->currentHunkPatch();
            $this->git->stagePatch($dv->path, $patch);
            $newHistory = $this->history->push(HistoryEntry::stagePatch($dv->path, $patch));
            return $this
                ->withAll(history: $newHistory)
                ->withDiffViewer(null)
                ->refresh()
                ->withAll(history: $newHistory, successMessage: Lang::t('diff.hunk_staged'));
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Amend the last commit without changing its message. */
    private function amendCommit(): self
    {
        try {
            $this->git->amend();
            return $this->withAll(history: $this->history->push(HistoryEntry::amend()))->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Open inline branch name collection. */
    private function startCreateBranch(): self
    {
        return $this->withBranchCollection(true, '');
    }

    private function withBranchCollection(bool $collecting, string $name): self
    {
        return $this->withAll(collectingBranchName: $collecting, branchName: $name);
    }

    private function withBranchName(string $name): self
    {
        return $this->withAll(branchName: $name);
    }

    /** Execute the create-branch flow: create branch and switch to it. */
    private function executeCreateBranch(): self
    {
        if ($this->branchName === '') {
            return $this->withError(Lang::t('branch.empty_name'));
        }
        try {
            $this->git->createBranch($this->branchName);
            return $this
                ->withAll(history: $this->history->push(HistoryEntry::createBranch($this->branchName)))
                ->withBranchCollection(false, '')
                ->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Undo the last operation. */
    private function executeUndo(): self
    {
        if (!$this->history->canUndo()) {
            return $this->withError(Lang::t('history.nothing_to_undo'));
        }
        $result = $this->history->undo();
        $entry = $result['entry'];
        $newHistory = $result['manager'];
        if ($entry === null) {
            return $this->withError(Lang::t('history.nothing_to_undo'));
        }
        return $this->applyHistoryEntry($entry, false, $newHistory);
    }

    /** Redo the last undone operation. */
    private function executeRedo(): self
    {
        if (!$this->history->canRedo()) {
            return $this->withError(Lang::t('history.nothing_to_redo'));
        }
        $result = $this->history->redo();
        $entry = $result['entry'];
        $newHistory = $result['manager'];
        if ($entry === null) {
            return $this->withError(Lang::t('history.nothing_to_redo'));
        }
        return $this->applyHistoryEntry($entry, true, $newHistory);
    }

    /**
     * Apply a history entry (either forward or inverse).
     *
     * @param bool $forward If true, apply the original op; if false, apply the inverse
     */
    private function applyHistoryEntry(HistoryEntry $entry, bool $forward, ?HistoryManager $newHistory = null): self
    {
        $op = $forward ? $entry->op : $entry->inverseOp;
        $args = $forward ? $entry->args : $entry->inverseArgs;

        try {
            match ($op) {
                'stage' => $this->git->stage($args['path']),
                'unstage' => $this->git->unstage($args['path']),
                'discard' => $this->git->discard($args['path']),
                'checkout' => $this->git->checkout($args['branch']),
                'commit' => $this->git->commit($args['message'] ?? ''),
                'reset' => $this->git->reset(),
                'amend' => $this->git->amend(),
                'createBranch' => $this->git->createBranch($args['name']),
                'deleteBranch' => $this->git->deleteBranch($args['name']),
                'stageAll' => $this->git->stageAll(),
                'unstageAll' => $this->git->unstageAll(),
                'stagePatch' => $this->git->stagePatch($args['path'], $args['hunk']),
                'unstagePatch' => $this->git->unstagePatch($args['path'], $args['hunk']),
                'merge' => $this->git->merge($args['branch']),
                'abort' => $this->git->rebaseAbort(),
                default => null,
            };
            $msg = Lang::t('history.undone', ['op' => $entry->op]);
            $refreshed = $this->refresh();
            if ($newHistory !== null) {
                $refreshed = $refreshed->withAll(history: $newHistory);
            }
            return $refreshed->withAll(successMessage: $msg);
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Delete the currently selected branch. */
    private function executeDeleteBranch(): self
    {
        $branch = $this->branches[$this->branchesCursor] ?? null;
        if (!is_array($branch) || !isset($branch['name'])) {
            return $this->withError(Lang::t('branch.delete_no_select'));
        }
        if ($branch['current'] ?? false) {
            return $this->withError(Lang::t('branch.delete_current'));
        }
        try {
            $this->git->deleteBranch($branch['name']);
            return $this
                ->withAll(history: $this->history->push(HistoryEntry::deleteBranch($branch['name'])))
                ->refresh()
                ->withAll(successMessage: Lang::t('branch.deleted', ['name' => $branch['name']]));
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Start collecting merge target branch name. */
    private function startMerge(): self
    {
        return $this->withMergeCollection(true, '');
    }

    private function withMergeCollection(bool $collecting, string $target): self
    {
        return $this->withAll(collectingMergeTarget: $collecting, mergeTarget: $target);
    }

    private function withMergeTarget(string $target): self
    {
        return $this->withAll(mergeTarget: $target);
    }

    /** Execute the merge with the collected target branch. */
    private function executeMerge(): self
    {
        if ($this->mergeTarget === '') {
            return $this->withError(Lang::t('merge.empty_target'));
        }
        try {
            $this->git->merge($this->mergeTarget);
            return $this
                ->withAll(history: $this->history->push(HistoryEntry::merge($this->mergeTarget)))
                ->withMergeCollection(false, '')
                ->refresh()
                ->withAll(successMessage: Lang::t('merge.success', ['branch' => $this->mergeTarget]));
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Handle 'r' key: show rebase menu if rebase in progress. */
    private function handleRebaseKey(): self
    {
        if ($this->isRebaseInProgress()) {
            return $this->withRebaseMenu(true);
        }
        return $this->withError(Lang::t('rebase.no_rebase'));
    }

    private function withRebaseMenu(bool $show): self
    {
        return $this->withAll(showRebaseMenu: $show);
    }

    /** Check if a rebase is currently in progress. */
    private function isRebaseInProgress(): bool
    {
        return $this->git->rebaseInProgress();
    }

    private function executeRebaseContinue(): self
    {
        try {
            $this->git->rebaseContinue();
            return $this->withRebaseMenu(false)->refresh()->withAll(
                successMessage: Lang::t('rebase.success', ['action' => 'continue'])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function executeRebaseAbort(): self
    {
        try {
            $this->git->rebaseAbort();
            return $this->withRebaseMenu(false)->refresh()->withAll(
                successMessage: Lang::t('rebase.success', ['action' => 'abort'])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function executeRebaseSkip(): self
    {
        try {
            $this->git->rebaseSkip();
            return $this->withRebaseMenu(false)->refresh()->withAll(
                successMessage: Lang::t('rebase.success', ['action' => 'skip'])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    // ---- Stash manager ----

    private function showStashManager(): self
    {
        try {
            $stashEntries = $this->git->stashList();
            return $this->withStashManager(new StashManager($stashEntries));
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function withStashManager(?StashManager $sm): self
    {
        // Bypass withAll to avoid ?? operator treating explicit null as "keep existing"
        return new self(
            git: $this->git,
            status: $this->status,
            branches: $this->branches,
            log: $this->log,
            branchSummary: $this->branchSummary,
            pane: $this->pane,
            statusCursor: $this->statusCursor,
            branchesCursor: $this->branchesCursor,
            logCursor: $this->logCursor,
            error: $this->error,
            showHelp: $this->showHelp,
            collectingCommit: $this->collectingCommit,
            commitMessage: $this->commitMessage,
            diffViewer: $this->diffViewer,
            collectingBranchName: $this->collectingBranchName,
            branchName: $this->branchName,
            successMessage: $this->successMessage,
            history: $this->history,
            collectingMergeTarget: $this->collectingMergeTarget,
            mergeTarget: $this->mergeTarget,
            showRebaseMenu: $this->showRebaseMenu,
            stashManager: $sm,
            cherryPick: $this->cherryPick,
            worktrees: $this->worktrees,
            interactiveRebase: $this->interactiveRebase,
        );
    }

    private function navigateStash(int $dir): self
    {
        $sm = $this->stashManager;
        if ($sm === null) return $this;
        return $this->withStashManager($sm->withCursor($dir));
    }

    private function executeStashApply(): self
    {
        $sm = $this->stashManager;
        if ($sm === null) return $this;
        $current = $sm->current();
        if ($current === null) return $this;
        try {
            $this->git->stashApply($current->stashRef());
            return $this->withStashManager(null)->refresh()->withAll(
                successMessage: Lang::t('stash.applied', ['ref' => $current->stashRef()])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function executeStashDrop(): self
    {
        $sm = $this->stashManager;
        if ($sm === null) return $this;
        $current = $sm->current();
        if ($current === null) return $this;
        try {
            $this->git->stashDrop($current->stashRef());
            $freshStashes = $this->git->stashList();
            return $this->withStashManager(new StashManager($freshStashes))->withAll(
                successMessage: Lang::t('stash.dropped', ['ref' => $current->stashRef()])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    // ---- Cherry-pick ----

    private function startCherryPick(): self
    {
        return $this->withCherryPick(CherryPick::collecting());
    }

    private function withCherryPick(?CherryPick $cp): self
    {
        // Bypass withAll to avoid ?? operator treating explicit null as "keep existing"
        return new self(
            git: $this->git,
            status: $this->status,
            branches: $this->branches,
            log: $this->log,
            branchSummary: $this->branchSummary,
            pane: $this->pane,
            statusCursor: $this->statusCursor,
            branchesCursor: $this->branchesCursor,
            logCursor: $this->logCursor,
            error: $this->error,
            showHelp: $this->showHelp,
            collectingCommit: $this->collectingCommit,
            commitMessage: $this->commitMessage,
            diffViewer: $this->diffViewer,
            collectingBranchName: $this->collectingBranchName,
            branchName: $this->branchName,
            successMessage: $this->successMessage,
            history: $this->history,
            collectingMergeTarget: $this->collectingMergeTarget,
            mergeTarget: $this->mergeTarget,
            showRebaseMenu: $this->showRebaseMenu,
            stashManager: $this->stashManager,
            cherryPick: $cp,
            worktrees: $this->worktrees,
            interactiveRebase: $this->interactiveRebase,
        );
    }

    private function executeCherryPick(): self
    {
        $cp = $this->cherryPick;
        if ($cp === null || $cp->commitRef === '') {
            return $this->withError(Lang::t('cherry_pick.empty_ref'));
        }
        try {
            $this->git->cherryPick($cp->commitRef);
            return $this->withCherryPick(null)->refresh()->withAll(
                successMessage: Lang::t('cherry_pick.success', ['ref' => $cp->commitRef])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    // ---- Worktrees ----

    private function showWorktrees(): self
    {
        try {
            $entries = $this->git->worktreeList();
            return $this->withWorktrees(new Worktrees($entries));
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function withWorktrees(?Worktrees $wt): self
    {
        // Bypass withAll to avoid ?? operator treating explicit null as "keep existing"
        return new self(
            git: $this->git,
            status: $this->status,
            branches: $this->branches,
            log: $this->log,
            branchSummary: $this->branchSummary,
            pane: $this->pane,
            statusCursor: $this->statusCursor,
            branchesCursor: $this->branchesCursor,
            logCursor: $this->logCursor,
            error: $this->error,
            showHelp: $this->showHelp,
            collectingCommit: $this->collectingCommit,
            commitMessage: $this->commitMessage,
            diffViewer: $this->diffViewer,
            collectingBranchName: $this->collectingBranchName,
            branchName: $this->branchName,
            successMessage: $this->successMessage,
            history: $this->history,
            collectingMergeTarget: $this->collectingMergeTarget,
            mergeTarget: $this->mergeTarget,
            showRebaseMenu: $this->showRebaseMenu,
            stashManager: $this->stashManager,
            cherryPick: $this->cherryPick,
            worktrees: $wt,
            interactiveRebase: $this->interactiveRebase,
        );
    }

    private function navigateWorktree(int $dir): self
    {
        $wt = $this->worktrees;
        if ($wt === null) return $this;
        return $this->withWorktrees($wt->withCursor($dir));
    }

    private function executeWorktreeAdd(): self
    {
        $wt = $this->worktrees;
        if ($wt === null || $wt->newPath === '') {
            return $this->withError(Lang::t('worktree.empty_path'));
        }
        try {
            $branch = $wt->newBranch !== '' ? $wt->newBranch : 'HEAD';
            $this->git->worktreeAdd($wt->newPath, $branch);
            return $this->withWorktrees(null)->refresh()->withAll(
                successMessage: Lang::t('worktree.added', ['path' => $wt->newPath])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function executeWorktreeRemove(): self
    {
        $wt = $this->worktrees;
        if ($wt === null) return $this;
        $current = $wt->current();
        if ($current === null) return $this;
        try {
            $this->git->worktreeRemove($current->path);
            return $this->withWorktrees(null)->refresh()->withAll(
                successMessage: Lang::t('worktree.removed', ['path' => $current->path])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    // ---- Interactive rebase ----

    private function startInteractiveRebase(): self
    {
        return $this->withInteractiveRebase(InteractiveRebase::selectingN());
    }

    private function withInteractiveRebase(?InteractiveRebase $ir): self
    {
        // Bypass withAll to avoid ?? operator treating explicit null as "keep existing"
        return new self(
            git: $this->git,
            status: $this->status,
            branches: $this->branches,
            log: $this->log,
            branchSummary: $this->branchSummary,
            pane: $this->pane,
            statusCursor: $this->statusCursor,
            branchesCursor: $this->branchesCursor,
            logCursor: $this->logCursor,
            error: $this->error,
            showHelp: $this->showHelp,
            collectingCommit: $this->collectingCommit,
            commitMessage: $this->commitMessage,
            diffViewer: $this->diffViewer,
            collectingBranchName: $this->collectingBranchName,
            branchName: $this->branchName,
            successMessage: $this->successMessage,
            history: $this->history,
            collectingMergeTarget: $this->collectingMergeTarget,
            mergeTarget: $this->mergeTarget,
            showRebaseMenu: $this->showRebaseMenu,
            stashManager: $this->stashManager,
            cherryPick: $this->cherryPick,
            worktrees: $this->worktrees,
            interactiveRebase: $ir,
        );
    }

    private function confirmRebaseCount(): self
    {
        $ir = $this->interactiveRebase;
        if ($ir === null || !$ir->selectingN) return $this;
        try {
            $log = $this->git->log(50);
            return $this->withInteractiveRebase($ir->confirmCount($log));
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function navigateRebaseCommit(int $dir): self
    {
        $ir = $this->interactiveRebase;
        if ($ir === null) return $this;
        return $this->withInteractiveRebase($ir->withCursor($dir));
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
