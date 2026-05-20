<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Three-pane git TUI: status (left), branches (top right), log (bottom
 * right). Tab cycles focus; up/down moves the cursor in the active
 * pane; `s` stages / unstages the highlighted status entry; `a`
 * stages all files; Space (branches pane) checks out the selected
 * branch; `c` opens inline commit message collection; `?` shows the
 * help overlay; `R` refreshes from disk.
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
    ) {}

    public static function start(GitDriver $git): self
    {
        return (new self($git))->refresh();
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

        // Escape / q / Ctrl+C always quits, even during commit/branch collection
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q')
            || ($msg->ctrl && $msg->rune === 'c')) {
            if ($this->collectingCommit) {
                return [$this->withCommitCollection(false, ''), null];
            }
            if ($this->collectingBranchName) {
                return [$this->withBranchCollection(false, ''), null];
            }
            if ($this->showHelp) {
                return [$this->withShowHelp(false), null];
            }
            if ($this->diffViewer !== null) {
                return [$this->withDiffViewer(null), null];
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
            return [$this->showDiff(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'A') {
            return [$this->amendCommit(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'n') {
            return [$this->startCreateBranch(), null];
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
        );
    }

    private function moveCursor(int $dir): self
    {
        return match ($this->pane) {
            Pane::Status => $this->withAll(
                statusCursor: $this->clamp($this->statusCursor + $dir, count($this->status)),
            ),
            Pane::Branches => $this->withAll(
                branchesCursor: $this->clamp($this->branchesCursor + $dir, count($this->branches)),
            ),
            Pane::Log => $this->withAll(
                logCursor: $this->clamp($this->logCursor + $dir, count($this->log)),
            ),
        };
    }

    private function clamp(int $i, int $size): int
    {
        if ($size <= 0) return 0;
        return max(0, min($size - 1, $i));
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
            if ($isStaged) {
                $this->git->unstage($row['path']);
            } else {
                $this->git->stage($row['path']);
            }
            return $this->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Runs `git add -A` via the GitDriver, then refreshes the status list. */
    private function stageAll(): self
    {
        try {
            $this->git->stageAll();
            return $this->refresh();
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
            return $this->refresh();
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
            return $this->withCommitCollection(false, '')->refresh();
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
            return $this->withDiffViewer(null)->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Amend the last commit without changing its message. */
    private function amendCommit(): self
    {
        try {
            $this->git->amend();
            return $this->refresh();
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
            return $this->withBranchCollection(false, '')->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }
}
