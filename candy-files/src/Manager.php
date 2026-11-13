<?php

declare(strict_types=1);

namespace SugarCraft\Files;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ExecMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyboardEnhancementsMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Editor;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Undo\UndoActionType;
use SugarCraft\Files\Manager\ManagerBuilder;
use SugarCraft\Files\Msg\CopyCompletedMsg;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * The dual-pane file manager `Model`.
 *
 * No upstream — house pattern (finding 051). yorukot/superfile splits its
 * state across several bubbletea model files and ships no single Manager
 * symbol; this TEA model (update/view/subscriptions) is the library's own.
 *
 * Holds two {@see Pane}s plus an active-pane index (Tab swaps),
 * a status line, and a confirmation-state enum so destructive
 * operations route through an explicit gate: "press `y` — or the
 * affirmative the prompt's locale teaches — to confirm, anything
 * else to cancel".
 *
 * Mutations (delete) are gated by ConfirmState. The first press
 * of `d` arms the confirmation; the next `y` (or the localized
 * affirmative) actually deletes. Any other key cancels. This is
 * one TUI pattern that's worth the extra state — accidental
 * deletes are too annoying to ship.
 *
 * Filesystem reads happen through an injected lister closure.
 * `FsLister::lister()` is the prod default; tests pass a fake.
 */
final class Manager implements Model
{
    use SubscriptionCapable;

    /**
     * Immutable-with helper. Rebuilds via the constructor with named args, so
     * the derived {@see $lister} (already resolved to a non-null closure on the
     * live instance) round-trips unchanged and no ctor-side logic is skipped.
     */
    use Mutable;

    /** @var \Closure(string): list<Entry>|null */
    private readonly \Closure $lister;

    private const UNDO_LIMIT = 50;

    /** @var int Result ceiling for a live-height-less search (CF-02's window math caps the real frame). */
    private const SEARCH_CAP = 20;

    /**
     * Per-process trash directory, created lazily on first delete. @see trashRoot()
     *
     * DESIGN NOTE (deliberate, pre-1.0): this trash is EPHEMERAL. The
     * shutdown handler registered in start() removes the whole directory
     * when the process exits, so `delete` is recoverable via undo (u)
     * ONLY within the same candy-files session — quit, crash, or reboot
     * and the deleted bytes are gone for good. A persistent, XDG-trash
     * spec-compatible bin with cross-session restore is a separate
     * feature, intentionally not built here.
     */
    private static ?string $trashRoot = null;

    /**
     * @param (\Closure(string): list<Entry>|null)|null $lister
     * @param array<int,array{left:Pane,right:Pane,activeIdx:int}> $tabs
     * @param list<UndoAction> $undoStack Stack of undoable actions
     * @param list<UndoAction> $redoStack Stack of redoable actions (cleared on new action)
     * @param int $termCols Live terminal width in cells (0 = unknown, Renderer falls back to its fixed 40-cell pane)
     * @param int $termRows Live terminal height in rows (0 = unknown, Renderer lists every entry — the legacy unwindowed frame)
     */
    public function __construct(
        public readonly Pane $left,
        public readonly Pane $right,
        public readonly int $activeIdx = 0,
        public readonly string $status = '',
        public readonly ConfirmState $confirm = ConfirmState::None,
        ?\Closure $lister = null,
        public readonly ?string $searchQuery = null,
        public readonly array $searchResults = [],
        public readonly int $searchCursor = 0,
        public readonly array $tabs = [],
        public readonly int $tabIndex = 0,
        public readonly array $undoStack = [],
        public readonly array $redoStack = [],
        public readonly ?string $pendingOpDest = null,
        public readonly ?string $pendingOpType = null,
        public readonly ?string $inputBuffer = null,
        public readonly int $termCols = 0,
        public readonly int $termRows = 0,
        /** CF-44: async copy batches scheduled but not yet reported complete. */
        public readonly int $copyBatchesInFlight = 0,
        /** CF-44: first `q` landed while a batch was in flight; second press quits. */
        public readonly bool $quitArmed = false,
        /** CF-28: `?` opens the help overlay — a frame, not a side-effecting mode. */
        public readonly bool $helpMode = false,
    ) {
        $this->lister = $lister ?? FsLister::lister();
    }

    /**
     * @param (\Closure(string): list<Entry>|null)|null $lister
     */
    public static function start(string $leftCwd, string $rightCwd, ?\Closure $lister = null): self
    {
        $lister ??= FsLister::lister();
        $left = Pane::open($leftCwd, $lister);
        $right = Pane::open($rightCwd, $lister);
        // Initialize with 1 tab containing the dual panes
        $initialTab = [
            'left' => $left,
            'right' => $right,
            'activeIdx' => 0,
        ];

        // Register shutdown handler to clean up trash directory
        static $shutdownRegistered = false;
        if ($shutdownRegistered === false) {
            $shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                // Clean up exactly the (randomly named) trash root we created,
                // if any deletes happened this process.
                if (self::$trashRoot !== null && \is_dir(self::$trashRoot)) {
                    self::removePath(self::$trashRoot);
                }
            });
        }

        return new self(
            $left,
            $right,
            lister: $lister,
            tabs: [$initialTab],
            tabIndex: 0,
            undoStack: [],
            redoStack: [],
            pendingOpDest: null,
            pendingOpType: null,
            inputBuffer: null,
        );
    }

    public function init(): ?\Closure
    {
        // Ask kitty-capable terminals (kitty, WezTerm, foot, contour, ...)
        // to disambiguate their key reporting, so Ctrl+Tab / Ctrl+Shift+Tab
        // arrive as `CSI 9;5u` / `CSI 9;6u` instead of a bare 0x09 byte
        // indistinguishable from plain Tab. Terminals without the kitty
        // protocol ignore the push bytes; sugar-core Program's shutdown
        // release writes the matching pop unconditionally, so the flag
        // stack can never strand on exit.
        return Cmd::pushKittyKeyboard(KeyboardEnhancementsMsg::DISAMBIGUATE);
    }

    /**
     * Create a Manager via a fluent builder.
     */
    public static function builder(): ManagerBuilder
    {
        return new ManagerBuilder();
    }

    public function update(Msg $msg): array
    {
        // Terminal resize: capture the live width AND height so the next
        // view() derives pane widths from it and windows the entry list to
        // fit the rows. sugar-core's Program emits WindowSizeMsg at
        // startup and on every SIGWINCH, then re-renders — handling it here
        // is what makes the layout responsive. CF-02: the arm used to
        // compare cols only and DISCARD msg->rows, so a rows-only resize
        // (window dragged vertically, tmux split re-flow) returned the same
        // Model and the frame stayed stale. No Cmd: re-render is the
        // Program's dirty-flag step.
        if ($msg instanceof WindowSizeMsg) {
            if ($msg->cols === $this->termCols && $msg->rows === $this->termRows) {
                return [$this, null];
            }
            return [$this->mutate(['termCols' => $msg->cols, 'termRows' => $msg->rows]), null];
        }

        // Async copy completion: the Cmd ran on the loop, files landed in the
        // destination pane's directory. Re-read both listings, report the
        // honest outcome (per-file errors included), and finalize undo state.
        if ($msg instanceof CopyCompletedMsg) {
            return [$this->handleCopyCompleted($msg), null];
        }

        // CF-03: a spawned external program (editor / pager / runner) has
        // exited and the runtime restored the screen. Refresh the pane the
        // program may have mutated (an editor rewrites the file; a script
        // can touch anything in the cwd) and report the exit honestly.
        if ($msg instanceof ExecMsg) {
            $paneName = $this->activePane()->currentEntry()?->name;
            $label = $paneName !== null ? Entry::sanitizeName($paneName) : '';
            $status = $msg->exitCode === 0
                ? Lang::t('status.opened', ['name' => $label])
                : Lang::t('status.editor_failed', ['name' => $label, 'code' => $msg->exitCode]);
            return [$this->refreshAllPanes()->withStatus($status), null];
        }

        if (($msg instanceof KeyMsg) === false) {
            return [$this, null];
        }

        // Confirmation gate consumes the next keystroke entirely.
        if ($this->confirm !== ConfirmState::None) {
            [$resolved, $cmd] = $this->resolveConfirm($msg);
            return [$resolved, $cmd];
        }

        // CF-28: the help overlay swallows every keystroke except its own
        // dismissal. It is a frame — no cursor moves, no mode changes hide
        // behind it.
        if ($this->helpMode) {
            if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === '?')) {
                return [$this->mutate(['helpMode' => false]), null];
            }
            return [$this, null];
        }

        // Search mode intercepts all keys
        if ($this->searchQuery !== null) {
            return [$this->handleSearchKey($msg), null];
        }

        // CF-28: `?` opens the overlay. Search mode was already intercepted
        // above, so a '?' typed into a query can never open help by accident.
        if ($msg->type === KeyType::Char && $msg->rune === '?' && !$msg->ctrl && !$msg->alt) {
            return [$this->mutate(['helpMode' => true]), null];
        }

        if ($msg->type === KeyType::Char && $msg->rune === 'q' && !$msg->ctrl && !$msg->alt) {
            // CF-44: quitting while a copy batch is in flight needs a second
            // press. The shutdown handler will remove the ephemeral trash
            // root and the loop dies mid-batch — bytes half-landed, undo
            // stack unreachable — so the first press only arms the question,
            // naming how many batches are still running.
            if ($this->copyBatchesInFlight > 0 && !$this->quitArmed) {
                return [$this->mutate([
                    'quitArmed' => true,
                    'status' => Lang::t('status.quit_armed', ['count' => $this->copyBatchesInFlight]),
                ]), null];
            }
            return [$this, Cmd::quit()];
        }

        // Any other keystroke drops the pending quit question (CF-44) —
        // the armed state is about THIS batch window, not the session, and
        // the question is chrome: a stale copy of it would keep pleading
        // for a second press that no longer quits.
        $model = $this->quitArmed ? $this->mutate(['quitArmed' => false, 'status' => '']) : $this;

        return $model->dispatch($msg);
    }

    /**
     * Finalize an async copy that the Cmd completed on the event loop.
     *
     * The pre-completion state armed by performCopyAsync() says "copying…"
     * (nothing has landed yet); this is where the truth from the wire lands.
     * Counts are ACTIONS APPLIED, never arm-time intentions: $copiedItems
     * carries only the legs that really wrote, $errors counts failed legs,
     * $skipped counts names filtered before scheduling, and $overwrote
     * counts successful legs whose destination already existed at schedule
     * time (copy silently replaces — users must be able to see it happened).
     * Status precedence is errors > skipped > overwrote > plain; both
     * copy-undo and the entry refresh read the honest ok-set.
     */
    private function handleCopyCompleted(CopyCompletedMsg $msg): self
    {
        $applied = count($msg->copiedItems);
        $status = match (true) {
            // CF-21: a thrown leg names its first reason after the localized
            // count frame (the diagnostic itself is untranslated technical
            // text; exception messages can echo paths, so it passes through
            // the same sanitize choke as every other name-bearing write).
            $msg->errors > 0 => Lang::t('status.copied_with_errors', ['count' => $applied, 'errors' => $msg->errors])
                . ($msg->errorReason !== null ? ': ' . Entry::sanitizeName($msg->errorReason) : ''),
            $msg->skipped > 0 => Lang::t('status.copied_skipped', ['count' => $applied, 'skipped' => $msg->skipped]),
            $msg->overwrote > 0 => Lang::t('status.copied_overwrote', ['count' => $applied, 'overwrote' => $msg->overwrote]),
            default => Lang::t('status.copied', ['count' => $applied]),
        };
        $newUndoStack = $this->undoStack;
        if ($msg->copiedItems !== []) {
            $newUndoStack[] = UndoAction::copy($msg->copiedItems);
        }
        $newUndoStack = array_slice($newUndoStack, -self::UNDO_LIMIT);
        return $this->refreshAllPanes()
            ->withConfirm(ConfirmState::None, $status)
            ->withUndoRedoStacks($newUndoStack, []) // Clear redo on new action
            // CF-44: this completion retires exactly one armed batch.
            ->mutate(['copyBatchesInFlight' => max(0, $this->copyBatchesInFlight - 1)]);
    }

    /**
     * Re-read both pane listings from the filesystem, preserving each
     * pane's cwd / sort / hidden state and the tab they live in.
     */
    private function refreshAllPanes(): self
    {
        $lister = $this->lister;
        $reopen = static fn(Pane $p): Pane => Pane::open($p->cwd, $lister, $p->sort, $p->showHidden);
        if ($this->tabs !== [] && ($tab = $this->tabs[$this->tabIndex] ?? null) !== null) {
            $newTab = ['left' => $reopen($tab['left']), 'right' => $reopen($tab['right']), 'activeIdx' => $tab['activeIdx']];
            $newTabs = $this->tabs;
            $newTabs[$this->tabIndex] = $newTab;
            return $this->mutate(['left' => $newTab['left'], 'right' => $newTab['right'], 'tabs' => $newTabs]);
        }
        return $this->mutate(['left' => $reopen($this->left), 'right' => $reopen($this->right)]);
    }

    /**
     * CF-20 (closes audit 028): the cursor-preserving twin of
     * refreshAllPanes for the undo/redo tails — relist (not Pane::open) so
     * a re-read does not silently wipe cursor positions or the entries of
     * the selection that survived, and so BOTH panes re-read: an undo that
     * re-homes bytes out of the inactive pane must not leave it showing a
     * phantom.
     */
    private function relistAllPanes(): self
    {
        $lister = $this->lister;
        $relist = static fn(Pane $p): Pane => $p->relist($lister);
        if ($this->tabs !== [] && ($tab = $this->tabs[$this->tabIndex] ?? null) !== null) {
            $newTab = ['left' => $relist($tab['left']), 'right' => $relist($tab['right']), 'activeIdx' => $tab['activeIdx']];
            $newTabs = $this->tabs;
            $newTabs[$this->tabIndex] = $newTab;
            return $this->mutate(['left' => $newTab['left'], 'right' => $newTab['right'], 'tabs' => $newTabs]);
        }
        return $this->mutate(['left' => $relist($this->left), 'right' => $relist($this->right)]);
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    public function activePane(): Pane
    {
        if ($this->tabs !== [] && ($tab = $this->tabs[$this->tabIndex] ?? null) !== null) {
            return $tab['activeIdx'] === 0 ? $tab['left'] : $tab['right'];
        }
        return $this->activeIdx === 0 ? $this->left : $this->right;
    }

    /**
     * CF-23 (closes audit 032): the single tab-state truth. While a tab
     * exists, its own `activeIdx` IS the answer (the same field
     * Renderer::activeIdx reads); the flat field is only the no-tabs
     * fallback.
     */
    private function effectiveActiveIdx(): int
    {
        $tab = $this->currentTabs();
        return $tab !== null ? (int) $tab['activeIdx'] : $this->activeIdx;
    }

    /**
     * CF-23: commit a new tabs list together with its selection AND keep
     * the flat mirror in sync, so no tab mutator can leave $this->left/
     * $this->right/$this->activeIdx pointing into a stale tab again.
     *
     * @param array<int,array{left:Pane,right:Pane,activeIdx:int}> $newTabs
     */
    private function withTabs(array $newTabs, int $index): self
    {
        if ($newTabs === [] || !isset($newTabs[$index])) {
            throw new \InvalidArgumentException('tab index ' . $index . ' outside ' . count($newTabs) . ' tabs');
        }
        $cur = $newTabs[$index];
        return $this->mutate([
            'left'       => $cur['left'],
            'right'      => $cur['right'],
            'activeIdx'  => $cur['activeIdx'],
            'tabs'       => $newTabs,
            'tabIndex'   => $index,
        ]);
    }

    public function inactivePane(): Pane
    {
        if ($this->tabs !== [] && ($tab = $this->tabs[$this->tabIndex] ?? null) !== null) {
            return $tab['activeIdx'] === 0 ? $tab['right'] : $tab['left'];
        }
        return $this->activeIdx === 0 ? $this->right : $this->left;
    }

    /**
     * Key routing for the idle (no confirm/search) state.
     *
     * @return array{0:self, 1:?\Closure} [next Model, ?Cmd]
     */
    private function dispatch(KeyMsg $msg): array
    {
        // CF-05: shortcut letters are the UNMODIFIED keyboard's domain.
        // InputReader decodes the legacy Ctrl+D byte (0x04) to a Char rune
        // 'd' with ctrl set (ESC-prefixed forms arrive with alt set), so the
        // old rune-only arms captured chords: Ctrl+D armed a delete, Alt+R
        // refreshed, Ctrl+U undid. Every shortcut below keys on this $plain
        // predicate; chords with explicit owners (Ctrl+Z/Y/W) keep their
        // own modified arms further down.
        $plain = $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt;

        // CF-03 (owner's one ruling, shared with CF-54): the open grammar.
        // Enter / Right edit — dirs navigate as they always did, a file
        // under the cursor used to be a SILENT NO-OP (Pane::navigate drops
        // non-dirs), which read as a dead key. `l` is the diamond's right
        // leg: navigate for dirs, $PAGER for text files (a binary/huge
        // sniff refuses into a localized status — never page garbage). `x`
        // runs executables and refuses anything else.
        if ($msg->type === KeyType::Enter || $msg->type === KeyType::Right) {
            return $this->openEntry('edit');
        }
        if ($plain && $msg->rune === 'l') {
            return $this->openEntry('view');
        }
        if ($plain && $msg->rune === 'x') {
            return $this->runEntry();
        }

        $next = match (true) {
            $plain && $msg->rune === '/'
                => $this->search(''),
            // Tab cycling. The parser NEVER puts a "\t" rune on a Char
            // message — bare 0x09 and every modified Tab decode to
            // KeyType::Tab (xterm-family reports Ctrl+Tab as `CSI 1;5I`,
            // Ctrl+Shift+Tab as `CSI 1;6I`; kitty-capable terminals
            // negotiated in init() report `CSI 9;5u` / `CSI 9;6u`).
            // These arms key on TYPE plus the modifier flags and MUST
            // stay above the unmodified-Tab arm below: that arm used to
            // shadow them, so Ctrl+Tab pane-swapped instead of cycling.
            // Ctrl+PageUp / Ctrl+PageDown are the universal alternative:
            // they arrive unambiguously modified even on a plain xterm,
            // where Ctrl+Tab is indistinguishable from Tab.
            $msg->type === KeyType::Tab && $msg->ctrl && $msg->shift,
            $msg->type === KeyType::PageDown && $msg->ctrl
                => $this->tabs === [] ? $this : $this->switchTab(($this->tabIndex - 1 + count($this->tabs)) % count($this->tabs)),
            $msg->type === KeyType::Tab && $msg->ctrl,
            $msg->type === KeyType::PageUp && $msg->ctrl
                => $this->tabs === []
                    ? $this->duplicateTab()
                    : $this->switchTab(($this->tabIndex + 1) % count($this->tabs)),
            $msg->type === KeyType::Tab
                // CF-23 (closes audit 032): key the flip on the RENDERER's
                // source (the tab's own activeIdx), not the stale flat field
                // switchTab left behind.
                => $this->withActive(1 - $this->effectiveActiveIdx()),
            // CF-02: unmodified PageUp/PageDown page the cursor through the
            // entry list (the modified pair cycles tabs — it lives at the top
            // of this match and keeps priority over these arms).
            $msg->type === KeyType::PageDown
                => $this->withActivePane(fn(Pane $p) => $p->moveCursor($this->pageSize())),
            $msg->type === KeyType::PageUp
                => $this->withActivePane(fn(Pane $p) => $p->moveCursor(-$this->pageSize())),
            $msg->type === KeyType::Up,
            $plain && $msg->rune === 'k'
                => $this->withActivePane(fn(Pane $p) => $p->moveCursor(-1)),
            $msg->type === KeyType::Down,
            $plain && $msg->rune === 'j'
                => $this->withActivePane(fn(Pane $p) => $p->moveCursor(1)),
            $msg->type === KeyType::Home,
            $plain && $msg->rune === 'g'
                => $this->withActivePane(fn(Pane $p) => $p->gotoTop()),
            $msg->type === KeyType::End,
            $plain && $msg->rune === 'G'
                => $this->withActivePane(fn(Pane $p) => $p->gotoBottom()),
            $msg->type === KeyType::Left,
            $plain && $msg->rune === 'h'
                => $this->goUp(),
            // Space toggles selection. InputReader decodes a bare 0x20
            // (and the kitty CSI-u code-32 report negotiated in init())
            // to KeyType::Space with rune ' ' — NEVER to a printable
            // Char. The old arm keyed on Char + ' ', a shape the parser
            // cannot emit, so the binding was DEAD in production while
            // fabricated unit pins stayed green (C-1: same trap
            // sugar-core's KeyBindings names the `space` token for; this
            // lib bypasses that registry, so the arm spells the type).
            // The Char leg stays as belt-braces for a future parser
            // revision that surfaces space on the printable side.
            $msg->type === KeyType::Space,
            $plain && $msg->rune === ' '
                => $this->withActivePane(fn(Pane $p) => $p->toggleSelection())
                       ->withActivePane(fn(Pane $p) => $p->moveCursor(1)),
            $plain && $msg->rune === 's'
                => $this->cycleSort(),
            $plain && $msg->rune === '.'
                => $this->withActivePane(fn(Pane $p) => $p->toggleHidden($this->lister)),
            $plain && $msg->rune === 'd'
                => $this->armDelete(),
            $plain && $msg->rune === 'c'
                => $this->armCopy(),
            $plain && $msg->rune === 'm'
                => $this->armMove(),
            $plain && $msg->rune === 'R'
                => $this->armRename(),
            // CF-10: mc-F7/superfile-N baseline — create a directory in the
            // active pane's cwd through the shared text-entry sub-mode.
            $plain && $msg->rune === 'N'
                => $this->armMkdir(),
            $plain && $msg->rune === 'r'
                => $this->refresh(),
            // Escape (outside search/confirm — those consume it earlier):
            // clear the multi-select. Before this arm existed,
            // Pane::clearSelection() had no production caller at all, so a
            // mis-clicked selection could only be undone one entry at a
            // time by re-pressing space.
            $msg->type === KeyType::Escape
                => $this->withActivePane(fn(Pane $p) => $p->clearSelection()),
            $plain && $msg->rune === 'u'
                => $this->undo(),
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'z'
                => $this->undo(),
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'y'
                => $this->redo(),
            // Tab management: t duplicates, Ctrl+w closes.
            // (Ctrl+Tab cycling lives at the top of this match, keyed on
            // KeyType::Tab — see the comment there.)
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'w'
                => $this->closeTab(),
            $plain && $msg->rune === 't'
                => $this->duplicateTab(),
            default => $this,
        };
        return [$next, null];
    }

    private function withActive(int $idx): self
    {
        if ($this->tabs !== [] && ($tab = $this->tabs[$this->tabIndex] ?? null) !== null) {
            $newTab = ['left' => $tab['left'], 'right' => $tab['right'], 'activeIdx' => $idx];
            $newTabs = $this->tabs;
            $newTabs[$this->tabIndex] = $newTab;
            return $this->mutate(['activeIdx' => $idx, 'tabs' => $newTabs]);
        }
        return $this->mutate(['activeIdx' => $idx]);
    }

    /**
     * @param \Closure(Pane): Pane $fn
     */
    private function withActivePane(\Closure $fn): self
    {
        if ($this->tabs !== [] && ($tab = $this->tabs[$this->tabIndex] ?? null) !== null) {
            if ($tab['activeIdx'] === 0) {
                $newPane = $fn($tab['left']);
                $newTab = ['left' => $newPane, 'right' => $tab['right'], 'activeIdx' => 0];
            } else {
                $newPane = $fn($tab['right']);
                $newTab = ['left' => $tab['left'], 'right' => $newPane, 'activeIdx' => 1];
            }
            $newTabs = $this->tabs;
            $newTabs[$this->tabIndex] = $newTab;
            // Keep $this->left/$this->right in sync with the active tab's panes
            $newLeft = $newTab['left'];
            $newRight = $newTab['right'];
            return $this->mutate(['left' => $newLeft, 'right' => $newRight, 'tabs' => $newTabs]);
        }
        if ($this->activeIdx === 0) {
            return $this->mutate(['left' => $fn($this->left)]);
        }
        return $this->mutate(['right' => $fn($this->right), 'activeIdx' => 1]);
    }

    /**
     * CF-02: one page of cursor travel. The pane box spends 4 lines on
     * chrome (top border, header, separator, bottom border) and the frame
     * 1 more on the status line; the fold-hint rows and the occasional
     * tab-bar line absorb the last cell. Without a captured height a
     * conservative 15-row page keeps the keys useful.
     */
    private function pageSize(): int
    {
        return $this->termRows > 0 ? max(1, $this->termRows - 6) : 15;
    }

    private function navigate(): self
    {
        $active = $this->activePane();
        $pane = $active->navigate($this->lister);
        if (!$pane->readable && $pane->cwd === $active->cwd) {
            // CF-22 (closes audit 031): the target directory answered the
            // lister with null (missing/unreadable). The pane keeps its
            // last honest listing and the status names the door that
            // refused — silently swapping in a fake-empty pane was the old lie.
            $target = $active->currentEntry();
            $refused = $target === null ? $active->cwd : ($target->isParentSentinel()
                ? Pane::parentPath($active->cwd)
                : Pane::join($active->cwd, $target->name));
            return $this->withActivePane(fn(Pane $p) => $pane)
                ->withStatus(Lang::t('status.unreadable', ['dir' => Entry::sanitizeName($refused)]));
        }
        return $this->withActivePane(fn(Pane $p) => $pane);
    }

    /**
     * CF-03: Enter/Right (edit) and `l` (view) on the entry under the
     * cursor. Dirs navigate exactly as before; files spawn. The spawn is a
     * Cmd::exec — the runtime suspends raw/alt-screen around the child and
     * feeds the exit back as ExecMsg, which update() turns into a re-list
     * plus an honest status.
     *
     * @param 'edit'|'view' $mode
     * @return array{0:self, 1:?\Closure}
     */
    private function openEntry(string $mode): array
    {
        $pane = $this->activePane();
        $entry = $pane->currentEntry();
        if ($entry === null) {
            return [$this, null];
        }
        if ($entry->isDir || $entry->isParentSentinel()) {
            return [$this->navigate(), null];
        }
        $full = Pane::join($pane->cwd, $entry->name);
        $label = Entry::sanitizeName($entry->name);
        if ($mode === 'view') {
            // The pager leg earns its keep only on viewable text: a NUL in
            // the head, a file beyond 32 MiB, or an unreadable one refuses
            // with a localized status instead of painting escape sequences
            // across the user's terminal.
            $argv = $this->isBinaryOrHuge($full) ? null : $this->pagerCommand();
            if ($argv === null) {
                return [$this->withStatus(Lang::t('status.no_viewer', ['name' => $label])), null];
            }
            return [
                $this->withStatus(Lang::t('status.opening_pager', ['name' => $label])),
                Cmd::exec([...$argv, $full]),
            ];
        }
        try {
            $editor = Editor::command();
        } catch (\RuntimeException) {
            return [$this->withStatus(Lang::t('status.no_editor', ['name' => $label])), null];
        }
        return [
            $this->withStatus(Lang::t('status.opening_editor', ['name' => $label])),
            Cmd::exec([...$editor, $full]),
        ];
    }

    /**
     * CF-03 `x`: run the file under the cursor, but ONLY when it carries an
     * exec bit — a plain text file must not spawn through the shell.
     *
     * @return array{0:self, 1:?\Closure}
     */
    private function runEntry(): array
    {
        $pane = $this->activePane();
        $entry = $pane->currentEntry();
        $label = $entry === null ? '' : Entry::sanitizeName($entry->name);
        if ($entry === null || $entry->isDir || $entry->isParentSentinel()) {
            return [$this->withStatus(Lang::t('status.not_executable', ['name' => $label])), null];
        }
        $full = Pane::join($pane->cwd, $entry->name);
        $perms = @fileperms($full);
        if (!is_int($perms) || ($perms & 0111) === 0) {
            return [$this->withStatus(Lang::t('status.not_executable', ['name' => $label])), null];
        }
        return [
            $this->withStatus(Lang::t('status.running', ['name' => $label])),
            Cmd::exec([$full]),
        ];
    }

    /**
     * CF-03: pager argv — $PAGER (possibly with flags) then the classic
     * less→more fallback chain. Null when nothing resolves.
     *
     * @return ?list<string>
     */
    private function pagerCommand(): ?array
    {
        $candidates = [];
        $env = getenv('PAGER');
        if (is_string($env) && trim($env) !== '') {
            $candidates[] = trim($env);
        }
        $candidates[] = DIRECTORY_SEPARATOR === '\\' ? 'more' : 'less';
        if (DIRECTORY_SEPARATOR !== '\\') {
            $candidates[] = 'more';
        }
        foreach ($candidates as $candidate) {
            $argv = preg_split('/\s+/u', $candidate) ?: [];
            if ($argv === [] || $argv[0] === '') {
                continue;
            }
            $bin = self::resolveBinary($argv[0]);
            if ($bin !== null) {
                $argv[0] = $bin;
                return array_values(array_filter($argv, static fn(string $a): bool => $a !== ''));
            }
        }
        return null;
    }

    /**
     * CF-03: absolute path or PATH lookup. Mirrors sugar-core Editor's
     * private which() — Editor exposes only the $EDITOR chain, and the
     * pager needs the same probe for $PAGER/less/more.
     */
    private static function resolveBinary(string $cmd): ?string
    {
        if ($cmd === '') {
            return null;
        }
        if (str_contains($cmd, DIRECTORY_SEPARATOR) || (DIRECTORY_SEPARATOR === '\\' && str_contains($cmd, '/'))) {
            return is_file($cmd) && is_executable($cmd) ? $cmd : null;
        }
        $probe = DIRECTORY_SEPARATOR === '\\'
            ? 'where ' . escapeshellarg($cmd) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($cmd) . ' 2>/dev/null';
        $out = @shell_exec($probe);
        if (!is_string($out) || trim($out) === '') {
            return null;
        }
        $first = strtok(trim($out), "\r\n");
        return $first !== false && $first !== '' ? $first : null;
    }

    /** CF-03: NUL byte in the head, unreadable, or larger than 32 MiB. */
    private function isBinaryOrHuge(string $path): bool
    {
        $size = @filesize($path);
        if ($size !== false && $size > 32 * 1024 * 1024) {
            return true;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return true;
        }
        $head = fread($fh, 8192);
        fclose($fh);
        return !is_string($head) || str_contains($head, "\x00");
    }

    private function goUp(): self
    {
        $active = $this->activePane();
        $parentPath = Pane::parentPath($active->cwd);
        // CF-22: an unreadable parent is refused, not entered blind — the
        // listing stays and the status names the door that shut.
        $parent = Pane::open($parentPath, $this->lister, $active->sort, $active->showHidden);
        if (!$parent->readable) {
            return $this->withActivePane(fn(Pane $p) => $p->unreadable())
                ->withStatus(Lang::t('status.unreadable', ['dir' => Entry::sanitizeName($parentPath)]));
        }
        return $this->withActivePane(fn(Pane $p) => $parent->cursorOnName(basename($active->cwd)));
    }

    private function cycleSort(): self
    {
        $active = $this->activePane();
        return $this->withActivePane(fn(Pane $p) => $p->withSort($active->sort->cycle()));
    }

    private function refresh(): self
    {
        // relist() (not Pane::open) so a re-read does not silently wipe the
        // cursor position and the entries of the selection that survived.
        $pane = $this->activePane()->relist($this->lister);
        // CF-22: `r` on a directory that stopped answering no longer reports
        // "refreshed" — it names the refusal, and the pane above the status
        // still shows the last honest listing (relist retained it).
        return $this->withActivePane(fn(Pane $p) => $pane)
            ->withStatus($pane->readable
                ? Lang::t('status.refreshed')
                : Lang::t('status.unreadable', ['dir' => Entry::sanitizeName($pane->cwd)]));
    }

    private function armDelete(): self
    {
        $selectedCount = count($this->activePane()->selected);
        $current = $this->activePane()->currentEntry();
        if ($selectedCount === 0 && ($current === null || $current->isParentSentinel())) {
            return $this->withStatus(Lang::t('status.nothing_to_delete'));
        }
        $names = $selectedCount > 0
            ? Lang::t('confirm.selected_entries', ['count' => $selectedCount])
            : "'" . Entry::sanitizeName((string) $current?->name) . "'";
        // CF-04: promise what the filesystem will actually do. The trash is
        // a rename, but rename across devices fails with EXDEV and the
        // delete falls back to direct removal — unrecoverable. A prompt on
        // such an entry used to promise the ordinary (undo-able) delete;
        // it now says PERMANENT up front.
        $targets = $selectedCount > 0
            ? array_keys($this->activePane()->selected)
            : [(string) $current?->name];
        $permanent = $this->deleteTouchesForeignDevice($this->activePane()->cwd, $targets);
        // Two literal Lang::t() calls (not a ternary inside the call): the
        // key-coverage guard scans for `Lang::t('literal'` and dynamic key
        // selection must not make declared translations look dead.
        $prompt = $permanent
            ? Lang::t('confirm.delete_permanent', ['names' => $names])
            : Lang::t('confirm.delete', ['names' => $names]);
        return $this->withConfirm(ConfirmState::DeleteSelected, $prompt);
    }

    /**
     * CF-04: would deleting these entries land in the per-process trash,
     * or fall through to permanent removal? The trash root lives on the
     * temp directory's device; a target on a different device cannot be
     * renamed into it (EXDEV), so performDelete() will unlink for good.
     * This is an ARM-TIME PREDICTION for the confirm prompt only — the
     * truth is always the failed rename itself (and on platforms where
     * stat dev is uniform this simply never fires, leaving the ordinary
     * undo-able path).
     *
     * @param list<string> $names
     */
    private function deleteTouchesForeignDevice(string $cwd, array $names): bool
    {
        $trash = @stat(sys_get_temp_dir());
        if ($trash === false) {
            return false;
        }
        foreach ($names as $name) {
            if ($name === '' || $name === '..') {
                continue;
            }
            $st = @stat(Pane::join($cwd, $name));
            if ($st !== false && $st['dev'] !== $trash['dev']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a confirmation keystroke.
     * Returns [Manager, ?Cmd] — the Cmd is non-null for async operations like copy.
     *
     * @return array{0: self, 1: ?\Closure} [Manager, Cmd]
     */
    private function resolveConfirm(KeyMsg $msg): array
    {
        // CF-10: RenameSelected and MkdirPrompt share one text-entry
        // sub-mode — characters accumulate in $inputBuffer, Enter commits,
        // Escape cancels, backspace edits. Only the prompt and the commit
        // leg differ. (The branch was rename-only; parameterising it keeps
        // the two grammar surfaces from drifting.)
        $isMkdir = $this->confirm === ConfirmState::MkdirPrompt;
        if ($this->confirm === ConfirmState::RenameSelected || $isMkdir) {
            // Two literal key spellings below — the coverage guard greps
            // for literal key arguments and a dynamic key name is invisible.
            $promptText = static fn(self $m): string => $isMkdir
                ? Lang::t('prompt.mkdir')
                : Lang::t('confirm.rename', ['name' => Entry::sanitizeName($m->pendingOpDest ?? '')]);
            $restate = static fn(self $m, string $status, ?string $buf): self =>
                $m->withConfirm($m->confirm, $status, $m->pendingOpDest, $isMkdir ? 'mkdir' : 'rename', $buf);
            // Backspace: drop last character from buffer
            if ($msg->type === KeyType::Backspace) {
                $buf = $this->inputBuffer ?? '';
                $newBuf = $buf === '' ? '' : self::dropLast($buf);
                return [$restate($this, $promptText($this), $newBuf), null];
            }
            // Enter: commit the typed buffer
            if ($msg->type === KeyType::Enter) {
                $newName = $this->inputBuffer ?? '';
                if ($newName === '') {
                    return [$this->withConfirm(ConfirmState::None, Lang::t('status.cancelled'))
                        ->withInputBuffer(null), null];
                }
                $committed = $isMkdir
                    ? $restate($this, $promptText($this), $newName)->performMkdir()
                    : $restate($this, $promptText($this), $newName)->performRenameWithBuffer();
                return [$committed, null];
            }
            // Escape: cancel
            if ($msg->type === KeyType::Escape) {
                return [$this->withConfirm(ConfirmState::None, Lang::t('status.cancelled'))
                    ->withInputBuffer(null), null];
            }
            // Printable character: accumulate in buffer. Space is its own
            // key type at the parser (see dispatch), so it must be
            // accepted here too — names containing spaces are real and
            // must be renameable (C-1 family).
            if (($msg->type === KeyType::Char || $msg->type === KeyType::Space) && !$msg->ctrl) {
                $buf = $this->inputBuffer ?? '';
                $newBuf = $buf . $msg->rune;
                return [$restate($this,
                    // N-4: the typed buffer joins the status like every other
                    // name-bearing write — sanitize at the choke, even though
                    // live keystrokes are printables (defense-in-depth).
                    $promptText($this) . Entry::sanitizeName($newBuf) . '_',
                    $newBuf), null];
            }
            // All other keys: no-op, stay in confirm state
            return [$restate($this, $promptText($this), $this->inputBuffer), null];
        }

        // Single-key y/n confirmation for delete/copy/move. M-7: the prompt
        // teaches a localized affirmative (de "(j/n)", ru "(д/н)", tr "(e/h)",
        // …), so the gate honors 'y' AND the active catalogue's taught letter
        // — case-insensitive single rune, multibyte-safe (mb_* compare; the
        // KeyMsg rune for Char is one UTF-8 codepoint string). Every other
        // answer still cancels; the rename submode above is untouched.
        $rune = mb_strtolower($msg->rune);
        // CF-05: the affirmative is a bare keypress only. Ctrl+Y is REDO
        // outside a prompt — letting it answer "y" here silently confirmed
        // destructive operations through an unrelated chord.
        $confirmed = $msg->type === KeyType::Char
            && !$msg->ctrl && !$msg->alt
            && ($rune === 'y' || $rune === mb_strtolower(Lang::t('confirm.affirmative')));
        if (!$confirmed) {
            return [$this->withConfirm(ConfirmState::None, Lang::t('status.cancelled'))
                ->withInputBuffer(null), null];
        }
        // For copy, return an async Cmd; delete/move remain synchronous
        return match ($this->confirm) {
            ConfirmState::DeleteSelected => [$this->performDelete(), null],
            ConfirmState::CopySelected => $this->performCopyAsync(),
            ConfirmState::MoveSelected => [$this->performMove(), null],
            default => [$this->withConfirm(ConfirmState::None, Lang::t('status.cancelled'))
                ->withInputBuffer(null), null],
        };
    }

    private function performDelete(): self
    {
        $pane = $this->activePane();
        $names = $pane->selected !== []
            ? array_keys($pane->selected)
            : [$pane->currentEntry()?->name];
        $errors = 0;
        $permanentCount = 0;
        $deletedItems = [];
        foreach ($names as $name) {
            if ($name === null || $name === '..' || $name === '') {
                continue;
            }
            $full = Pane::join($pane->cwd, $name);
            // Move to trash for O(1) undo (no file content read) instead of unlinking directly.
            $trashPath = $this->trashPath($full);
            if ($trashPath === null || !@rename($full, $trashPath)) {
                // Fall back to direct removal if rename fails (e.g. cross-device
                // EXDEV, or the trash root could not be created). CF-04: capture
                // dir-ness from the ORIGINAL path before removePath() destroys
                // it — the old code asked is_dir('') after zeroing $trashPath,
                // so every permanently-deleted directory was recorded as a file
                // and undo could not even re-create its empty skeleton.
                $wasDir = is_dir($full);
                if (!self::removePath($full)) {
                    $errors++;
                    continue;
                }
                $trashPath = '';
                $permanentCount++;
                $deletedItems[] = [
                    'path' => $full,
                    'isDir' => $wasDir,
                    'trash' => '',
                ];
                continue;
            }
            $deletedItems[] = [
                'path' => $full,
                // 018: record what the bytes ARE, not what they point at —
                // a symlink whose target is a directory would otherwise be
                // filed as isDir (is_dir() follows), mislabeling the entry.
                'isDir' => is_dir($trashPath) && !is_link($trashPath),
                'trash' => $trashPath,
            ];
        }
        // HONEST COUNTS: applied = entries that really moved to trash (or
        // were really removed), errors = failed legs, skipped = names
        // filtered at the loop head. count($names) counted intentions, which
        // inflated the number whenever a stale selection name was filtered
        // or a leg failed.
        $skipped = count(array_filter($names, static fn(?string $n): bool => $n === null || $n === '..' || $n === ''));
        $msg = match (true) {
            $errors > 0 => Lang::t('status.deleted_with_errors', ['count' => count($deletedItems), 'errors' => $errors]),
            $skipped > 0 => Lang::t('status.deleted_skipped', ['count' => count($deletedItems), 'skipped' => $skipped]),
            // CF-04: undo-able trash moves and unrecoverable permanent removals
            // get distinct wording — never hide an EXDEV deletion behind the
            // ordinary "deleted N entries" message.
            $permanentCount > 0 => Lang::t('status.deleted_permanent', ['count' => count($deletedItems), 'permanent' => $permanentCount]),
            default => Lang::t('status.deleted', ['count' => count($deletedItems)]),
        };
        // CF-42 (closes 014, 069): a delete that reached the session trash
        // must say how short the recovery window is — the bytes live in a
        // 0700 per-process root that the shutdown handler wipes, so after
        // quitting "deleted" really means gone. No hint over a fully
        // permanent removal (EXDEV or trash-root failure): there is
        // nothing there to recover, and a trash promise would lie.
        if (count($deletedItems) > $permanentCount) {
            $msg .= ' ' . Lang::t('status.deleted_trash_hint');
        }
        // Build new undo stack with this deletion
        $newUndoStack = $this->undoStack;
        if ($deletedItems !== []) {
            $newUndoStack[] = UndoAction::delete($deletedItems);
        }
        $newUndoStack = array_slice($newUndoStack, -self::UNDO_LIMIT);
        // CF-20: a delete re-homes bytes (trash) — both panes re-read.
        return $this->refreshAllPanes()
            ->withConfirm(ConfirmState::None, $msg)
            ->withUndoRedoStacks($newUndoStack, []); // Clear redo on new action
    }

    /** Build a trash path for a deleted entry, creating the trash root on first use. */
    private function trashPath(string $originalPath): ?string
    {
        $trashDir = self::trashRoot();
        // Lazily create the trash root. Without this the very first @rename()
        // into it always failed (the directory did not exist), so performDelete()
        // fell through to a permanent unlink and silently defeated undo. 0700
        // keeps recovered files private to the owning process.
        if (!is_dir($trashDir) && !@mkdir($trashDir, 0700, true) && !is_dir($trashDir)) {
            return null;
        }
        $basename = basename($originalPath);
        // Use cryptographically secure random bytes for an unpredictable trash entry name.
        // CF-25 (closes 037): a filesystem component caps at NAME_MAX
        // (~255 bytes); the 32-hex prefix already guarantees uniqueness, so
        // the basename leg is cut instead of letting rename(2) hit
        // ENAMETOOLONG on a long-but-legal name — that failure fell through
        // to the permanent removePath path and silently un-undid the delete.
        // The full name lives on in the undo record's path field.
        $trashName = sprintf('%s_%s', bin2hex(random_bytes(16)), substr($basename, 0, 222));
        return $trashDir . '/' . $trashName;
    }

    /**
     * Per-process trash root, computed once and reused for every delete so the
     * shutdown handler can clean up exactly what was created. The random suffix
     * makes the directory name unpredictable (guards against symlink attacks in
     * the shared temp dir) and collision-free across concurrent processes/re-runs.
     */
    private static function trashRoot(): string
    {
        return self::$trashRoot ??= sprintf(
            '%s/candyfiles-trash-%d-%s',
            sys_get_temp_dir(),
            getmypid(),
            bin2hex(random_bytes(8)),
        );
    }

    /** Arm copy confirmation — next KeyMsg triggers performCopyAsync or cancel. */
    private function armCopy(): self
    {
        $pane = $this->activePane();
        $selectedCount = count($pane->selected);
        $current = $pane->currentEntry();
        if ($selectedCount === 0 && ($current === null || $current->isParentSentinel())) {
            return $this->withStatus(Lang::t('status.nothing_to_copy'));
        }
        $inactive = $this->inactivePane();
        // Both names and destination are filesystem data — sanitize at the
        // message-build choke before they ride into the rendered status line.
        $names = $selectedCount > 0
            ? Lang::t('confirm.selected_entries', ['count' => $selectedCount])
            : "'" . Entry::sanitizeName((string) $current?->name) . "'";
        return $this->withConfirm(
            ConfirmState::CopySelected,
            Lang::t('confirm.copy', ['names' => $names, 'dest' => Entry::sanitizeName($inactive->cwd)]),
            $inactive->cwd,
            'copy'
        );
    }

    /** Arm move confirmation — next KeyMsg triggers performMove or cancel. */
    private function armMove(): self
    {
        $pane = $this->activePane();
        $selectedCount = count($pane->selected);
        $current = $pane->currentEntry();
        if ($selectedCount === 0 && ($current === null || $current->isParentSentinel())) {
            return $this->withStatus(Lang::t('status.nothing_to_move'));
        }
        $inactive = $this->inactivePane();
        $names = $selectedCount > 0
            ? Lang::t('confirm.selected_entries', ['count' => $selectedCount])
            : "'" . Entry::sanitizeName((string) $current?->name) . "'";
        return $this->withConfirm(
            ConfirmState::MoveSelected,
            Lang::t('confirm.move', ['names' => $names, 'dest' => Entry::sanitizeName($inactive->cwd)]),
            $inactive->cwd,
            'move'
        );
    }

    /**
     * Arm rename confirmation — a text-entry submode, not the y/n gate:
     * printables append to the input buffer, Enter commits it through
     * performRenameWithBuffer() (which delegates to performRename()),
     * and Esc cancels.
     */
    private function armRename(): self
    {
        $pane = $this->activePane();
        $current = $pane->currentEntry();
        if ($current === null || $current->isParentSentinel()) {
            return $this->withStatus(Lang::t('status.nothing_to_rename'));
        }
        return $this->withConfirm(
            ConfirmState::RenameSelected,
            Lang::t('confirm.rename', ['name' => Entry::sanitizeName($current->name)]),
            $current->name,
            'rename'
        );
    }

    /**
     * CF-10: arm the mkdir text-entry sub-mode (the `N` chord). No entry
     * under the cursor is required — creation targets the pane's cwd — so
     * the chord works in an empty directory, where mc/superfile advertise
     * it most.
     */
    private function armMkdir(): self
    {
        return $this->withConfirm(ConfirmState::MkdirPrompt, Lang::t('prompt.mkdir'));
    }

    /**
     * Name legality for creation/rename into this directory (CF-10). A name
     * that embeds a separator, a traversal, or a control byte is not a name
     * at all; '.' and '..' are virtual entries and ' ' alone is legal but
     * nothing-to-create. performRename's own (stricter than needed) guard
     * stays its until CF-24 unifies the two — this helper is the correct
     * predicate both will share.
     */
    private static function nameIsLegal(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }
        if (str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')) {
            return false;
        }
        return !preg_match('/[\x00-\x1F\x7F]/', $name);
    }

    /**
     * CF-10: create one directory relative to the active cwd, recording an
     * undoable Insert action. Undo removes it again ONLY while it is still
     * empty (reverseInsert); anything the user put inside survives.
     */
    private function performMkdir(): self
    {
        $pane = $this->activePane();
        $name = $this->inputBuffer ?? '';
        $refused = Lang::t('status.mkdir_failed', ['name' => Entry::sanitizeName($name)]);
        if (!self::nameIsLegal($name)) {
            return $this->withConfirm(ConfirmState::None, $refused)->withInputBuffer(null);
        }
        $full = Pane::join($pane->cwd, $name);
        // Never create over (or beside the refusal of) an existing name —
        // lstat semantics so a dangling link counts as taken.
        if (file_exists($full) || is_link($full)) {
            return $this->withActivePane(fn(Pane $p) =>
                Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
                ->withConfirm(ConfirmState::None, $refused)
                ->withInputBuffer(null);
        }
        if (!@mkdir($full)) {
            return $this->withActivePane(fn(Pane $p) =>
                Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
                ->withConfirm(ConfirmState::None, $refused)
                ->withInputBuffer(null);
        }
        $newUndoStack = $this->undoStack;
        $newUndoStack[] = UndoAction::insert([$full]);
        $newUndoStack = array_slice($newUndoStack, -self::UNDO_LIMIT);
        $msg = Lang::t('status.created', ['count' => 1]);

        // CF-20: the new directory is visible through the twin cwd too.
        return $this->refreshAllPanes()
            ->withConfirm(ConfirmState::None, $msg)
            ->withInputBuffer(null)
            ->withUndoRedoStacks($newUndoStack, []);
    }

    /**
     * Copy a file, symlink or directory tree to $dst.
     *
     * The recursive-directory machinery lives in exactly ONE home —
     * {@see AsyncOps::copyPath()} (cycle-guarded, depth-capped). Manager::copy
     * is the sync face of that engine, and the async copy path runs the same
     * code on a loop tick. (This used to be a second copyDir() copy here; the
     * two copies could and did drift.)
     *
     * @internal No production caller: the TUI copy flow resolves through
     *           copyManyAsync()/performCopyAsync(). This face exists so the
     *           sync and async paths share one engine and one test door —
     *           CF-26 tag-and-honor; the delete decision rides CF-11.
     *
     * @param string $src Source path
     * @param string $dst Destination path
     * @return bool True on success; false on any error (file not found, permission denied, etc.)
     */
    public function copy(string $src, string $dst): bool
    {
        return AsyncOps::copyPath($src, $dst);
    }

    /**
     * @param string $src Source path
     * @param string $dst Destination path
     * @return bool True on success; false on any error
     */
    public function move(string $src, string $dst): bool
    {
        return @rename($src, $dst);
    }

    /**
     * @param string $src Source path
     * @param string $newName New name (not full path — placed in the same directory as src)
     * @return bool True on success; false on any error
     */
    public function rename(string $src, string $newName): bool
    {
        $dir = dirname($src);
        $dst = Pane::join($dir, $newName);
        return @rename($src, $dst);
    }

    /**
     * Schedule an async copy and return [Manager, Cmd].
     *
     * HONEST SEMANTICS: AsyncOps defers the I/O to a loop tick
     * ({@see futureTick}) so the keypress round-trip finishes and the frame
     * paints first — but each individual copy then runs to completion
     * synchronously inside that tick. A huge recursive copy still blocks
     * the loop for its duration; this is tick-deferred, not non-blocking.
     *
     * What the pending state may claim: nothing has landed yet, so the
     * status says "copying…" (past-tense "copied" was a lie until the
     * completion message replaced it). The Cmd's promise resolves to a
     * CopyCompletedMsg carrying the ok-set only, the failed-leg count, the
     * names filtered before scheduling, and how many successful legs
     * replaced a pre-existing destination.
     *
     * @return array{0: self, 1: \Closure}
     */
    private function performCopyAsync(): array
    {
        $pane = $this->activePane();
        $names = $pane->selected !== []
            ? array_keys($pane->selected)
            : [$pane->currentEntry()?->name];
        $dst = $this->pendingOpDest;
        if ($dst === null) {
            return [$this->withConfirm(ConfirmState::None, Lang::t('status.cancelled')), null];
        }

        // Build the source→destination map synchronously (we have the names now)
        $copiedItems = [];
        foreach ($names as $name) {
            if ($name === null || $name === '..' || $name === '') {
                continue;
            }
            $src = Pane::join($pane->cwd, $name);
            $target = Pane::join($dst, $name);
            // CF-43: self-copy guard. With both panes on one cwd (the
            // documented startup default) target === src: copyPath would
            // relink a file onto itself — an error leg today while move's
            // @rename($s,$s) reported success. A same-path leg is neither
            // copied nor failed; it joins the skipped tally.
            if (self::canonicalPath($src) === self::canonicalPath($target)) {
                continue;
            }
            $copiedItems[$src] = $target;
        }
        $skipped = count($names) - count($copiedItems);

        // Which destinations already exist? copy() silently replaces them —
        // measured at SCHEDULE time (a race with a third party in between is
        // disclosed; the completion message is still honest about the bytes
        // that landed over something that was there when we armed).
        $existedSrcs = [];
        foreach ($copiedItems as $src => $target) {
            if (file_exists($target) || is_link($target)) {
                $existedSrcs[$src] = true;
            }
        }

        // Build the initial Manager state (before async op completes). The
        // in-flight counter is what makes CF-44's quit guard name a number:
        // one batch = one scheduled CopyCompletedMsg, whatever its size.
        $pendingManager = $this
            ->withConfirm(ConfirmState::None, Lang::t('status.copy_pending', ['count' => count($copiedItems)]))
            ->mutate(['copyBatchesInFlight' => $this->copyBatchesInFlight + 1]);

        // Return a Cmd that runs the async copy via AsyncOps.
        $asyncOps = new AsyncOps();
        $cmd = Cmd::promise(static function () use ($asyncOps, $copiedItems, $skipped, $existedSrcs): \React\Promise\PromiseInterface {
            return $asyncOps->copyManyAsync($copiedItems)
                ->then(static function (array $results) use ($copiedItems, $skipped, $existedSrcs): CopyCompletedMsg {
                    // UNDO RECORDS SUCCESSES ONLY: a failed leg has nothing
                    // to forget, and an undo entry over the whole map
                    // pretended to have copied bytes that never landed.
                    $okItems = array_filter($results, static fn($ok): bool => $ok === true);
                    $errors = count($results) - count($okItems);
                    // CF-21 (audit 029): the first exception diagnostic rides
                    // to the status line. Disk refusals carry no reason, so a
                    // plain-error batch keeps the bare count frame.
                    $reason = null;
                    foreach ($results as $r) {
                        if (is_string($r)) {
                            $reason = $r;
                            break;
                        }
                    }
                    $overwrote = count(array_intersect_key($okItems, $existedSrcs));
                    return new CopyCompletedMsg($okItems, $errors, $skipped, $overwrote, $reason);
                });
        });

        return [$pendingManager, $cmd];
    }

    private function performMove(): self
    {
        $pane = $this->activePane();
        $names = $pane->selected !== []
            ? array_keys($pane->selected)
            : [$pane->currentEntry()?->name];
        $dst = $this->pendingOpDest;
        if ($dst === null) {
            return $this->withConfirm(ConfirmState::None, Lang::t('status.cancelled'));
        }
        $errors = 0;
        $overwrote = 0;
        $movedItems = [];
        foreach ($names as $name) {
            if ($name === null || $name === '..' || $name === '') {
                continue;
            }
            $src = Pane::join($pane->cwd, $name);
            $target = Pane::join($dst, $name);
            // CF-43: self-move guard — same rationale as the copy schedule.
            // @rename($s,$s) succeeds as a silent no-op on POSIX, so the old
            // code counted the leg as "moved 1" and pushed a self-reversing
            // undo action that could never be undone meaningfully.
            if (self::canonicalPath($src) === self::canonicalPath($target)) {
                continue;
            }
            // CF-06: probe the destination BEFORE the leg runs — the copy
            // engine's schedule-time $existedSrcs pattern. rename() silently
            // replaces an existing victim on POSIX, and undo can reverse the
            // mover but cannot resurrect the clobbered file: the count is the
            // minimum honest face. file_exists misses dangling links, hence
            // the is_link disjunction.
            $clobbers = file_exists($target) || is_link($target);
            if (!$this->move($src, $target)) {
                $errors++;
                continue; // failed leg: not moved, not undoable, not counted
            }
            if ($clobbers) {
                $overwrote++;
            }
            $movedItems[$src] = $target;
        }
        // Counts report ACTIONS APPLIED; skipped names (filtered at the loop
        // head) are surfaced separately instead of inflating the total.
        $skipped = count($names) - count($movedItems) - $errors;
        $msg = match (true) {
            $errors > 0 => Lang::t('status.moved_with_errors', ['count' => count($movedItems), 'errors' => $errors]),
            $skipped > 0 => Lang::t('status.moved_skipped', ['count' => count($movedItems), 'skipped' => $skipped]),
            $overwrote > 0 => Lang::t('status.moved_overwrote', ['count' => count($movedItems), 'overwrote' => $overwrote]),
            default => Lang::t('status.moved', ['count' => count($movedItems)]),
        };
        $newUndoStack = $this->undoStack;
        if ($movedItems !== []) {
            $newUndoStack[] = UndoAction::move($movedItems);
        }
        $newUndoStack = array_slice($newUndoStack, -self::UNDO_LIMIT);
        // CF-20: a move lands bytes in the (possibly inactive) destination
        // pane — both panes re-read, no Tab+`r` dance to chase a phantom.
        return $this->refreshAllPanes()
            ->withConfirm(ConfirmState::None, $msg)
            ->withUndoRedoStacks($newUndoStack, []);
    }

    private function performRename(): self
    {
        $pane = $this->activePane();
        $current = $pane->currentEntry();
        if ($current === null || $current->isParentSentinel()) {
            return $this->withConfirm(ConfirmState::None, Lang::t('status.cancelled'))
                ->withInputBuffer(null);
        }
        $srcName = $current->name;
        $src = Pane::join($pane->cwd, $srcName);
        // Read the new name from inputBuffer (set by typed-input confirm mode)
        $newName = $this->inputBuffer ?? '';
        if ($newName === '' || $newName === $srcName) {
            return $this->withActivePane(fn(Pane $p) =>
                Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
                ->withConfirm(ConfirmState::None, Lang::t('status.cancelled'))
                ->withInputBuffer(null);
        }
        // CF-24 (audit 034) legality guard. Separators stay SUBSTRING-refused
        // (a rename writes a base name). The '..' substring leg was pure
        // over-refusal: the nets that matter — this separator leg plus the
        // canonicalPath containment below — already refuse every traversal
        // shape, while '..' as a substring wrongly refused legal names like
        // 'v1..v2.pdf'. '.'/'..' are refused by exact SEGMENT equality
        // (same rule performMkdir already honors).
        if (str_contains($newName, '/') === true || str_contains($newName, '\\') === true || $newName === '.' || $newName === '..') {
            return $this->withActivePane(fn(Pane $p) =>
                Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
                ->withConfirm(ConfirmState::None, Lang::t('status.rename_invalid', ['name' => Entry::sanitizeName($newName)]))
                ->withInputBuffer(null);
        }
        // Additional path traversal guard: validate resolved path stays within source directory
        $dst = Pane::join($pane->cwd, $newName);
        $resolvedDst = self::canonicalPath($dst);
        $resolvedCwd = self::canonicalPath($pane->cwd);
        // The '/' boundary is load-bearing hygiene: without it a sibling
        // directory whose name extends this one ('/tmp/pane' vs
        // '/tmp/panex') would satisfy a bare prefix match. Today the
        // separator guard above makes that unreachable, but the containment
        // check must stand on its own — hence the explicit boundary plus its
        // structural pin.
        if (str_starts_with($resolvedDst, $resolvedCwd . '/') === false && $resolvedDst !== $resolvedCwd) {
            return $this->withActivePane(fn(Pane $p) =>
                Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
                ->withConfirm(ConfirmState::None, Lang::t('status.rename_failed', ['name' => Entry::sanitizeName($srcName)]))
                ->withInputBuffer(null);
        }
        // CF-43: canonical self-rename guard. The string check above catches
        // byte-identical names; this catches lexical aliases ('a' under a
        // trailing-slash cwd still canonicalizes onto the source). A rename
        // onto self is a cancellation, not a failure.
        if ($resolvedDst === self::canonicalPath($src)) {
            return $this->withActivePane(fn(Pane $p) =>
                Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
                ->withConfirm(ConfirmState::None, Lang::t('status.cancelled'))
                ->withInputBuffer(null);
        }
        $renamedItems = [];
        $renamedItems[$src] = $dst;
        // CF-06: same destination pre-probe as performMove — a rename onto an
        // existing name silently eats that file; announce it (the victim count
        // is one by construction here).
        $clobbers = file_exists($dst) || is_link($dst);
        if (!$this->rename($src, $newName)) {
            return $this->withActivePane(fn(Pane $p) =>
                Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
                ->withConfirm(ConfirmState::None, Lang::t('status.rename_failed', ['name' => Entry::sanitizeName($srcName)]))
                ->withInputBuffer(null);
        }
        $newUndoStack = $this->undoStack;
        $newUndoStack[] = UndoAction::rename($renamedItems);
        $newUndoStack = array_slice($newUndoStack, -self::UNDO_LIMIT);
        // Old and new names are raw filesystem bytes — a filename is
        // attacker-writable data and these strings ride straight into the
        // rendered status line. Sanitize at the message-build choke.
        $msg = $clobbers
            // Two literal calls (never a ternary inside Lang::t — the
            // coverage guard greps for literal key spellings).
            ? Lang::t('status.renamed_overwrote', [
                'old'       => Entry::sanitizeName($srcName),
                'new'       => Entry::sanitizeName($newName),
                'overwrote' => 1,
            ])
            : Lang::t('status.renamed', [
                'old' => Entry::sanitizeName($srcName),
                'new' => Entry::sanitizeName($newName),
            ]);

        // CF-20: the rename mutates the shared cwd listing — both twins.
        return $this->refreshAllPanes()
            ->withConfirm(ConfirmState::None, $msg)
            ->withInputBuffer(null)
            ->withUndoRedoStacks($newUndoStack, []);
    }

    /** Commit a rename from the typed input buffer. */
    private function performRenameWithBuffer(): self
    {
        return $this->performRename();
    }

    /**
     * Lexical path canonicalization (no filesystem access, no extension
     * dependency). Collapses duplicate slashes, '.' segments, and resolves
     * '..' segments purely by name.
     *
     * This REPLACES the ext-phar canonicalize call: that method is NOT
     * universally present — measured on the 8.3.6 CLI of the CI/dev box,
     * method_exists for it returned false despite ext-phar being
     * loaded — so the rename COMMIT path threw an uncaught Error for every
     * single rename attempt on this box (the shipped test skipped the
     * commit step for exactly this reason). A guard must never crash the
     * thing it guards.
     */
    private static function canonicalPath(string $path): string
    {
        $unified = str_replace('\\', '/', $path);
        $absolute = str_starts_with($unified, '/');
        $segments = [];
        foreach (explode('/', $unified) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($absolute) {
                    // An absolute '..' pops the last named segment and can
                    // never escape: '/a/..' lands back on '/'.
                    array_pop($segments);
                    continue;
                }
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                    continue;
                }
            }
            $segments[] = $segment;
        }
        $joined = implode('/', $segments);
        if ($absolute) {
            return '/' . $joined;
        }
        return $joined === '' ? '.' : $joined;
    }

    private function withConfirm(ConfirmState $state, string $status, ?string $pendingOpDest = null, ?string $pendingOpType = null, ?string $inputBuffer = null): self
    {
        return $this->mutate([
            'status' => $status,
            'confirm' => $state,
            'pendingOpDest' => $pendingOpDest,
            'pendingOpType' => $pendingOpType,
            'inputBuffer' => $inputBuffer,
        ]);
    }

    private function withStatus(string $status): self
    {
        return $this->mutate(['status' => $status]);
    }

    private function withInputBuffer(?string $inputBuffer): self
    {
        return $this->mutate(['inputBuffer' => $inputBuffer]);
    }

    /** Start search mode with a query */
    public function search(string $query): self
    {
        // CF-09: the search corpus is the pane's IN-MEMORY listing, never a
        // fresh lister rescan. Two defects died here: the per-keystroke
        // ($this->lister)($cwd) call cost ~193 ms/char on real directories
        // (30.7 ms scandir + resort, plus Smith-Waterman over every raw
        // name), and — worse — the raw listing bypassed the pane's
        // visibility law, so '/' in a hidden-off pane enumerated (and Enter
        // navigated into) every dotfile the UI had deliberately hidden.
        // The parent sentinel is chrome, not a matchable entry: excluded.
        $cap = $this->termRows > 0 ? max(3, $this->termRows - 6) : self::SEARCH_CAP;
        $candidates = [];
        foreach ($this->activePane()->entries as $entry) {
            if (!$entry->isParentSentinel()) {
                $candidates[] = $entry;
            }
        }
        if ($query === '') {
            // Empty query shows the (now visibility-correct) full listing,
            // capped, and stays in search mode.
            return $this->withSearch('', array_slice($candidates, 0, $cap), 0);
        }
        // Fuzzy-match filenames through the sugar-fuzzy SSOT (Smith-Waterman
        // local alignment). matchAll() ranks by score descending, so the
        // strongest subsequence matches float to the top and a query whose
        // characters never appear yields no results.
        //
        // Charset prefilter: a Smith-Waterman score above zero requires at
        // least one aligned character pair, so a name sharing no (case-folded)
        // character with the query can never rank. Dropping those names
        // before matchAll() skips the alignment matrix for the great
        // majority of a large directory and cannot drop a single result —
        // it is a strict superset of the scorer's positive set.
        $queryChars = [];
        foreach (self::codepoints(mb_strtolower($query)) as $ch) {
            $queryChars[$ch] = true;
        }
        $survivors = [];
        foreach ($candidates as $entry) {
            foreach (self::codepoints(mb_strtolower($entry->name)) as $ch) {
                if (isset($queryChars[$ch])) {
                    $survivors[] = $entry;
                    break;
                }
            }
        }
        $matcher = SmithWatermanMatcher::new();
        // Queue entries per name so ranked results map back 1:1 even if a
        // directory ever surfaces two entries sharing a name.
        $byName = [];
        foreach ($survivors as $entry) {
            $byName[$entry->name][] = $entry;
        }
        $names = array_map(static fn(Entry $e) => $e->name, $survivors);
        $results = [];
        // Hard cap via the matcher's own limit: ranking is global, so the
        // cap must apply after the sort (MatchResultSorter), not before.
        foreach ($matcher->matchAll($query, $names, $cap) as $result) {
            $entry = array_shift($byName[$result->haystack]);
            if ($entry !== null) {
                $results[] = $entry;
            }
        }
        return $this->withSearch($query, $results, 0);
    }

    /** Grapheme-ish codepoint split for the search charset prefilter. */
    private static function codepoints(string $s): array
    {
        $out = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return $out === false ? str_split($s) : $out;
    }

    /** Exit search mode */
    public function exitSearch(): self
    {
        return $this->withSearch(null, [], 0);
    }

    /** Navigate search results with up/down */
    public function moveSearchCursor(int $delta): self
    {
        if ($this->searchQuery === null || $this->searchResults === []) {
            return $this;
        }
        $newCursor = max(0, min(count($this->searchResults) - 1, $this->searchCursor + $delta));
        return $this->withSearch($this->searchQuery, $this->searchResults, $newCursor);
    }

    /** Open selected search result */
    public function openSearchResult(): self
    {
        if ($this->searchQuery === null || $this->searchResults === []) {
            return $this;
        }
        $entry = $this->searchResults[$this->searchCursor] ?? null;
        if ($entry === null) {
            return $this;
        }
        // If it's a directory, navigate into it; if file, CF-09: exit search
        // AND land the pane cursor on the hit — before, Enter on a file
        // result dropped the user back to wherever the cursor previously
        // was, so the visible selection was a lie and the next Enter acted
        // on a different file than the one just chosen.
        if ($entry->isDir) {
            return $this->withActivePane(fn(Pane $p) => Pane::open(
                Pane::join($p->cwd, $entry->name),
                $this->lister,
                $p->sort,
                $p->showHidden
            ))->exitSearch();
        }
        return $this->withActivePane(fn(Pane $p) => $p->cursorOnName($entry->name))->exitSearch();
    }

    private function withSearch(?string $query, array $results, int $cursor): self
    {
        return $this->mutate([
            'searchQuery' => $query,
            'searchResults' => $results,
            'searchCursor' => $cursor,
        ]);
    }

    /** Handle keys while in search mode */
    private function handleSearchKey(KeyMsg $msg): self
    {
        // Escape exits search
        if ($msg->type === KeyType::Escape) {
            return $this->exitSearch();
        }
        // Enter opens result
        if ($msg->type === KeyType::Enter) {
            return $this->openSearchResult();
        }
        // Up/Down navigate results. CF-09: ARROWS ONLY — the old k/j legs
        // hijacked two letters that legitimately occur in file names, so
        // "keep.txt" could never be queried (the documented "needs paste"
        // workaround was a phantom of the hijack). k and j now fall through
        // to the append leg like every other printable.
        if ($msg->type === KeyType::Up) {
            return $this->moveSearchCursor(-1);
        }
        if ($msg->type === KeyType::Down) {
            return $this->moveSearchCursor(1);
        }
        // Backspace removes last char from query
        if ($msg->type === KeyType::Backspace) {
            if ($this->searchQuery === '') {
                // Empty query + backspace = exit search
                return $this->exitSearch();
            }
            $newQuery = self::dropLast($this->searchQuery);
            if ($newQuery === '') {
                // Backspacing to empty = exit search
                return $this->exitSearch();
            }
            return $this->search($newQuery);
        }
        // Regular chars append to query. Space arrives as its own key
        // type (see dispatch), so it must append here or a file whose
        // name contains a space can never be searched for (C-1 family).
        if (($msg->type === KeyType::Char || $msg->type === KeyType::Space) && !$msg->ctrl && $msg->rune !== '/') {
            return $this->search(($this->searchQuery ?? '') . $msg->rune);
        }
        return $this;
    }

    /** Drop last UTF-8 character from string */
    private static function dropLast(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $out = preg_replace('/.$/us', '', $s);
        // Fall back to mb_substr on PCRE error (returns null)
        return $out ?? mb_substr($s, 0, -1);
    }

    /** Recursive delete. Empty dirs use rmdir; files use unlink. */
    private static function removePath(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return false;
        }
        $items = @scandir($path) ?: [];
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!self::removePath(rtrim($path, '/') . '/' . $name)) {
                return false;
            }
        }
        return @rmdir($path);
    }

    /**
     * Open a new tab landing on $path.
     *
     * The argument is honoured: a caller asking for /var/log gets /var/log.
     * A path that is not a readable directory falls back to duplicating the
     * current pane's cwd (the previous behaviour) rather than opening a tab
     * on a listing that can never render.
     */
    public function openNewTab(string $path = '/'): self
    {
        $current = $this->currentTabs();
        $fallback = $current !== null
            ? $current['left']->cwd
            : $this->left->cwd;
        $cwd = is_dir($path) ? $path : $fallback;
        $newTab = [
            'left' => Pane::open($cwd, $this->lister),
            'right' => Pane::open($cwd, $this->lister),
            'activeIdx' => 0,
        ];
        // A bare no-tab state must first be materialized as a tab of its
        // own — `$this->tabs` alone would drop the flat pair on the floor.
        $base = $this->tabs !== [] ? $this->tabs : [[
            'left' => $this->left, 'right' => $this->right, 'activeIdx' => $this->activeIdx,
        ]];
        $newTabs = [...$base, $newTab];
        return $this->withTabs($newTabs, count($newTabs) - 1);
    }

    /** Close the tab at index, unless it's the last tab */
    public function closeTab(?int $index = null): self
    {
        $index ??= $this->tabIndex;
        if ($this->tabs === [] || count($this->tabs) <= 1) {
            return $this->withStatus(Lang::t('status.cannot_close_last_tab'));
        }
        $newTabs = $this->tabs;
        array_splice($newTabs, $index, 1);
        return $this->withTabs($newTabs, min($this->tabIndex, count($newTabs) - 1));
    }

    /** Switch to tab at index */
    public function switchTab(int $index): self
    {
        if ($this->tabs === [] || $index < 0 || $index >= count($this->tabs)) {
            return $this;
        }
        // CF-23: go through withTabs so the flat mirror follows the newly
        // current tab instead of freezing on the tab we left.
        return $this->withTabs($this->tabs, $index);
    }

    /**
     * Open a new tab duplicating the CURRENT tab honestly (CF-23, closes
     * audit 040): both pane cwds and the active side are carried over —
     * one accidental `t` must not collapse the dual-pane pair onto the
     * left cwd.
     */
    public function duplicateTab(): self
    {
        $cur = $this->currentTabs() ?? [
            'left' => $this->left,
            'right' => $this->right,
            'activeIdx' => $this->activeIdx,
        ];
        $newTab = [
            'left'  => Pane::open($cur['left']->cwd, $this->lister, $cur['left']->sort, $cur['left']->showHidden),
            'right' => Pane::open($cur['right']->cwd, $this->lister, $cur['right']->sort, $cur['right']->showHidden),
            'activeIdx' => $cur['activeIdx'],
        ];
        $base = $this->tabs !== [] ? $this->tabs : [[
            'left' => $this->left, 'right' => $this->right, 'activeIdx' => $this->activeIdx,
        ]];
        $newTabs = [...$base, $newTab];
        return $this->withTabs($newTabs, count($newTabs) - 1);
    }

    /** @return array{left:Pane,right:Pane,activeIdx:int}|null */
    private function currentTabs(): ?array
    {
        return $this->tabs[$this->tabIndex] ?? null;
    }

    /** Create a new Manager with modified undo/redo stacks */
    private function withUndoRedoStacks(array $undoStack, array $redoStack): self
    {
        return $this->mutate(['undoStack' => $undoStack, 'redoStack' => $redoStack]);
    }

    /** Undo the last operation */
    public function undo(): self
    {
        if ($this->undoStack === []) {
            return $this->withStatus(Lang::t('status.nothing_to_undo'));
        }
        $newUndoStack = $this->undoStack;
        $action = array_pop($newUndoStack);
        if ($action === null) {
            return $this->withStatus(Lang::t('status.nothing_to_undo'));
        }
        [$errors, $occupied] = $this->reverseAction($action);
        // Copy undo is informational only — original is preserved; report that explicitly
        if ($action->type === UndoActionType::Copy) {
            $msg = Lang::t('status.copy_undo_noop');
            $newRedoStack = [...$this->redoStack, $action];
            return $this
                ->relistAllPanes()
                ->withStatus($msg)
                ->withUndoRedoStacks($newUndoStack, $newRedoStack);
        }
        if ($errors + $occupied > 0) {
            // 067: a leg that failed keeps the action ON its own stack —
            // no cross-stack transfer may advertise a reversibility the
            // action does not have. The user can press undo again (retry
            // after clearing an occupied target); already-restored legs
            // report goal-state and the rest is re-attempted honestly.
            return $this
                ->relistAllPanes()
                ->withStatus($occupied > 0
                    ? Lang::t('status.undo_target_occupied', ['description' => $action->description, 'occupied' => $occupied])
                    : Lang::t('status.undo_with_errors', ['description' => $action->description, 'errors' => $errors]))
                ->withUndoRedoStacks($this->undoStack, $this->redoStack);
        }
        $msg = Lang::t('status.undone', ['description' => $action->description]);
        // Full success only: now the action may move to the redo stack.
        $newRedoStack = [...$this->redoStack, $action];
        return $this
            ->relistAllPanes()
            ->withStatus($msg)
            ->withUndoRedoStacks($newUndoStack, $newRedoStack);
    }

    /** Redo the last undone operation */
    public function redo(): self
    {
        if ($this->redoStack === []) {
            return $this->withStatus(Lang::t('status.nothing_to_redo'));
        }
        $newRedoStack = $this->redoStack;
        $action = array_pop($newRedoStack);
        if ($action === null) {
            return $this->withStatus(Lang::t('status.nothing_to_redo'));
        }
        $errors = $this->redoAction($action);
        if ($errors > 0) {
            // 067 twin: a failed redo keeps the action on the redo stack —
            // it stays retryable and no half-completed replay is claimed
            // done by pushing it back for another undo cycle.
            return $this
                ->relistAllPanes()
                ->withStatus(Lang::t('status.redo_with_errors', ['description' => $action->description, 'errors' => $errors]))
                ->withUndoRedoStacks($this->undoStack, $this->redoStack);
        }
        $msg = Lang::t('status.redone', ['description' => $action->description]);
        // Full success only: push back to undo stack
        $newUndoStack = [...$this->undoStack, $action];
        $newUndoStack = array_slice($newUndoStack, -self::UNDO_LIMIT);
        return $this
            ->relistAllPanes()
            ->withStatus($msg)
            ->withUndoRedoStacks($newUndoStack, $newRedoStack);
    }

    /** Re-apply an undone action (for redo) */
    private function redoAction(UndoAction $action): int
    {
        $errors = 0;
        match ($action->type) {
            UndoActionType::Delete => $errors = $this->redoDelete($action->items),
            UndoActionType::Move => $errors = $this->redoMove($action->items),
            UndoActionType::Rename => $errors = $this->redoRename($action->items),
            // CF-10: redoing an undone mkdir recreates the empty directory.
            UndoActionType::Insert => $errors = $this->redoInsert($action->items),
            // Copy cannot be redone meaningfully — original is still there
            UndoActionType::Copy, UndoActionType::Modify, UndoActionType::Custom => $errors = 0,
        };
        return $errors;
    }

    private function redoDelete(array $items): int
    {
        $errors = 0;
        foreach ($items as $item) {
            if (!isset($item['path'])) {
                continue;
            }
            $path = $item['path'];
            if (!file_exists($path) && !is_link($path)) {
                // Already gone — the delete's goal state holds, so this is a
                // no-op success, not an error. (A broken symlink still exists
                // as a link and falls through to be re-trashed below.)
                continue;
            }
            $trashPath = $this->trashPath($path);
            if ($trashPath !== null && @rename($path, $trashPath)) {
                continue;
            }
            // Trash unavailable (null) or rename failed — fall back to
            // permanent removal, exactly as performDelete() degrades under a
            // broken filesystem, instead of passing null into rename().
            if (!self::removePath($path)) {
                $errors++;
            }
        }
        return $errors;
    }

    private function redoMove(array $moves): int
    {
        $errors = 0;
        foreach ($moves as $oldPath => $newPath) {
            if (!is_string($oldPath) || !is_string($newPath)) {
                continue;
            }
            // Re-do the move: oldPath -> newPath (same as original move)
            if (file_exists($oldPath) && !@rename($oldPath, $newPath)) {
                $errors++;
            }
        }
        return $errors;
    }

    private function redoRename(array $renames): int
    {
        $errors = 0;
        foreach ($renames as $oldPath => $newPath) {
            if (!is_string($oldPath) || !is_string($newPath)) {
                continue;
            }
            // Re-do the rename: newPath -> oldName in newDir (reverse of undo's rename)
            $oldName = basename($oldPath);
            $newDir = dirname($newPath);
            $targetPath = Pane::join($newDir, $oldName);
            if (file_exists($newPath) && !@rename($newPath, $targetPath)) {
                $errors++;
            }
        }
        return $errors;
    }

    /**
     * Reverse one undo action.
     *
     * @return array{0:int,1:int} [failed legs, target-occupied legs]
     */
    private function reverseAction(UndoAction $action): array
    {
        // Route by UndoActionType enum — no string prefix detection
        return match ($action->type) {
            UndoActionType::Delete => $this->reverseDelete($action->items),
            UndoActionType::Move => $this->reverseMove($action->items),
            UndoActionType::Rename => $this->reverseRename($action->items),
            // CF-10: undoing a mkdir removes the created directory — but
            // only while it is empty; a filled one (or one replaced by
            // other bytes) is reported occupied, never recursively eaten.
            UndoActionType::Insert => $this->reverseInsert($action->items),
            // Copy cannot be undone — original still exists
            UndoActionType::Copy, UndoActionType::Modify, UndoActionType::Custom => [0, 0],
        };
    }

    /**
     * CF-10: undo one or more created directories.
     *
     * Goal-state law shared with the delete/move/rename reversers:
     *  - path already absent  → goal state holds, no-op success;
     *  - path exists but is NOT a directory (someone dropped a file or a
     *    link onto the name since) → occupied — undo never deletes bytes
     *    it did not create;
     *  - directory non-empty (dotfiles count — they are real content and
     *    the visibility law says nothing about existence) → occupied;
     *  - empty directory → rmdir; failure counts as an error.
     *
     * @param mixed $paths list of created directory paths
     *
     * @return array{0:int,1:int} [failed legs, occupied legs]
     */
    private function reverseInsert(mixed $paths): array
    {
        $errors = 0;
        $occupied = 0;
        if (!is_array($paths)) {
            return [1, 0];
        }
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                $errors++;
                continue;
            }
            if (!file_exists($path) && !is_link($path)) {
                continue; // already removed — the create's goal state holds
            }
            if (!is_dir($path) || is_link($path)) {
                $occupied++; // someone else's bytes own the name now
                continue;
            }
            $children = @scandir($path);
            if ($children === false) {
                $errors++; // unreadable: cannot prove emptiness — hands off
                continue;
            }
            $real = 0;
            foreach ($children as $name) {
                if ($name !== '.' && $name !== '..') {
                    $real++;
                }
            }
            if ($real > 0) {
                $occupied++; // filled since creation: never recursively eaten
                continue;
            }
            if (!@rmdir($path)) {
                $errors++;
            }
        }
        return [$errors, $occupied];
    }

    /**
     * CF-10: replay an undone mkdir — recreate the empty directories.
     * A path that is already there (or was replaced by other bytes) is
     * goal-state-holding for the directory case and an occupied-substitute
     * error otherwise.
     *
     * @param mixed $paths list of created directory paths
     */
    private function redoInsert(mixed $paths): int
    {
        $errors = 0;
        if (!is_array($paths)) {
            return 1;
        }
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                $errors++;
                continue;
            }
            if (is_dir($path) && !is_link($path)) {
                continue; // already there — replay goal state holds
            }
            if (file_exists($path) || is_link($path)) {
                $errors++; // a non-directory took the name meanwhile
                continue;
            }
            if (!@mkdir($path)) {
                $errors++;
            }
        }
        return $errors;
    }

    /**
     * Reverse an undo action (restore trashed items).
     *
     * A delete item whose trash path is empty was removed PERMANENTLY (the
     * cross-device EXDEV fallback in performDelete). Its bytes no longer
     * exist anywhere, so "restoring" it cannot succeed: counting it as an
     * error is the only honest polarity — and no empty skeleton is built,
     * because a directory recreated without its payload must never read as
     * "Undone" (035: the hollow-restore lie). The 0700 mkdir fallback is
     * gone with it: unrecorded mode rewriting had no right to happen.
     *
     * Every restore renames ONLY onto a free path (007 — redoDelete's
     * proven two-term check states the law, and its comment says so):
     * undo of yesterday must never eat the file the user created today.
     * An occupied target counts as a named leg and the bytes STAY in
     * trash — recoverable later, once the user clears the collision.
     *
     * 018: a broken symlink passes file_exists nowhere (it follows), so
     * the gate is the two-term `file_exists || is_link` check on BOTH
     * sides — trash-presence and target-freedom.
     *
     * @param list<array{path:string,isDir:bool,trash:string}> $items
     * @return array{0:int,1:int} [failed legs, target-occupied legs]
     */
    private function reverseDelete(array $items): array
    {
        $errors = 0;
        $occupied = 0;
        foreach ($items as $item) {
            if (!isset($item['path'])) {
                continue;
            }
            $path = $item['path'];
            $trash = $item['trash'] ?? '';
            $targetHere = \file_exists($path) || \is_link($path);
            if ($trash === '') {
                $errors++; // permanent removal: the bytes are gone for good
                continue;
            }
            $trashHere = \file_exists($trash) || \is_link($trash);
            if (!$trashHere) {
                if ($targetHere) {
                    continue; // already restored by an earlier press: goal state holds
                }
                $errors++; // trash entry vanished and nothing sits at the target
                continue;
            }
            if ($targetHere) {
                $occupied++;
                continue;
            }
            if (!@\rename($trash, $path)) {
                $errors++;
            }
        }
        return [$errors, $occupied];
    }

    /**
     * newPath -> oldPath reverses the move. 007 law: the restore renames
     * only onto a FREE original path — a file the user created there since
     * must never be eaten; the bytes wait at the moved location instead.
     *
     * @param array<string,string> $moves Map of original path => new path
     * @return array{0:int,1:int} [failed legs, target-occupied legs]
     */
    private function reverseMove(array $moves): array
    {
        $errors = 0;
        $occupied = 0;
        foreach ($moves as $oldPath => $newPath) {
            if (!is_string($oldPath) || !is_string($newPath)) {
                continue;
            }
            $targetHere = \file_exists($oldPath) || \is_link($oldPath);
            $sourceHere = \file_exists($newPath) || \is_link($newPath);
            if ($targetHere) {
                if (!$sourceHere) {
                    continue; // bytes already home: an earlier press did it
                }
                $occupied++;
                continue;
            }
            if (!$sourceHere) {
                $errors++; // neither end holds the payload: nothing to reverse
                continue;
            }
            if (!@\rename($newPath, $oldPath)) {
                $errors++;
            }
        }
        return [$errors, $occupied];
    }

    /**
     * Reverse a rename pair under the same free-path law as reverseMove.
     *
     * @param array<string,string> $renames Map of old path => new path
     * @return array{0:int,1:int} [failed legs, target-occupied legs]
     */
    private function reverseRename(array $renames): array
    {
        $errors = 0;
        $occupied = 0;
        foreach ($renames as $oldPath => $newPath) {
            if (!is_string($oldPath) || !is_string($newPath)) {
                continue;
            }
            // Extract original name from oldPath
            $oldName = basename($oldPath);
            $newDir = dirname($newPath);
            $targetPath = Pane::join($newDir, $oldName);
            $targetHere = \file_exists($targetPath) || \is_link($targetPath);
            $sourceHere = \file_exists($newPath) || \is_link($newPath);
            if ($targetHere) {
                if (!$sourceHere) {
                    continue; // already back under its old name
                }
                $occupied++;
                continue;
            }
            if (!$sourceHere) {
                $errors++;
                continue;
            }
            if (!@\rename($newPath, $targetPath)) {
                $errors++;
            }
        }
        return [$errors, $occupied];
    }

    /** Check if undo is available */
    public function canUndo(): bool
    {
        return $this->undoStack !== [];
    }

    /** Check if redo is available */
    public function canRedo(): bool
    {
        return $this->redoStack !== [];
    }
}
