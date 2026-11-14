<?php

declare(strict_types=1);

namespace SugarCraft\Git;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Clamp;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * Three-pane git TUI: status (left), branches (top right), log (bottom
 * right). Tab cycles focus; up/down moves the cursor in the active
 * pane; `s` stages / unstages the highlighted status entry; `a`
 * stages all files; Space (branches pane) checks out the selected
 * branch; `c` opens inline commit message collection; `?` shows the
 * help overlay; `R` refreshes from disk. `u` / Ctrl+r for undo/redo.
 * `f` / `p` / `P` fetch, pull and push the tracked remote (each behind the
 * same armed confirm as the destructive verbs).
 *
 * Mirrors jesseduffield/lazygit pkg/gui — three-pane focus model and per-key controller dispatch. */
final class App implements Model
{
    /** Pane frame width used until the terminal reports its size via WindowSizeMsg. */
    private const DEFAULT_PANE_WIDTH = 36;

    /** Floor so a very narrow terminal still renders a usable pane. */
    private const MIN_PANE_WIDTH = 20;

    /**
     * @param list<array<string,mixed>> $status
     * @param list<array{name:string,sha:string,current:bool,remote:bool,ahead:?int,behind:?int,gone:bool}> $branches
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
        /** Whether the app is collecting a commit message (Tab adds lines, Enter commits). */
        public readonly bool $collectingCommit = false,
        /** The accumulated commit message (newlines included) while collectingCommit is true. */
        public readonly string $commitMessage = '',
        /** While the collector is open: it rewrites the last commit ('A' prefill) rather than starting a new one. */
        public readonly bool $commitAmend = false,
        /** Diff viewer overlay (opened with 'Enter' on a status file or a log commit - the latter read-only; 'd' closes it). */
        public readonly ?DiffViewer $diffViewer = null,
        /** Whether the app is collecting a branch name character-by-character. */
        public readonly bool $collectingBranchName = false,
        /** The accumulated branch name while collectingBranchName is true. */
        public readonly string $branchName = '',
        /** Transient success message shown until the next action or refresh acks it; navigation does not. */
        public readonly ?string $successMessage = null,
        /**
         * Amber notice lane: the confirmation request that rides with an armed
         * second press (or the collect hint while a buffer owns the keyboard).
         * candy-git-047: this state is not an error and must not share the
         * stealable red slot; the arm and its notice are one contract — they
         * are written together, kept together through navigation, and dropped
         * together on execute, cancel, refresh, or pane switch.
         */
        public readonly ?string $notice = null,
        /** Whether the app is collecting a merge target branch name. */
        public readonly bool $collectingMergeTarget = false,
        /** The accumulated merge target branch name. */
        public readonly string $mergeTarget = '',
        /** Whether the sequencer menu overlay is shown. */
        public readonly bool $showRebaseMenu = false,
        /** Which sequencer the overlay is resuming: 'rebase' | 'cherry' | 'merge'. */
        public readonly string $rebaseMenuKind = 'rebase',
        /** Key of the destructive action armed for a second confirming press. */
        public readonly ?string $pendingConfirm = null,
        /** Command history for undo/redo. */
        public readonly ?HistoryManager $history = null,
        /** Stash manager overlay (shown when 'S' is pressed). */
        public readonly ?StashManager $stashManager = null,
        /** While true the stash overlay owns a one-line message buffer for the next push (candy-git-014). */
        public readonly bool $collectingStash = false,
        /** The accumulated stash message while collectingStash is true (empty = git's own WIP decoration). */
        public readonly string $stashMessage = '',
        /** The open collection was started by 'u': the push will include untracked files (-u). */
        public readonly bool $stashUntracked = false,
        /** Cherry-pick state (shown when 'V' is pressed). */
        public readonly ?CherryPick $cherryPick = null,
        /** Worktrees manager overlay (shown when 'w' is pressed in branches pane). */
        public readonly ?Worktrees $worktrees = null,
        /** Interactive rebase overlay (shown when 'i' is pressed). */
        public readonly ?InteractiveRebase $interactiveRebase = null,
        /**
         * Base commit captured when the branch collector was opened from a log
         * row (candy-git-038). Null means the HEAD default; executeCreateBranch
         * threads it to createBranch and then clears it.
         */
        public readonly ?string $pendingBranchBase = null,
        /** Terminal width in columns, from the latest WindowSizeMsg (null until first resize). */
        public readonly ?int $width = null,
        /** Terminal height in rows, from the latest WindowSizeMsg (null until first resize). */
        public readonly ?int $height = null,
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
        if ($msg instanceof WindowSizeMsg) {
            return [$this->withSize($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof PasteMsg) {
            return $this->applyPaste($msg);
        }
        // The program probes the terminal's colour capability once
        // at launch (NO_COLOR / TERM / COLORTERM via ColorProfile::detect)
        // and hands the answer to the model. The profile is process render
        // state, not model state - sugar-style's Style::new() reads the
        // default profile, so adopting it here downgrades every hex colour in
        // the tree (and strips colour entirely under NO_COLOR) without the
        // App itself changing. Returning [$this, null] keeps the model pure.
        if ($msg instanceof ColorProfileMsg) {
            Style::setDefaultProfile($msg->profile);

            return [$this, null];
        }
        if ($msg instanceof KeyMsg === FALSE) {
            return [$this, null];
        }

        // Escape cancels the innermost open overlay/collection and quits when
        // idle; Ctrl+C rides the same chain. The 'q' quit key is gated on
        // isTyping(): while a text buffer owns the keyboard the letter q must
        // be typeable, so it falls through to the collecting arms.
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q' && !$this->isTyping())
            || ($msg->ctrl && $msg->rune === 'c')) {
            if ($this->pendingConfirm !== null) {
                return [$this->clearConfirm(), null];
            }
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
            if ($this->collectingStash) {
                // Esc backs out of the message buffer first — the overlay
                // it lives inside stays open (candy-git-014).
                return [$this->withStashCollection(false, ''), null];
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
            if ($msg->type === KeyType::Tab) {
                // candy-git-013: Tab inserts a newline here instead of being
                // swallowed — the collector owns the keyboard, so the one key
                // the user cannot get any other way (Enter commits, and every
                // terminal sends CR for Ctrl+Enter) is exactly the one worth
                // borrowing. Subject+body+bullets become typable.
                return [$this->withCommitMessage($this->commitMessage . "\n"), null];
            }
            $edited = self::editBuffer($this->commitMessage, $msg);
            if ($edited !== null) {
                return [$this->withCommitMessage($edited), null];
            }
            return [$this, null];
        }

        // Stash manager overlay (Escape closes it via the global block above)
        if ($this->stashManager !== null) {
            if ($this->collectingStash) {
                // A message buffer owns the keyboard: Enter pushes, edit
                // keys type, everything else is swallowed (candy-git-014).
                if ($msg->type === KeyType::Enter) {
                    return [$this->executeStashPush(), null];
                }
                $edited = self::editBuffer($this->stashMessage, $msg);
                if ($edited !== null) {
                    return [$this->withAll(stashMessage: $edited), null];
                }
                return [$this, null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'a') {
                return [$this->executeStashApply(), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'p') {
                return [$this->executeStashPop(), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 's') {
                return [$this->startStashPush(false), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'u') {
                return [$this->startStashPush(true), null];
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

        // Cherry-pick mode (Escape closes it via the global block above)
        if ($this->cherryPick !== null && $this->cherryPick->collecting === TRUE) {
            if ($msg->type === KeyType::Enter) {
                return [$this->executeCherryPick(), null];
            }
            $edited = self::editBuffer($this->cherryPick->commitRef, $msg);
            if ($edited !== null) {
                return [$this->withCherryPick($this->cherryPick->withCommitRef($edited)), null];
            }
            return [$this, null];
        }

        // Worktrees overlay (Escape closes the whole overlay via the global
        // block above — even mid-add; the old per-branch cancelAdding arms
        // sat behind that block and were unreachable).
        if ($this->worktrees !== null) {
            // When adding, Enter confirms and text edits (Char/Space/Backspace) append to the active field;
            // Tab switches which field that is (CG-24, candy-git-030: the branch slot had no input path).
            if ($this->worktrees->adding === TRUE) {
                if ($msg->type === KeyType::Enter) {
                    return [$this->executeWorktreeAdd(), null];
                }
                if ($msg->type === KeyType::Tab) {
                    return [$this->withWorktrees($this->worktrees->toggleAddField()), null];
                }
                $edited = self::editBuffer($this->worktrees->activeText(), $msg);
                if ($edited !== null) {
                    return [$this->withWorktrees($this->worktrees->withActiveText($edited)), null];
                }
                return [$this, null];
            }
            // When not adding
            if ($msg->type === KeyType::Char && $msg->rune === 'a') {
                return [$this->withWorktrees($this->worktrees->startAdding()), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'd' && $this->worktrees->removing === FALSE) {
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

        // Interactive rebase overlay (Escape closes it via the global block above)
        if ($this->interactiveRebase !== null) {
            // Selecting N: enter digit
            if ($this->interactiveRebase->selectingN) {
                if ($msg->type === KeyType::Enter) {
                    return [$this->confirmRebaseCount(), null];
                }
                if ($msg->type === KeyType::Char && ctype_digit($msg->rune) === TRUE) {
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
            // candy-git-011, parked per gate G1 (default arm): cycling and
            // dropping were preview theatre - the built todo was discarded on
            // Esc and no history could ever change - so the mutating keys
            // answer with the banner's own words on the amber notice lane
            // instead of silently re-drawing the preview. The keys stay
            // swallowed HERE on purpose: an unmapped key must never leak to
            // the global handlers (a 'd' that reached the status pane from
            // under this overlay would arm a real discard).
            if ($msg->type === KeyType::Space || ($msg->type === KeyType::Char && $msg->rune === 'l')) {
                return [$this->refuseParkedRebase(), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'd') {
                return [$this->refuseParkedRebase(), null];
            }
            return [$this, null];
        }

        // During branch name collection
        if ($this->collectingBranchName) {
            if ($msg->type === KeyType::Enter) {
                return [$this->executeCreateBranch(), null];
            }
            $edited = self::editBuffer($this->branchName, $msg);
            if ($edited !== null) {
                return [$this->withBranchName($edited), null];
            }
            return [$this, null];
        }

        // During merge target collection
        if ($this->collectingMergeTarget) {
            if ($msg->type === KeyType::Enter) {
                return [$this->executeMerge(), null];
            }
            $edited = self::editBuffer($this->mergeTarget, $msg);
            if ($edited !== null) {
                return [$this->withMergeTarget($edited), null];
            }
            return [$this, null];
        }

        // Sequencer menu: handle c/a/s keys against whichever operation stopped
        // (a stopped merge drives c/a; skip is a rebase-only notion, so the
        // row for it is only offered while kind is 'rebase').
        if ($this->showRebaseMenu) {
            $kind = $this->rebaseMenuKind;
            if ($msg->type === KeyType::Char && $msg->rune === 'c') {
                return [match ($kind) {
                    'cherry' => $this->executeCherryPickContinue(),
                    'merge' => $this->executeMergeContinue(),
                    default => $this->executeRebaseContinue(),
                }, null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'a') {
                return [match ($kind) {
                    'cherry' => $this->executeCherryPickAbort(),
                    'merge' => $this->executeMergeAbort(),
                    default => $this->executeRebaseAbort(),
                }, null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 's' && $kind === 'rebase') {
                return [$this->executeRebaseSkip(), null];
            }
            return [$this, null];
        }

        // (Help overlay needs no Escape arm here: the global block above
        // already closes it, and 'q' while help is open dismisses it too.)

        // Diff viewer (candy-git-034/036/045): Space acts on the side the
        // viewer is showing — worktree side stages, cached side UNstages
        // (armed twice, the index edit is the one a mis-press hides).
        // j/k/arrows scroll LINES so a hunk taller than the screen is
        // readable to its last row; [ ] hop hunks; page keys page lines.
        if ($this->diffViewer !== null) {
            $dv = $this->diffViewer;
            if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'd')) {
                // Closing takes any live arm with it (candy-git-011 law: the
                // armed gesture dies with the surface that offered it).
                return [$this->clearConfirm()->withDiffViewer(null), null];
            }
            // Read-only commit view (candy-git-015): staging is inert here,
            // the keys are the history actions the log never had.
            if ($dv->readOnly && $msg->type === KeyType::Space) {
                    return [$this, null];
                }
                if ($dv->readOnly && $msg->type === KeyType::Char) {
                return match ($msg->rune) {
                    'r' => [$this->executeRevert($dv->commitSha), null],
                    'R' => [$this->executeResetHard($dv->commitSha), null],
                    'v' => $dv->commitSha === ''
                        ? [$this, null]
                        : [$this->withDiffViewer(null)->withCherryPick(CherryPick::collecting()->withCommitRef($dv->commitSha)), null],
                    ' ' => [$this, null],
                    default => [$this, null],
                };
            }
            if ($msg->type === KeyType::Space) {
                return [$dv->cached ? $this->unstageCurrentHunk() : $this->stageCurrentHunk(), null];
            }
            if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
                return [$this->withDiffViewer($dv->scrollBy(-1)), null];
            }
            if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
                return [$this->withDiffViewer($dv->scrollBy(+1)), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === '[') {
                return [$this->navigateHunk(-1), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === ']') {
                return [$this->navigateHunk(+1), null];
            }
            $page = max(1, $this->overlayBodyRows() - 6);
            if ($msg->type === KeyType::PageUp) {
                return [$this->withDiffViewer($dv->scrollBy(-$page)), null];
            }
            if ($msg->type === KeyType::PageDown) {
                return [$this->withDiffViewer($dv->scrollBy(+$page)), null];
            }
            if ($msg->type === KeyType::Home) {
                return [$this->withDiffViewer($dv->withHunkIndex(0)), null];
            }
            if ($msg->type === KeyType::End) {
                return [$this->withDiffViewer($dv->withHunkIndex(PHP_INT_MAX)), null];
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
        // Viewport keys for the list panes — Page keys jump roughly a
        // screen, Home/End snap to the edges.
        if ($msg->type === KeyType::PageUp || $msg->type === KeyType::PageDown
            || $msg->type === KeyType::Home || $msg->type === KeyType::End) {
            return [$this->viewportKey($msg->type), null];
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
        if ($msg->type === KeyType::Enter && $this->pane === Pane::Status) {
            return [$this->showDiff(), null];
        }
        // The log pane acts on what it shows (candy-git-015): Enter opens the
        // full commit display read-only, and revert/reset/cherry-pick ride
        // inside it on the commit sha already on screen - never retyped.
        if ($msg->type === KeyType::Enter && $this->pane === Pane::Log) {
            return [$this->openCommitDetails(), null];
        }
        // Remotes: f fetch, p pull, P push (candy-git-005). Repo-wide verbs,
        // every one armed for a second confirming press — a push is visible
        // to others and a pull merges into the worktree, the same contract
        // discard/delete-drop already keep.
        if ($msg->type === KeyType::Char && $msg->rune === 'f') {
            return [$this->handleRemote('fetch'), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'p') {
            return [$this->handleRemote('pull'), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'P') {
            return [$this->handleRemote('push'), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'A') {
            return [$this->startAmend(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'n') {
            return [$this->startCreateBranch(), null];
        }
        // Undo: u key
        if ($msg->type === KeyType::Char && $msg->rune === 'u') {
            return [$this->executeUndo(), null];
        }
        // Redo: Ctrl+r
        if ($msg->ctrl === TRUE && $msg->rune === 'r') {
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

    /**
     * Width of each of the two side-by-side pane columns. Derived from the
     * terminal width reported via {@see WindowSizeMsg} — the two columns split
     * the width minus the border/padding/gutter overhead — and falls back to
     * the historical constant until a size arrives so first-frame output stays
     * byte-stable.
     */
    public function paneWidth(): int
    {
        if ($this->width === null) {
            return self::DEFAULT_PANE_WIDTH;
        }
        // Two frames each cost 4 cols (rounded border + 1-col padding, both
        // sides) plus a 2-col gutter between them: total overhead = 10.
        return max(self::MIN_PANE_WIDTH, intdiv($this->width - 10, 2));
    }

    /**
     * Rows available for the pane/overlay area after fixed chrome (header row,
     * help line, trailing row) and any notice or prompt bars. NULL until the
     * terminal reports its size — callers then treat lists as unbounded, which
     * is the historical byte-stable first-frame output (the height
     * was stored but never read, so every list and overlay rendered in full
     * and appended below each other off the bottom of the screen).
     */
    public function listRows(): ?int
    {
        if ($this->height === null) {
            return null;
        }
        $used = 3; // header row, help line, trailing row
        if ($this->error !== null)          { $used++; }
        if ($this->successMessage !== null) { $used++; }
        if ($this->collectingCommit)        { $used++; }
        if ($this->collectingBranchName)    { $used++; }
        if ($this->collectingMergeTarget)   { $used++; }
        if ($this->collectingStash)         { $used++; }
        if ($this->cherryPick !== null && $this->cherryPick->collecting) { $used++; }
        if ($this->cursorTarget() !== null)               { $used++; } // candy-git-022 modeline row
        return max(1, $this->height - $used);
    }

    /** Whether a full overlay is open and must share the screen with the lists. */
    public function overlayOpen(): bool
    {
        return $this->diffViewer !== null
            || $this->showHelp === TRUE
            || $this->showRebaseMenu === TRUE
            || $this->stashManager !== null
            || $this->worktrees !== null
            || $this->interactiveRebase !== null;
    }

    /** Rows the three list panes get; an open overlay claims the rest. */
    private function paneArea(): int
    {
        $rows = $this->listRows();
        if ($rows === null) {
            return PHP_INT_MAX;
        }
        if ($this->overlayOpen()) {
            return max(3, intdiv($rows, 3));
        }
        return $rows;
    }

    /** Visible status-list rows (frame borders excluded). */
    public function statusBodyRows(): int
    {
        $area = $this->paneArea();
        return $area === PHP_INT_MAX ? PHP_INT_MAX : max(1, $area - 2);
    }

    /** Visible branch-list rows: the right column stacks branches over log. */
    public function branchesBodyRows(): int
    {
        $area = $this->paneArea();
        return $area === PHP_INT_MAX ? PHP_INT_MAX : max(1, intdiv($area, 2) - 2);
    }

    /** Visible log rows: the right-column remainder below branches. */
    public function logBodyRows(): int
    {
        $area = $this->paneArea();
        if ($area === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }
        return max(1, ($area - intdiv($area, 2)) - 2);
    }

    /** Rows an open overlay gets; a tiny floor so short screens still show a frame. */
    public function overlayBodyRows(): int
    {
        $rows = $this->listRows();
        if ($rows === null) {
            return PHP_INT_MAX;
        }
        return max(1, $rows - $this->paneArea());
    }

    /**
     * The plain-text cursor modeline: what the keyboard currently acts on.
     *
     * Reverse video (SGR 7) carries the row cursor on styling terminals, but
     * sugar-style answers TERM=dumb (ColorProfile::NoTty) by emitting NO
     * escape at all — the selected row would become invisible while s/d/
     * Enter/Space kept acting on it (candy-git-022). This line names the
     * same target as untruncated text, and the Renderer prints it WITHOUT
     * any Style wrapper so it survives every palette.
     */
    public function cursorTarget(): ?string
    {
        if ($this->showHelp || $this->showRebaseMenu) {
            return null;
        }
        if ($this->isTyping()) {
            // A buffer bar owns the keyboard; its label already names itself.
            return null;
        }
        if ($this->diffViewer !== null) {
            return $this->diffViewer->path;
        }
        if ($this->stashManager !== null) {
            $entry = $this->stashManager->current();
            return $entry === null ? null : $entry->stashRef() . ': ' . $entry->message;
        }
        if ($this->worktrees !== null) {
            return $this->worktrees->current()?->path;
        }
        if ($this->interactiveRebase !== null) {
            $row = $this->interactiveRebase->commits[$this->interactiveRebase->cursor] ?? null;
            return $row === null ? null : $row->sha . ' ' . $row->subject;
        }
        if ($this->cherryPick !== null) {
            return null;
        }
        return match ($this->pane) {
            Pane::Status => isset($this->status[$this->statusCursor]['path'])
                ? (string) $this->status[$this->statusCursor]['path'] : null,
            Pane::Branches => isset($this->branches[$this->branchesCursor]['name'])
                ? (string) $this->branches[$this->branchesCursor]['name'] : null,
            Pane::Log => isset($this->log[$this->logCursor]['sha'])
                ? (string) $this->log[$this->logCursor]['sha'] . ' ' . (string) ($this->log[$this->logCursor]['subject'] ?? '') : null,
        };
    }

    /** Horizontal budget for full-width prompt/notice bars. */
    public function barWidth(): int
    {
        return $this->width === null ? PHP_INT_MAX : max(1, $this->width - 2);
    }

    /** Status-pane path budget: pane width minus padding (2) and the 3-col "XY " marker. */
    public function statusPathWidth(): int
    {
        return max(1, $this->paneWidth() - 5);
    }

    /** Branch-name budget: pane width minus padding (2) and the 2-col current-branch marker. */
    public function branchNameWidth(): int
    {
        return max(1, $this->paneWidth() - 4);
    }

    /** Log-subject budget: pane width minus padding (2) and the short-sha + 2-space gap (8). */
    public function logSubjectWidth(): int
    {
        return max(1, $this->paneWidth() - 10);
    }

    /**
     * Whether a text-collection buffer currently owns the keyboard: in these
     * states printable keys belong to the buffer, and global quit keys that
     * collide with letters (q) must not fire. Escape/Ctrl+C still cancel via
     * the global chain — only the letter collision is gated.
     */
    public function isTyping(): bool
    {
        return $this->collectingCommit
            || $this->collectingBranchName
            || $this->collectingMergeTarget
            || $this->collectingStash
            || ($this->cherryPick !== null && $this->cherryPick->collecting)
            || ($this->worktrees !== null && $this->worktrees->adding);
    }

    /**
     * Apply one text-edit key to a collecting buffer: printable Char and
     * Space append, Backspace removes the last grapheme cluster (candy-git-099
     * — one press on 👩‍💻, no orphaned joiners), Ctrl+W kills the last word
     * and Ctrl+U clears the buffer (candy-git-046). Returns the new buffer,
     * or null when the key is not an edit key (other ctrl/alt chords never
     * insert text). Space arrives as KeyType::Space from the input parser,
     * never as Char ' ' — both shapes are handled.
     *
     * candy-git-009: the ctrl/alt FLAG guard alone is not enough. sugar-core's
     * InputReader maps raw C0 bytes (0x00, 0x1c-0x1f) to Char runes with
     * ctrl=false — pastes outside a bracketed envelope and rare keycodes both
     * deliver them — so the printable test is applied to the rune itself.
     * Any codepoint in Unicode general category C (Cc/Cf/Cn/Co/Cs) except \n
     * is dropped: NUL through DEL, but also zero-width and other invisibles,
     * which garble cells, desync the line-diff model, and ride into git argv.
     */
    private static function editBuffer(string $buffer, KeyMsg $msg): ?string
    {
        if ($msg->ctrl && !$msg->alt && $msg->type === KeyType::Char) {
            // InputReader folds ctrl+letter into chr(0x60+code) with ctrl=true:
            // 23 is 'w', 21 is 'u'. The other edit-relevant ctrl chords never
            // arrive here: Ctrl+C is claimed by the quit chain above the
            // collectors, and pane verbs (Ctrl+R) sit behind the early
            // collector return, so an unclaimed chord just no-ops in-buffer.
            return match ($msg->rune) {
                'w' => self::killWord($buffer),
                'u' => '',
                default => null,
            };
        }
        if ($msg->ctrl || $msg->alt) {
            return null;
        }
        if ($msg->type === KeyType::Char && $msg->rune !== '') {
            // !== 0, not === 1: a lone continuation byte makes preg_match return
            // FALSE, and FALSE must also be refused — the same fail-closed rule
            // that governs Renderer::sanitize (audit #7).
            if (preg_match('/[^\P{C}\n]/u', $msg->rune) !== 0) {
                return null;
            }

            return $buffer . $msg->rune;
        }
        if ($msg->type === KeyType::Space) {
            return $buffer . ' ';
        }
        if ($msg->type === KeyType::Backspace) {
            if ($buffer === '') {
                return '';
            }
            // candy-git-099: walk the SAME grapheme splitter the truncation
            // paths use (Width::nextCluster) so a ZWJ family like 👩‍💻
            // dies in one press instead of shedding orphaned joiners mid-buffer.
            $i = 0;
            $last = 0;
            $len = strlen($buffer);
            while ($i < $len) {
                $cluster = Width::nextCluster($buffer, $i);
                $last = $i;
                $i += strlen($cluster);
            }

            return substr($buffer, 0, $last);
        }
        return null;
    }

    /**
     * Ctrl+W: kill the last word — trailing blanks, then the word behind
     * them (readline semantics on words-as-run-of-nonspace, the honest shape
     * for branch names, refs, and one-line messages). Fail-closed on
     * malformed UTF-8: a FALSE preg answer refuses the edit, never eats text.
     */
    private static function killWord(string $buffer): string
    {
        $unspaced = preg_replace('/\s+$/u', '', $buffer);
        if (!is_string($unspaced)) {
            return $buffer;
        }
        $cut = preg_replace('/\S+$/u', '', $unspaced);

        return is_string($cut) ? $cut : $buffer;
    }

    /**
     * Route a bracketed paste into whichever text buffer owns the keyboard;
     * pastes outside a collecting buffer are dropped.
     */
    private function applyPaste(PasteMsg $msg): array
    {
        $text = self::flattenPaste($msg->content);
        if ($this->collectingCommit) {
            // The commit buffer is multi-line by design (candy-git-013): a
            // pasted paragraph keeps its shape. Paste bytes never dispatch
            // keys, so an embedded newline cannot smuggle an Enter — the
            // flattening was only ever for one-line buffers.
            return [$this->withCommitMessage($this->commitMessage . self::commitPasteText($msg->content)), null];
        }
        if ($this->cherryPick !== null && $this->cherryPick->collecting) {
            return [$this->withCherryPick($this->cherryPick->withCommitRef($this->cherryPick->commitRef . $text)), null];
        }
        if ($this->worktrees !== null && $this->worktrees->adding) {
            return [$this->withWorktrees($this->worktrees->appendActive($text)), null];
        }
        if ($this->collectingBranchName) {
            return [$this->withBranchName($this->branchName . $text), null];
        }
        if ($this->collectingMergeTarget) {
            return [$this->withMergeTarget($this->mergeTarget . $text), null];
        }
        return [$this, null];
    }

    /**
     * Paste bytes arrive pre-sanitized by the input reader (embedded escape
     * sequences neutralized); newlines and tabs are flattened to spaces so a
     * multi-line paste can never smuggle an Enter-equivalent action through a
     * one-line buffer.
     */
    private static function flattenPaste(string $content): string
    {
        return preg_replace('/[\r\n\t]+/', ' ', $content) ?? '';
    }

    /**
     * Prepare pasted text for the (multi-line) commit buffer: newlines are
     * kept so a pasted paragraph preserves its shape (the flattening was
     * only ever needed for the one-line buffers). Paste bytes never
     * dispatch as keys, so an embedded newline cannot trigger a commit
     * (candy-git-013). Anything besides newline in the envelope is the
     * same fail-closed category-C refusal the typed gate enforces: the
     * whole paste is refused rather than half-trusted.
     */
    private static function commitPasteText(string $content): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $tabsOut    = str_replace("\t", ' ', $normalized);

        // Refuse the whole envelope if any category-C byte other than newline
        // survives (fail-closed, matching the typed gate in editBuffer).
        return preg_match('/[^\P{C}\n]/u', $tabsOut) !== 0 ? '' : $tabsOut;
    }

    private function withSize(int $width, int $height): self
    {
        return $this->withAll(width: $width, height: $height);
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
        // Opening or closing the buffer always leaves amend mode behind it:
        // the next commit starts fresh, and a stale amend flag must never
        // rewrite HEAD again by accident (candy-git-013). startAmend
        // re-raises the flag explicitly on top of this call.
        return $this->withAll(collectingCommit: $collecting, commitMessage: $message, commitAmend: false);
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
        ?array $status = null,
        ?array $branches = null,
        ?array $log = null,
        ?string $branchSummary = null,
        ?Pane $pane = null,
        ?int $statusCursor = null,
        ?int $branchesCursor = null,
        ?int $logCursor = null,
        ?string $error = null,
        ?bool $showHelp = null,
        ?bool $collectingCommit = null,
        ?string $commitMessage = null,
        ?bool $commitAmend = null,
        ?DiffViewer $diffViewer = null,
        bool $diffViewerSet = false,
        ?bool $collectingBranchName = null,
        ?string $branchName = null,
        ?string $successMessage = null,
        ?HistoryManager $history = null,
        ?bool $collectingMergeTarget = null,
        ?string $mergeTarget = null,
        ?bool $showRebaseMenu = null,
        ?string $rebaseMenuKind = null,
        ?string $pendingConfirm = null,
        bool $pendingConfirmSet = false,
        ?string $notice = null,
        ?StashManager $stashManager = null,
        bool $stashManagerSet = false,
        ?bool $collectingStash = null,
        ?string $stashMessage = null,
        ?bool $stashUntracked = null,
        ?CherryPick $cherryPick = null,
        bool $cherryPickSet = false,
        ?Worktrees $worktrees = null,
        bool $worktreesSet = false,
        ?InteractiveRebase $interactiveRebase = null,
        bool $interactiveRebaseSet = false,
        ?string $pendingBranchBase = null,
        bool $pendingBranchBaseSet = false,
        ?int $width = null,
        ?int $height = null,
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
            commitAmend: $commitAmend ?? $this->commitAmend,
            diffViewer: $diffViewerSet ? $diffViewer : ($diffViewer ?? $this->diffViewer),
            collectingBranchName: $collectingBranchName ?? $this->collectingBranchName,
            branchName: $branchName ?? $this->branchName,
            successMessage: $successMessage,
            history: $history ?? $this->history,
            collectingMergeTarget: $collectingMergeTarget ?? $this->collectingMergeTarget,
            mergeTarget: $mergeTarget ?? $this->mergeTarget,
            showRebaseMenu: $showRebaseMenu ?? $this->showRebaseMenu,
            rebaseMenuKind: $rebaseMenuKind ?? $this->rebaseMenuKind,
            // The armed confirm, its amber notice, and the arm-set sentinel
            // are one contract (candy-git-047): armConfirm writes the pair,
            // navigation re-passes the pair, and every other lane drops the
            // pair by leaving these parameters null — an arm can never
            // outlive its visible warning, and a visible warning can never
            // be erased while the arm still lives (the old shared-red-slot
            // design allowed exactly that: candy-git-002).
            pendingConfirm: $pendingConfirmSet ? $pendingConfirm : null,
            notice: $notice,
            stashManager: $stashManagerSet ? $stashManager : ($stashManager ?? $this->stashManager),
            collectingStash: $collectingStash ?? $this->collectingStash,
            stashMessage: $stashMessage ?? $this->stashMessage,
            stashUntracked: $stashUntracked ?? $this->stashUntracked,
            cherryPick: $cherryPickSet ? $cherryPick : ($cherryPick ?? $this->cherryPick),
            worktrees: $worktreesSet ? $worktrees : ($worktrees ?? $this->worktrees),
            interactiveRebase: $interactiveRebaseSet ? $interactiveRebase : ($interactiveRebase ?? $this->interactiveRebase),
            pendingBranchBase: $pendingBranchBaseSet ? $pendingBranchBase : ($pendingBranchBase ?? $this->pendingBranchBase),
            width: $width ?? $this->width,
            height: $height ?? $this->height,
        );
    }

    private function moveCursor(int $dir): self
    {
        // Navigation is neither an answer to an arm nor a reading of a
        // message: the amber notice, the red error, and the green success
        // all persist until an action acks them (candy-git-047/098). The
        // arm persists with its notice — pressing a DIFFERENT target while
        // an arm lives re-arms (arm keys embed the target), so persisting
        // never executes the wrong thing.
        return $this->withAll(
            statusCursor: $this->pane === Pane::Status ? Clamp::int($this->statusCursor + $dir, 0, count($this->status) - 1) : $this->statusCursor,
            branchesCursor: $this->pane === Pane::Branches ? Clamp::int($this->branchesCursor + $dir, 0, count($this->branches) - 1) : $this->branchesCursor,
            logCursor: $this->pane === Pane::Log ? Clamp::int($this->logCursor + $dir, 0, count($this->log) - 1) : $this->logCursor,
            error: $this->error,
            successMessage: $this->successMessage,
            pendingConfirm: $this->pendingConfirm,
            pendingConfirmSet: true,
            notice: $this->notice,
        );
    }

    /**
     * PageUp/PageDown jump by one visible page (minus a 2-row overlap for
     * context), Home snaps to the first row, End to the last — all through the
     * same clamped moveCursor so an empty list is a no-op.
     */
    private function viewportKey(KeyType $type): self
    {
        if ($type === KeyType::Home) {
            return $this->moveCursor(-1000000000);
        }
        if ($type === KeyType::End) {
            return $this->moveCursor(1000000000);
        }
        $page = match ($this->pane) {
            Pane::Status   => $this->statusBodyRows(),
            Pane::Branches => $this->branchesBodyRows(),
            Pane::Log      => $this->logBodyRows(),
        };
        $step = max(1, min($page, 1000000) - 2);
        return $this->moveCursor($type === KeyType::PageUp ? -$step : +$step);
    }

    /** Runs `git restore --worktree -- <path>` via the GitDriver, then refreshes. */
    /** Arms a destructive action: the stored key is answered by a matching repeat press, and the amber notice lane shows the request for as long as the arm lives. */
    private function armConfirm(string $key, string $warning): self
    {
        return $this->withAll(pendingConfirm: $key, pendingConfirmSet: true, notice: $warning);
    }

    private function clearConfirm(): self
    {
        return $this->withAll(pendingConfirm: null, pendingConfirmSet: true);
    }

    private function discard(): self
    {
        if ($this->pane !== Pane::Status) {
            return $this;
        }
        $row = $this->status[$this->statusCursor] ?? null;
        if (!is_array($row) || !isset($row['path'])) {
            return $this;
        }
        // Discarding worktree bytes is unrecoverable - git has no
        // undo for it either. One press arms (naming the target), the same
        // press on the same target executes; Escape cancels.
        $untracked = ($row['index_status'] ?? '') === '?'
            && ($row['work_status'] ?? '') === '?';
        $arm = 'discard:' . $row['path'];
        if ($this->pendingConfirm !== $arm) {
            // An untracked row's d never reaches git restore (it would
            // answer pathspec-did-not-match - a guaranteed error on the
            // routine junk-file cleanup, candy-git-035). It unlinks the
            // file, so the warning says DISK, not git-soup.
            $warning = $untracked
                ? Lang::t('confirm.discard_untracked', ['path' => (string) $row['path']])
                : Lang::t('confirm.discard', ['path' => (string) $row['path']]);

            return $this->armConfirm($arm, $warning);
        }
        $base = $this->clearConfirm();
        try {
            if ($untracked) {
                $this->git->deleteUntracked($row['path']);
            } else {
                $this->git->discard($row['path']);
            }

            $entry = $untracked
                ? HistoryEntry::deleteUntracked($row['path'])
                : HistoryEntry::discard($row['path']);

            $done = $base->withAll(history: $base->requireHistory()->push($entry))->refresh();

            return $untracked
                ? $done->withAll(successMessage: Lang::t('discard.deleted', ['path' => (string) $row['path']]))
                : $done;
        } catch (\RuntimeException $e) {
            return $base->withError($e->getMessage());
        }
    }

    /**
     * Arm-confirm lane for the three remote verbs (candy-git-005). First
     * press arms (naming the operation in the warning lane), the same press
     * executes; Escape/pane-navigation clears the arm like every other
     * confirm. Success lands in the transient success lane and refreshes the
     * panes — fetch/pull move ahead/behind state, push changes what origin
     * knows, and all three are the point of the header summary.
     */
    private function handleRemote(string $verb): self
    {
        $arm     = 'remote:' . $verb;
        $warning = match ($verb) {
            'fetch' => Lang::t('confirm.fetch'),
            'pull'  => Lang::t('confirm.pull'),
            default => Lang::t('confirm.push'),
        };
        if ($this->pendingConfirm !== $arm) {
            return $this->armConfirm($arm, $warning);
        }
        $base = $this->clearConfirm();
        try {
            match ($verb) {
                'fetch' => $this->git->fetch(),
                'pull'  => $this->git->pull(),
                default => $this->git->push(),
            };
            $done = match ($verb) {
                'fetch' => Lang::t('remote.fetch_done'),
                'pull'  => Lang::t('remote.pull_done'),
                default => Lang::t('remote.push_done'),
            };
            return $base->refresh()->withAll(successMessage: $done);
        } catch (\RuntimeException $e) {
            return $base->withError($e->getMessage());
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
            // Conflicted ('U') and untracked ('?') rows stage on the first key:
        // their index letters are not a staged change to reverse.
        $isStaged = !in_array((string) ($row['index_status'] ?? ' '), [' ', '?', 'U'], true);
            $newHistory = null;
            if ($isStaged) {
                $this->git->unstage($row['path']);
                $newHistory = $this->requireHistory()->push(HistoryEntry::unstage($row['path']));
            } else {
                $this->git->stage($row['path']);
                $newHistory = $this->requireHistory()->push(HistoryEntry::stage($row['path']));
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
            return $this->withAll(history: $this->requireHistory()->push(HistoryEntry::stageAll()))->refresh();
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
        // Captured BEFORE the switch: the branches list is still the
        // pre-checkout snapshot, so this names the branch the user is
        // leaving — the inverse needs an origin to return to.
        $from = $this->currentBranchName();
        try {
            $name = (string) $branch['name'];
            if ($branch['remote'] ?? false) {
                // DWIM (candy-git-037): Space on `origin/feature` should land
                // on a local `feature` tracking it — `checkout --track` for
                // a fresh branch, or a plain checkout when the local already
                // exists (git refuses --track then). The history names the
                // local branch either way, because that is what HEAD moved to.
                $local = self::localNameOf((string) $name);
                try {
                    $this->git->checkoutRemote($name);
                } catch (\RuntimeException) {
                    $this->git->checkout($local);
                }
                return $this->withAll(history: $this->requireHistory()->push(HistoryEntry::checkout($from, $local)))->refresh();
            }
            $this->git->checkout($name);
            return $this->withAll(history: $this->requireHistory()->push(HistoryEntry::checkout($from, $name)))->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /**
     * Strip the remote name from a remote-tracking ref: `origin/feature` and
     * `origin/feat/x` both DWIM to a local `feature` / `feat/x` — only the
     * first path element is the remote.
     */
    private static function localNameOf(string $remoteBranch): string
    {
        $slash = strpos($remoteBranch, '/');
        return $slash === false ? $remoteBranch : substr($remoteBranch, $slash + 1);
    }

    /**
     * Name of the branch the cached branches list marks as current, or ''
     * when none is (detached HEAD, or the list has not loaded yet).
     */
    private function currentBranchName(): string
    {
        foreach ($this->branches as $row) {
            if (is_array($row) && ($row['current'] ?? false) === true) {
                return (string) ($row['name'] ?? '');
            }
        }

        return '';
    }

    /** Opens inline commit message collection — sets collectingCommit=true, clears commitMessage. */
    private function startCommit(): self
    {
        return $this->withCommitCollection(true, '');
    }

    /** Calls GitDriver::commit() (or the amend leg) with the accumulated message, then exits collection mode and refreshes. */
    private function executeCommit(): self
    {
        if ($this->commitMessage === '') {
            return $this->withError(Lang::t('commit.empty_message'));
        }
        try {
            if ($this->commitAmend) {
                // A pre-filled collector rewrites HEAD with the edited bytes
                // (candy-git-013); submitting it untouched is the old
                // --no-edit keypress, expressed through -m.
                $this->git->amendWith($this->commitMessage);

                return $this
                    ->withAll(history: $this->requireHistory()->push(HistoryEntry::amend()), commitAmend: false)
                    ->withCommitCollection(false, '')
                    ->refresh();
            }
            $this->git->commit($this->commitMessage);

            return $this
                ->withAll(history: $this->requireHistory()->push(HistoryEntry::commit($this->commitMessage)))
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
            // A row whose worktree half is clean but whose index half is not
            // has all its change STAGED — a plain worktree diff would render
            // empty, so show the staged side for those rows.
            $cached = ($row['index_status'] ?? ' ') !== ' '
                && ($row['index_status'] ?? ' ') !== '?'
                && ($row['work_status'] ?? ' ') === ' ';
            // A ?? row has no git diff at all - plain git diff -- path
            // answers with nothing, which rendered as an empty box on
            // the most routine review action there is (candy-git-035).
            // It shows as git's own new-file patch instead: every line
            // an addition, stageable hunk by hunk exactly like any other
            // diff (apply --cached of a +lines block IS a partial add).
            $untracked = ($row['index_status'] ?? '') === '?'
                && ($row['work_status'] ?? '') === '?';
            $lines = $untracked
                ? $this->git->untrackedDiff($row['path'])
                : $this->git->diff($row['path'], $cached);
            // The side must ride the viewer, not just the request: a staged
            // row's viewer showing --cached bytes while Space re-staged them
            // was the candy-git-034 trap.
            $diffViewer = DiffViewer::fromRawDiff($row['path'], $lines, $cached);
            return $this->withDiffViewer($diffViewer);
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Explicit-set diffViewer swap — the null argument MEANS "close the overlay",
     * which withAll's `?? $this->diffViewer` keep-existing fallthrough cannot express
     * unaided, hence the diffViewerSet sentinel (single construction site).
     * error/successMessage ride through unchanged, matching the old full-arg bypass.
     */
    private function withDiffViewer(?DiffViewer $dv): self
    {
        return $this->withAll(
            error: $this->error,
            successMessage: $this->successMessage,
            diffViewer: $dv,
            diffViewerSet: true,
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
        // candy-git-059: hunkIndex() resolves the stored line back to the
        // 0-based position; withHunkIndex() owns the clamping.
        return $this->withDiffViewer($dv->withHunkIndex($dv->hunkIndex() + $dir));
    }

    /** Stage the currently selected hunk in the diff viewer. */
    private function stageCurrentHunk(): self
    {
        $dv = $this->diffViewer;
        if ($dv === null) {
            return $this;
        }
        if ($dv->hunkCount() === 0) {
            // candy-git-073: a diff with no hunks has nothing to stage;
            // refuse cleanly instead of piping an unapplyable blob to git.
            return $this->withError(Lang::t('diff.no_hunks'));
        }
        if ($dv->partialTail && $dv->hunkIndex() === $dv->hunkCount() - 1) {
            // candy-git-040: the capture lost everything past 4 MiB, so the
            // LAST hunk is a beheaded fragment - applying it would feed git a
            // partial blob and fail confusingly. Earlier hunks are complete
            // bytes and stay stageable.
            return $this->withError(Lang::t('diff.partial_tail'));
        }
        try {
            $patch = $dv->currentHunkPatch();
            $this->git->stagePatch($dv->path, $patch);
            $newHistory = $this->requireHistory()->push(HistoryEntry::stagePatch($dv->path, $patch));
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }

        return $this
            ->withAll(history: $newHistory)
            ->rebuildDiffViewer(Lang::t('diff.hunk_staged'));
    }

    /**
     * Unstage the selected hunk on the cached side (candy-git-034). The
     * viewer reaches this side from a staged-only status row; before this
     * row, Space on a --cached diff re-applied an already-staged hunk and
     * unstage was reachable only through history undo.
     * Armed twice like every other index edit: the first press states the
     * target in the notice lane, git runs only on the second.
     */
    private function unstageCurrentHunk(): self
    {
        $dv = $this->diffViewer;
        if ($dv === null) {
            return $this;
        }
        if ($dv->hunkCount() === 0) {
            return $this->withError(Lang::t('diff.no_hunks'));
        }
        if ($dv->partialTail && $dv->hunkIndex() === $dv->hunkCount() - 1) {
            // candy-git-040: same beheaded-tail refusal on the index side -
            // a reverse patch of a partial hunk would damage the index.
            return $this->withError(Lang::t('diff.partial_tail'));
        }
        $arm = 'unstagehunk:' . $dv->path . ':' . $dv->hunkIndex();
        if ($this->pendingConfirm !== $arm) {
            return $this->armConfirm($arm, Lang::t('confirm.unstage_hunk'));
        }
        $base = $this->clearConfirm();
        try {
            $patch = $dv->currentHunkPatch();
            $this->git->unstagePatch($dv->path, $patch);
            $newHistory = $this->requireHistory()->push(HistoryEntry::unstagePatch($dv->path, $patch));
        } catch (\RuntimeException $e) {
            return $base->withError($e->getMessage());
        }

        return $base
            ->withAll(history: $newHistory)
            ->rebuildDiffViewer(Lang::t('diff.hunk_unstaged'));
    }

    /**
     * Re-run the viewer's own diff in place after a hunk left it
     * (candy-git-036: closing the viewer turned per-hunk staging into an
     * open/stage/reopen loop — the pre-image context of the other hunks is
     * untouched by staging hunk k, so the review should continue right
     * where it was). Lands on the next remaining hunk: the staged one is
     * gone from this side, so the same 0-based index now points forward.
     * If the side has nothing left to show, the overlay closes honestly.
     */
    private function rebuildDiffViewer(string $success): self
    {
        $dv = $this->diffViewer;
        if ($dv === null) {
            return $this;
        }
        try {
            $lines = $this->git->diff($dv->path, $dv->cached);
        } catch (\RuntimeException $e) {
            // The edit itself worked; losing the fresh view must not hide
            // that. Close with the error surfaced, exactly as the old
            // close-and-error path behaved.
            return $this->withDiffViewer(null)->refresh()->withError($e->getMessage());
        }

        $fresh = DiffViewer::fromRawDiff($dv->path, $lines, $dv->cached);
        $base = $this->refresh();
        if ($fresh->hunkCount() === 0) {
            return $base
                ->withDiffViewer(null)
                ->withAll(successMessage: $success);
        }
        $target = $fresh->withHunkIndex($dv->hunkIndex());

        return $base
            ->withDiffViewer($target)
            ->withAll(successMessage: $success);
    }

    /**
     * Open the commit collector PRE-FILLED with the last commit's full
     * message, in amend mode (candy-git-013, GAP-13: 'A' amended --no-edit
     * on the spot and the message could never be edited at all). Submitting
     * the buffer unchanged reproduces the old keypress exactly.
     */
    private function startAmend(): self
    {
        try {
            $message = $this->git->lastCommitMessage();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }

        return $this->withCommitCollection(true, $message)->withAll(commitAmend: true);
    }

    /** Open inline branch name collection. */
    private function startCreateBranch(): self
    {
        // From a log row, `n` branches from THAT commit (candy-git-038);
        // from anywhere else it keeps the HEAD default.
        $base = null;
        if ($this->pane === Pane::Log) {
            $row = $this->log[$this->logCursor] ?? null;
            if (is_array($row) && isset($row['sha']) && is_string($row['sha'])) {
                $base = $row['sha'];
            }
        }

        return $this->withAll(
            collectingBranchName: true,
            branchName: '',
            pendingBranchBase: $base,
            pendingBranchBaseSet: true,
        );
    }

    private function withBranchCollection(bool $collecting, string $name): self
    {
        return $this->withAll(collectingBranchName: $collecting, branchName: $name, pendingBranchBase: null, pendingBranchBaseSet: true);
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
        // Captured BEFORE checkout -b moves HEAD (candy-git-016): the
        // inverse has to know which branch to walk back to.
        $from = $this->currentBranchName();
        try {
            $this->git->createBranch($this->branchName, $this->pendingBranchBase);
            return $this
                ->withAll(history: $this->requireHistory()->push(HistoryEntry::createBranch($this->branchName, $from, (string) ($this->pendingBranchBase ?? ''))))
                ->withBranchCollection(false, '')
                ->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /**
     * The history manager is an optional ctor collaborator (App::start() always
     * supplies one; hand-constructed Apps may not). Dereferencing null on 'a'
     * or 'u' fataled, so every mutation path routes through here for an honest
     * error line instead. RuntimeException rides the surrounding catches.
     */
    private function requireHistory(): HistoryManager
    {
        if ($this->history === null) {
            throw new \RuntimeException(Lang::t('history.unavailable'));
        }
        return $this->history;
    }

    /** Undo the last operation. */
    private function executeUndo(): self
    {
        if ($this->history === null) {
            return $this->withError(Lang::t('history.unavailable'));
        }
        if (!$this->history->canUndo()) {
            return $this->withError(Lang::t('history.nothing_to_undo'));
        }
        // Peek BEFORE popping: an unrecoverable inverse must leave the history
        // stacks untouched (undo() would move the entry onto the redo stack
        // even when the refusal keeps the repo unchanged).
        $undoStack = $this->history->undoStack;
        $top       = $undoStack[array_key_last($undoStack)];
        if ($top->inverseOp === HistoryEntry::UNRECOVERABLE_INVERSE) {
            return $this->withError(Lang::t('history.cannot_undo', ['op' => $this->opLabel($top->op)]));
        }
        $result = $this->history->undo();
        $entry = $result['entry'];
        $newHistory = $result['manager'];
        if ($entry === null) {
            return $this->withError(Lang::t('history.nothing_to_undo'));
        }
        return $this->applyHistoryEntry($entry, false, $newHistory);
    }

    /** Redo the last undone operation (re-applies the FORWARD op, which is
     * always real work — unrecoverability is a property of inverses only). */
    private function executeRedo(): self
    {
        if ($this->history === null) {
            return $this->withError(Lang::t('history.unavailable'));
        }
        if ($this->history->canRedo() === FALSE) {
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
            'deleteUntracked' => $this->git->deleteUntracked($args['path']),
                'checkout' => $this->git->checkout($args['branch']),
                'commit' => $this->git->commit($args['message'] ?? ''),
                'reset' => $this->git->reset(),
                'amend' => $this->git->amend(),
                'createBranch' => $this->git->createBranch($args['name'], ($args['base'] ?? '') !== '' ? (string) $args['base'] : null),
                'removeBranch' => $this->undoCreateBranch($args),
                'deleteBranch' => $this->git->deleteBranch($args['name']),
                'restoreBranch' => $this->git->restoreBranch($args['name'], $args['sha']),
                'stageAll' => $this->git->stageAll(),
                'unstageAll' => $this->git->unstageAll(),
                'stagePatch' => $this->git->stagePatch($args['path'], $args['hunk']),
                'unstagePatch' => $this->git->unstagePatch($args['path'], $args['hunk']),
                'merge' => $this->git->merge($args['branch']),
                'abort' => $this->git->rebaseAbort(),
                'revert' => $this->git->revert($args['sha']),
                'resetHard' => $this->git->resetHard($args['sha']),
                // Belt to executeUndo's peek: if a refuse-shaped inverse ever
                // reaches here (via a future caller), fail loud, never silent.
                HistoryEntry::UNRECOVERABLE_INVERSE => throw new \RuntimeException(
                    Lang::t('history.cannot_undo', ['op' => $this->opLabel($entry->op)]),
                ),
                // An op nobody implements must NOT be reported as "undone" —
                // the old `default => null` claimed success on garbage.
                default => throw new \RuntimeException(Lang::t('history.unknown_op', ['op' => $op])),
            };
            // A redo must not announce itself as an undo; both
            // lanes name the operation through its humanized label.
            $label  = $this->opLabel($entry->op);
            $msg    = $forward
                ? Lang::t('history.redone', ['op' => $label])
                : Lang::t('history.undone', ['op' => $label]);
            $refreshed = $this->refresh();
            if ($newHistory !== null) {
                $refreshed = $refreshed->withAll(history: $newHistory);
            }
            $result = $refreshed->withAll(successMessage: $msg);
            if (!$forward && $entry->op === 'commit') {
                // candy-git-043: undoing a commit reset the boundary and
                // used to drop the typed message with it — the collector is
                // the only authoring path, so re-arm it with the exact bytes
                // the history entry captured.
                $result = $result->withCommitCollection(true, (string) ($entry->args['message'] ?? ''));
            }

            return $result;
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /**
     * Inverse of a branch creation (candy-git-016): walk back to the
     * recorded origin first, then delete the fresh ref. Deleting while the
     * new branch is checked out is exactly what git's `used by worktree`
     * refusal punished; the paired order restores both the ref and HEAD to
     * where they stood before `n` ran.
     *
     * @param array<string, mixed> $args
     */
    private function undoCreateBranch(array $args): void
    {
        $this->git->checkout((string) $args['from']);
        $this->git->deleteBranch((string) $args['name']);
    }

    /**
     * Human-readable name for a history op: 'createBranch'
     * becomes "branch creation" via the history.op.<op> keys. Unknown ops
     * fall back to the raw identifier — Lang::t returns the key itself
     * when no translation exists, so the substitution is detectable.
     */
    private function opLabel(string $op): string
    {
        $key = 'history.op.' . $op;
        $value = Lang::t($key);

        return $value === $key ? $op : $value;
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
        if ($branch['remote'] ?? false) {
            // A remote-tracking ref is not ours to delete; the branch family
            // only manages locals (candy-git-037).
            return $this->withError(Lang::t('branch.delete_remote'));
        }
        // Branch deletion needs the same armed second press as discard.
        $arm = 'branch:' . $branch['name'];
        if ($this->pendingConfirm !== $arm) {
            return $this->armConfirm($arm, Lang::t('confirm.delete_branch', ['name' => (string) $branch['name']]));
        }
        $base = $this->clearConfirm();
        try {
            $this->git->deleteBranch($branch['name']);
            return $base
                ->withAll(history: $base->requireHistory()->push(HistoryEntry::deleteBranch(
                    (string) $branch['name'],
                    (string) ($branch['sha'] ?? ''),
                )))
                ->refresh()
                ->withAll(successMessage: Lang::t('branch.deleted', ['name' => $branch['name']]));
        } catch (\RuntimeException $e) {
            return $base->withError($e->getMessage());
        }
    }

    /** Start collecting merge target branch name. */
    private function startMerge(): self
    {
        // `M` with the cursor on a local branch row merges THAT branch
        // (candy-git-038): the buffer opens seeded, still editable. The
        // current branch and remote-tracking rows seed nothing - merging
        // into yourself is nonsense and the tracking ref is not a checkout
        // target this menu should imply.
        $seed = '';
        if ($this->pane === Pane::Branches) {
            $row = $this->branches[$this->branchesCursor] ?? null;
            if (
                is_array($row)
                && empty($row['current'])
                && empty($row['remote'])
                && isset($row['name'])
                && is_string($row['name'])
            ) {
                $seed = $row['name'];
            }
        }

        return $this->withMergeCollection(true, $seed);
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
                ->withAll(history: $this->requireHistory()->push(HistoryEntry::merge($this->mergeTarget)))
                ->withMergeCollection(false, '')
                ->refresh()
                ->withAll(successMessage: Lang::t('merge.success', ['branch' => $this->mergeTarget]));
        } catch (\RuntimeException $e) {
            // A conflicted merge leaves rows in the index; refresh so they are
            // visible, and name the failure in human terms when git reports
            // conflicts.
            $msg = str_contains($e->getMessage(), 'CONFLICT')
                ? Lang::t('merge.conflict', ['branch' => $this->mergeTarget])
                : $e->getMessage();
            return $this->withMergeCollection(false, '')->refresh()->withError($msg);
        }
    }

    /** Handle 'r' key: open the sequencer menu for whichever operation stopped. */
    private function handleRebaseKey(): self
    {
        // The probe shells out (git rev-parse); a failing git must land in the
        // error lane like every other operation, not as an uncaught fatal
        // that takes the TUI down.
        try {
            // One menu serves every sequencer stop: rebase, cherry-pick, and
            // a conflicted merge all leave continue/abort needs (CG-14 — the
            // merge stop used to get a hint-only error, so a merge started
            // in-app could not be finished or abandoned in-app).
            $state = $this->git->sequenceState();
            if ($state === 'rebase' || $state === 'cherry' || $state === 'merge') {
                return $this->withRebaseMenu(true, $state);
            }
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
        return $this->withError(Lang::t('rebase.no_rebase'));
    }

    private function withRebaseMenu(bool $show, string $kind = 'rebase'): self
    {
        return $this->withAll(showRebaseMenu: $show, rebaseMenuKind: $show ? $kind : null);
    }

    private function executeRebaseContinue(): self
    {
        try {
            $this->git->rebaseContinue();
            return $this->withRebaseMenu(false)->refresh()->withAll(
                successMessage: Lang::t('rebase.success', ['action' => Lang::t('rebase.continue')])
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
                successMessage: Lang::t('rebase.success', ['action' => Lang::t('rebase.abort')])
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
                successMessage: Lang::t('rebase.success', ['action' => Lang::t('rebase.skip')])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function executeCherryPickContinue(): self
    {
        try {
            $this->git->cherryPickContinue();
            return $this->withRebaseMenu(false)->refresh()->withAll(
                successMessage: Lang::t('rebase.success', ['action' => Lang::t('rebase.continue')])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function executeCherryPickAbort(): self
    {
        try {
            $this->git->cherryPickAbort();
            return $this->withRebaseMenu(false)->refresh()->withAll(
                successMessage: Lang::t('rebase.success', ['action' => Lang::t('rebase.abort')])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Continue leg of a stopped merge: commits the recorded resolution. */
    private function executeMergeContinue(): self
    {
        try {
            $this->git->mergeContinue();
            return $this->withRebaseMenu(false)->refresh()->withAll(
                successMessage: Lang::t('merge.sequence_success', ['action' => Lang::t('rebase.continue')])
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    /** Abort leg of a stopped merge: git restores the pre-merge state. */
    private function executeMergeAbort(): self
    {
        try {
            $this->git->mergeAbort();
            return $this->withRebaseMenu(false)->refresh()->withAll(
                successMessage: Lang::t('merge.sequence_success', ['action' => Lang::t('rebase.abort')])
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

    /** Explicit-set stashManager swap — the null argument MEANS "close the overlay",
     * which withAll's `?? $this->stashManager` keep-existing fallthrough cannot express
     * unaided, hence the stashManagerSet sentinel (single construction site).
     * error/successMessage ride through unchanged, matching the old full-arg bypass.
     */
    private function withStashManager(?StashManager $sm): self
    {
        return $this->withAll(
            error: $this->error,
            successMessage: $this->successMessage,
            stashManager: $sm,
            stashManagerSet: true,
        );
    }

    private function navigateStash(int $dir): self
    {
        $sm = $this->stashManager;
        if ($sm === null) return $this;
        return $this->withStashManager($sm->withCursor($dir));
    }

    /** Open-or-close for the stash message buffer (candy-git-014): closing
     * always drops the untracked tag with it, so a stale -u can never ride
     * into the next push. */
    private function withStashCollection(bool $collecting, string $message): self
    {
        return $this->withAll(collectingStash: $collecting, stashMessage: $message, stashUntracked: false);
    }

    /** 's' pushes tracked work, 'u' pushes tracked + untracked (-u); both
     * open the message buffer first — Enter on an empty buffer lets git
     * decorate the entry itself (WIP on <branch>). */
    private function startStashPush(bool $untracked): self
    {
        return $this->withAll(collectingStash: true, stashMessage: '', stashUntracked: $untracked);
    }

    private function executeStashPush(): self
    {
        $sm = $this->stashManager;
        if ($sm === null) return $this;
        try {
            $this->git->stashPush($this->stashMessage === '' ? null : $this->stashMessage, false, $this->stashUntracked);
            $fresh = new StashManager($this->git->stashList());
            return $this->withStashCollection(false, '')
                ->withStashManager($fresh)
                ->refresh()
                ->withAll(successMessage: Lang::t('stash.pushed'));
        } catch (\RuntimeException $e) {
            // The buffer closes on failure too — its bytes describe a push
            // that did not happen — but the overlay stays open for a retry.
            return $this->withStashCollection(false, '')->withError($e->getMessage());
        }
    }

    private function executeStashPop(): self
    {
        $sm = $this->stashManager;
        if ($sm === null) return $this;
        $current = $sm->current();
        if ($current === null) return $this;
        // Pop consumes the entry (apply + drop): arm like a drop, since the
        // stash copy is destroyed either way (candy-git-014).
        $arm = 'stashpop:' . $current->stashRef();
        if ($this->pendingConfirm !== $arm) {
            return $this->armConfirm($arm, Lang::t('confirm.stash_pop', ['ref' => $current->stashRef()]));
        }
        $base = $this->clearConfirm();
        try {
            $this->git->stashPop($current->stashRef());
            $fresh = new StashManager($this->git->stashList());
            return $base->withStashManager($fresh)->refresh()->withAll(
                successMessage: Lang::t('stash.popped', ['ref' => $current->stashRef()])
            );
        } catch (\RuntimeException $e) {
            return $base->withError($e->getMessage());
        }
    }

    private function executeStashApply(): self
    {
        $sm = $this->stashManager;
        if ($sm === null) return $this;
        $current = $sm->current();
        if ($current === null) return $this;
        try {
            $this->git->stashApply($current->stashRef());
            // The overlay stays open (candy-git-076): closing it sent the
            // next keystrokes to the base UI, where a documented stash `d`
            // armed a status-pane file discard instead. apply does not
            // change the list, so the manager is re-passed untouched;
            // refresh() keeps it (keep-existing fallthrough) and reloads
            // the worktree panes the apply just dirtied.
            return $this->refresh()->withAll(
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
        // Dropping a stash discards its only copy - arm first.
        $arm = 'stash:' . $current->stashRef();
        if ($this->pendingConfirm !== $arm) {
            return $this->armConfirm($arm, Lang::t('confirm.stash_drop', ['ref' => $current->stashRef()]));
        }
        $base = $this->clearConfirm();
        try {
            $this->git->stashDrop($current->stashRef());
            $freshStashes = $this->git->stashList();
            return $base->withStashManager(new StashManager($freshStashes))->withAll(
                successMessage: Lang::t('stash.dropped', ['ref' => $current->stashRef()])
            );
        } catch (\RuntimeException $e) {
            return $base->withError($e->getMessage());
        }
    }

    // ---- Cherry-pick ----

    private function startCherryPick(): self
    {
        // From the log the visible sha IS the seed (candy-git-038); the
        // buffer stays editable so the user can still aim elsewhere.
        $row  = $this->pane === Pane::Log ? ($this->log[$this->logCursor] ?? null) : null;
        $seed = is_array($row) && isset($row['sha']) && is_string($row['sha']) ? $row['sha'] : '';

        return $this->withCherryPick($seed !== ''
            ? CherryPick::collecting()->withCommitRef($seed)
            : CherryPick::collecting());
    }

    /**
     * Open the read-only commit display for the log cursor row
     * (candy-git-015): full message, stat, and patch from `git show`,
     * windowed by the same scroll machinery as a file diff.
     */
    private function openCommitDetails(): self
    {
        $row = $this->log[$this->logCursor] ?? null;
        if (!is_array($row) || !isset($row['sha']) || !is_string($row['sha']) || $row['sha'] === '') {
            return $this;
        }
        try {
            $lines = $this->git->commitDetails($row['sha']);
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }

        return $this->withDiffViewer(DiffViewer::fromCommitDetails($row['sha'], $lines));
    }

    /** Revert the displayed commit - armed, press r twice (candy-git-015). */
    private function executeRevert(string $sha): self
    {
        $arm = 'revert:' . $sha;
        if ($this->pendingConfirm !== $arm) {
            return $this->armConfirm($arm, Lang::t('confirm.revert', ['sha' => $sha]));
        }
        $base = $this->clearConfirm();
        try {
            $this->git->revert($sha);
        } catch (\RuntimeException $e) {
            return $base->withError($e->getMessage());
        }

        return $base
            ->withAll(history: $this->requireHistory()->push(HistoryEntry::revert($sha)))
            ->refresh()
            ->withAll(successMessage: Lang::t('revert.done', ['sha' => $sha]));
    }

    /** Move HEAD onto the displayed commit - armed twice (candy-git-015). */
    private function executeResetHard(string $sha): self
    {
        $arm = 'resethard:' . $sha;
        if ($this->pendingConfirm !== $arm) {
            return $this->armConfirm($arm, Lang::t('confirm.reset_hard', ['sha' => $sha]));
        }
        $base = $this->clearConfirm();
        try {
            $this->git->resetHard($sha);
        } catch (\RuntimeException $e) {
            return $base->withError($e->getMessage());
        }

        return $base
            ->withAll(history: $this->requireHistory()->push(HistoryEntry::resetHard($sha)))
            ->refresh()
            ->withAll(successMessage: Lang::t('reset.done', ['sha' => $sha]));
    }

    /** Explicit-set cherryPick swap — the null argument MEANS "close the overlay",
     * which withAll's `?? $this->cherryPick` keep-existing fallthrough cannot express
     * unaided, hence the cherryPickSet sentinel (single construction site).
     * error/successMessage ride through unchanged, matching the old full-arg bypass.
     */
    private function withCherryPick(?CherryPick $cp): self
    {
        return $this->withAll(
            error: $this->error,
            successMessage: $this->successMessage,
            cherryPick: $cp,
            cherryPickSet: true,
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
            // A stopped cherry-pick needs the same continue/abort path a
            // rebase stop does.
            return $this->withCherryPick(null)->refresh()->withAll(
                showRebaseMenu: true, rebaseMenuKind: 'cherry', error: $e->getMessage()
            );
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

    /** Explicit-set worktrees swap — the null argument MEANS "close the overlay",
     * which withAll's `?? $this->worktrees` keep-existing fallthrough cannot express
     * unaided, hence the worktreesSet sentinel (single construction site).
     * error/successMessage ride through unchanged, matching the old full-arg bypass.
     */
    private function withWorktrees(?Worktrees $wt): self
    {
        return $this->withAll(
            error: $this->error,
            successMessage: $this->successMessage,
            worktrees: $wt,
            worktreesSet: true,
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
            // An empty branch lets git name the new branch from the path
            // basename; a typed one is created explicitly. Either way the
            // worktree is born ON A BRANCH - 'HEAD' here meant detached.
            $branch = $wt->newBranch;
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

    /** Explicit-set interactiveRebase swap — the null argument MEANS "close the overlay",
     * which withAll's `?? $this->interactiveRebase` keep-existing fallthrough cannot express
     * unaided, hence the interactiveRebaseSet sentinel (single construction site).
     * error/successMessage ride through unchanged, matching the old full-arg bypass.
     */
    private function withInteractiveRebase(?InteractiveRebase $ir): self
    {
        return $this->withAll(
            error: $this->error,
            successMessage: $this->successMessage,
            interactiveRebase: $ir,
            interactiveRebaseSet: true,
        );
    }

    private function confirmRebaseCount(): self
    {
        $ir = $this->interactiveRebase;
        if ($ir === null || $ir->selectingN === FALSE) return $this;
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

    /**
     * The park-G1 refusal: answers a mutating todo key with the banner's own
     * words on the amber notice lane, leaving the preview untouched. The
     * interactiveRebase re-pass keeps the built todo exactly as it was — a
     * refusal must not rebuild (or reorder) what the user is reading.
     */
    private function refuseParkedRebase(): self
    {
        return $this->withAll(
            interactiveRebase: $this->interactiveRebase,
            interactiveRebaseSet: true,
            error: $this->error,
            notice: Lang::t('rebase_i.preview_only'),
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
