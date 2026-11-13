<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\PerfSchema;

use SugarCraft\Widgets\Tabs\Tabs;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Components\Card\Badge;
use SugarCraft\Dashboard\Components\Card\Divider;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Sql\Admin\PageBase;
use SugarCraft\Sql\Admin\Resilience\StatementTimeout;
use SugarCraft\Sql\Admin\Resilience\StatementTimeoutException;
use SugarCraft\Sql\Admin\RowNavigable;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Core\Msg\ReloadReportMsg;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Style\Style;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;
use SugarCraft\Sql\CellValue;

/**
 * Performance Schema configuration page with tabbed interface.
 *
 * Provides management of PS setup including:
 * - Easy Setup: Enable/disable/reset PS with preset configurations
 * - Instruments: Collapsible tree view with tri-state toggles
 * - Consumers: List of consumers with tri-state toggles
 * - Actors&Objects: Split view of actors and object settings
 * - Threads: Read-only list of instrumented threads
 * - Options: Timer configuration (read-only)
 *
 * **EasySetupDetector wiring**: When an `EasySetupDetector` is provided
 * at construction it classifies the current PS setup state four-state —
 * `fully` (all instruments enabled+timed, all consumers enabled),
 * `disabled` (PS inaccessible), `default` (matches the MySQL 5.6 or 5.7
 * Appendix C profile), `custom` — via `classifyFromData()` on the rows this
 * page already loaded (SQL-P07: zero extra queries per arrival; the query
 * ladder `detect()` remains the classifier's oracle, not the render path).
 * The page never mints the detector itself: the App wires
 * `EasySetupDetector::fromContext($context)`, an omitted parameter keeps the
 * cruder instrument-only fallback (see below) — the old text claiming
 * fromContext happens here was never true.
 *
 * When no detector is wired (backward-compatibility fallback), `detectSetupState()`
 * uses loaded instrument data only — it cannot check TIMED status or consumers,
 * making it less accurate than the wired detector.
 *
 * **State badge colours**: FULLY (green), DEFAULT (yellow), CUSTOM (blue),
 * DISABLED (red), UNKNOWN (neutral).
 *
 * **Read-only mode**: Detected by `hasUpdatePrivilege()` — probed ONCE per
 * page session with a no-op `UPDATE ... SET ENABLED=ENABLED WHERE 1=0`
 * (memoized; SQL-P01). When true, all write actions (Easy Setup
 * `[`/`]`/`.` and inline toggles) are disabled. Requires `PROCESS`
 * privilege on MySQL.
 *
 * Keyboard shortcuts:
 *   [j/k] or [↑/↓] - Navigate items
 *   [h/l] or [←/→] - Previous/next tab (the in-app tab switcher)
 *   [Space] or [Enter] - Toggle/select item
 *   [Tab] - Switch tabs (standalone only: App consumes Tab for the pane cycle)
 *   [`[` / `]` / `.`] - Easy Setup actions: enable full / disable / reset
 *   [c] - Stage commit for pending changes (when dirty); [y] fires it
 *   [r] - Revert pending changes
 *   [q] - Return to the Tables browser (App intercepts q on the admin pane)
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema
 */
final class PerfSchemaPage extends PageBase implements RowNavigable
{
    /** Tab constants */
    public const TAB_EASY_SETUP = 'easy_setup';
    public const TAB_INSTRUMENTS = 'instruments';
    public const TAB_CONSUMERS = 'consumers';
    public const TAB_ACTORS = 'actors';
    public const TAB_OBJECTS = 'objects';
    public const TAB_THREADS = 'threads';
    public const TAB_OPTIONS = 'options';

    /** @var list<string> Tab order */
    private const TABS = [
        self::TAB_EASY_SETUP,
        self::TAB_INSTRUMENTS,
        self::TAB_CONSUMERS,
        self::TAB_ACTORS,
        self::TAB_OBJECTS,
        self::TAB_THREADS,
        self::TAB_OPTIONS,
    ];

    /** @var array<string, string> Tab display labels (TAB_* key => label) */
    private const TAB_LABELS = [
        self::TAB_EASY_SETUP => 'Easy Setup',
        self::TAB_INSTRUMENTS => 'Instruments',
        self::TAB_CONSUMERS => 'Consumers',
        self::TAB_ACTORS => 'Actors',
        self::TAB_OBJECTS => 'Objects',
        self::TAB_THREADS => 'Threads',
        self::TAB_OPTIONS => 'Options',
    ];

    private string $activeTab = self::TAB_EASY_SETUP;
    private int $selectedRowIndex = 0;
    private bool $readOnlyMode = false;

    /** @var list<SetupInstruments> */
    private array $instruments = [];

    /** @var list<SetupConsumers> */
    private array $consumers = [];

    /** @var list<SetupActors> */
    private array $actors = [];

    /** @var list<SetupObjects> */
    private array $objects = [];

    /** @var list<SetupThreads> */
    private array $threads = [];

    /** @var list<SetupTimers> */
    private array $timers = [];

    private ?EasySetupDetector $detector = null;
    private ?CommitPlanner $commitPlanner = null;
    private ?ChangeTracker $changeTracker = null;

    private string $setupState = 'custom';

    /**
     * SQL-P01: session-memoized result of the UPDATE-privilege probe.
     * The probe rides the SYNC handle (exec() bypasses the async cache),
     * so running it per rendered frame was a full write round trip every
     * second plus one per navigation keystroke — for a boolean that is
     * constant per server session. Null until first probed; a page is
     * built per pane entry, and {@see forgetPrivilegeProbe()} is the
     * invalidation hook for context identity changes.
     */
    private ?bool $updatePrivilegeMemo = null;

    /**
     * SQL-P07 (adminpages-007): arrival-scoped data gate. build() used to run
     * the FULL loadData() batch — six setup/digest table pulls, the setup
     * detection and the ChangeTracker/CommitPlanner rebuild — on EVERY render
     * frame, so tab-switching and arrow navigation re-paid for byte-identical
     * snapshots, and (worse) silently rolled back uncommitted toggles whose
     * rows the per-frame rebuild overwrote with server truth. Now the batch
     * runs once per async-cache arrival: ReloadReportMsg re-arms the gate
     * (the App forwards it on every admin load/drain completion, mirroring
     * ConnectionsPage), navigation keys do not.
     */
    private bool $snapshotLoaded = false;

    /**
     * SQL-P07: the access probe rode the same per-frame path as the data
     * batch (one COUNT query per render). Valid once per arrival; ReloadReportMsg
     * re-arms (a reconnect can change PROCESS-grant visibility).
     */
    private ?bool $accessMemo = null;

    /**
     * SQL-P07: the instrument tree (~1,500 nodes parsed from the row list)
     * was rebuilt per frame. Memoized like the rows it renders: the writers of
     * $instruments (loadData, toggleInstrument) are enumerated in this class
     * and clear the memo, so it is exact without diffing the arrays.
     */
    private ?InstrumentTree $treeMemo = null;

    /**
     * D-M27: statements staged by [c] and awaiting an explicit [y]
     * confirmation. These are UPDATEs against performance_schema (and, from
     * the variable editor's genre, durable server config) — the commit used
     * to fire on a single blind keypress while KILL and SET GLOBAL gated
     * behind prompts. Null when no commit is armed.
     *
     * @var list<array{sql:string,params:array<mixed>}>|null
     */
    private ?array $pendingCommit = null;

    /** In-page feedback line (commit cancelled / armed), rendered in build(). */
    private ?string $actionMessage = null;

    /**
     * @param StatementTimeout|null $commitTimeout SQL-P07 (adminpages-028):
     *        per-statement budget for the confirmed commit and Easy Setup
     *        loops — both run SYNCHRONOUSLY against performance_schema
     *        (they are writes; the async cache serves reads only), so an
     *        UPDATE stalled on a metadata lock used to freeze the entire UI
     *        indefinitely, and an error mid-list left a partial commit with
     *        no record of where it stopped. Null builds the default budget;
     *        injection exists for tests and embedders that want a tighter cap.
     */
    public function __construct(
        ServerContextInterface $context,
        ?EasySetupDetector $detector = null,
        ?CommitPlanner $commitPlanner = null,
        private readonly ?StatementTimeout $commitTimeout = null,
    ) {
        parent::__construct($context);
        $this->detector = $detector;
        $this->commitPlanner = $commitPlanner;
    }

    /**
     * Factory method to create a new PerfSchemaPage.
     */
    public static function new(
        ServerContextInterface $context,
        ?EasySetupDetector $detector = null,
        ?CommitPlanner $commitPlanner = null,
        ?StatementTimeout $commitTimeout = null,
    ): self {
        return new self($context, $detector, $commitPlanner, $commitTimeout);
    }

    /**
     * Verify we can access Performance Schema before rendering. SQL-P07
     * (adminpages-007): memoized per arrival — this probe is a live COUNT
     * round trip, and the old every-frame call kept a real query in flight
     * every render regardless of the data gate below.
     */
    protected function validate(): bool
    {
        if ($this->accessMemo !== null) {
            return $this->accessMemo;
        }
        try {
            // Try to query setup_instruments to verify PS is accessible
            $result = $this->context->connection()->query(
                'SELECT COUNT(*) FROM `performance_schema`.`setup_instruments` WHERE `NAME` NOT LIKE \'memory/%\''
            );
            return $this->accessMemo = is_array($result);
        } catch (\PDOException $e) {
            $this->errorMessage = $this->getErrorMessage($e);
            return $this->accessMemo = false;
        }
    }

    /**
     * Build the complete page output.
     */
    protected function build(): string
    {
        // SQL-P07: the whole batch is arrival-scoped (snapshotLoaded gate);
        // navigation frames reuse the parsed snapshot.
        if (!$this->snapshotLoaded) {
            $this->loadData();
        }

        $lines = [];

        $lines[] = $this->renderHeader();
        $lines[] = '';
        $lines[] = $this->renderTabBar();
        $lines[] = '';
        $lines[] = $this->renderContent();
        $lines[] = '';
        if ($this->pendingCommit !== null) {
            // D-M27 confirmation: show exactly what will run, up to five
            // statements, then the y-or-any-key decision.
            $lines[] = Style::new()->foreground(Color::hex('#f38ba8'))->render(
                sprintf('COMMIT %d statement(s) to performance_schema? [y] confirm · any other key cancels', count($this->pendingCommit))
            );
            foreach (array_slice($this->pendingCommit, 0, 5) as $stmt) {
                $sql = (string) $stmt['sql'];
                if (strlen($sql) > 100) {
                    $sql = substr($sql, 0, 97) . '...';
                }
                // cut→sanitize (adminpages-026): the statement text may itself
                // carry ESC bytes (it is persisted server-side — and the cut
                // measures raw bytes), so the confirmation preview must not
                // become the one channel that paints an escape at the prompt
                // the user is about to answer with 'y'.
                $lines[] = '  ' . Style::new()->foreground(Color::hex('#cdd6f4'))->render(CellValue::sanitize($sql));
            }
            $lines[] = '';
        } elseif ($this->actionMessage !== null) {
            $lines[] = Style::new()->foreground(Color::hex('#94a3b8'))->render($this->actionMessage);
            $lines[] = '';
        }
        $lines[] = $this->renderFooter();

        return implode("\n", $lines);
    }

    /**
     * Handle keyboard shortcuts for navigation and actions.
     *
     * @return array{0: self, 1: ?\SugarCraft\Core\Cmd}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof ReloadReportMsg) {
            // SQL-P07: an async-cache arrival is the ONLY thing that re-arms
            // the data batch (App forwards this on admin load/drain
            // completion). Held while the page carries uncommitted toggles:
            // the old per-frame reload silently evaporated them (server truth
            // overwrote the edited rows before [c] could plan from them), and
            // the same bug in miniature is an arrival mid-edit — so a dirty
            // page keeps its snapshot until commit/revert, which each reload
            // explicitly.
            if ($this->isDirty()) {
                return [$this, null];
            }
            $clone = clone $this;
            $clone->snapshotLoaded = false;
            $clone->accessMemo = null;
            return [$clone, null];
        }

        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        // Ensure data is loaded for navigation operations (SQL-P07: this is
        // the cold-start leg only — the per-frame batch is gated by
        // snapshotLoaded in build(); a re-armed page re-fetches at render,
        // not here, so keystrokes never sync-load stale-surviving state).
        if (!$this->snapshotLoaded && $this->instruments === [] && $this->consumers === []) {
            $this->loadData();
        }

        $ch = $msg->rune;
        $type = $msg->type;

        // While a commit awaits confirmation, only an explicit y/Y fires it;
        // ANY other key cancels (fail-safe: a stray keystroke never rewrites
        // server instrumentation), mirroring the ConnectionsPage kill prompt.
        if ($this->pendingCommit !== null) {
            if ($ch === 'y' || $ch === 'Y') {
                return $this->executeCommit($this->pendingCommit);
            }
            return [$this->withClearedCommitPrompt('Commit cancelled'), null];
        }

        // Tab switches tabs
        // NOTE: App consumes Tab for the pane cycle (see VariablesPage) — the
        // tab-pair below is unreachable in the live app; kept for a future
        // dedicated in-page tab key (review F1).
        if ($type === KeyType::Tab && !$msg->shift) {
            return [$this->withNextTab(), null];
        }

        // Shift+Tab goes to previous tab
        if ($type === KeyType::Tab && $msg->shift) {
            return [$this->withPrevTab(), null];
        }

        // h/l or Left/Right arrows cycle tabs. These are the live in-app
        // tab switchers: App delegates unclaimed chars (and the arrows) to
        // row-navigable pages, so unlike the Tab pair above they are
        // reachable while the app runs (review M2 re-key).
        if ($ch === 'l' || $type === KeyType::Right) {
            return [$this->withNextTab(), null];
        }
        if ($ch === 'h' || $type === KeyType::Left) {
            return [$this->withPrevTab(), null];
        }

        // j or Down navigates down
        if ($ch === 'j' || $type === KeyType::Down) {
            return [$this->withNavigateDown(), null];
        }

        // k or Up navigates up
        if ($ch === 'k' || $type === KeyType::Up) {
            return [$this->withNavigateUp(), null];
        }

        // Space or Enter toggles/selects
        if ($ch === ' ' || $type === KeyType::Enter) {
            return $this->handleToggle();
        }

        // c stages a commit; the statements fire only after [y] confirms.
        if ($ch === 'c' && $this->isDirty()) {
            return $this->armCommitConfirm();
        }

        // r reverts pending changes
        if ($ch === 'r' && $this->isDirty()) {
            return [$this->withRevert(), null];
        }

        // Easy Setup actions on the [ ] . bracket pair — the Reports-page
        // convention. They replaced the digits 1/2/3, which App always
        // intercepts as the admin pane picker before any page sees them
        // (review M2); the bracket keys are otherwise unused here, so the
        // actions are genuinely armed in the live app on the Easy Setup tab.
        if ($this->activeTab === self::TAB_EASY_SETUP) {
            if ($ch === '[') {
                return $this->handleEasySetupAction(1);
            }
            if ($ch === ']') {
                return $this->handleEasySetupAction(2);
            }
            if ($ch === '.') {
                return $this->handleEasySetupAction(3);
            }
        }

        // q quits (handled by parent)
        if ($ch === 'q') {
            return [$this->withQuit(), null];
        }

        return [$this, null];
    }

    // ─── Wither Methods ───────────────────────────────────────────────────────

    /**
     * Return a new instance with the next tab active.
     */
    public function withNextTab(): self
    {
        $clone = clone $this;
        $currentIndex = array_search($this->activeTab, self::TABS, true);
        $nextIndex = ($currentIndex + 1) % count(self::TABS);
        $clone->activeTab = self::TABS[$nextIndex];
        $clone->selectedRowIndex = 0;
        return $clone;
    }

    /**
     * Return a new instance with the previous tab active.
     */
    public function withPrevTab(): self
    {
        $clone = clone $this;
        $currentIndex = array_search($this->activeTab, self::TABS, true);
        $prevIndex = $currentIndex - 1;
        if ($prevIndex < 0) {
            $prevIndex = count(self::TABS) - 1;
        }
        $clone->activeTab = self::TABS[$prevIndex];
        $clone->selectedRowIndex = 0;
        return $clone;
    }

    /**
     * Return a new instance with a specific tab active.
     */
    public function withTab(string $tab): self
    {
        if (!in_array($tab, self::TABS, true)) {
            return $this;
        }

        $clone = clone $this;
        $clone->activeTab = $tab;
        $clone->selectedRowIndex = 0;
        return $clone;
    }

    /**
     * Return a clone (quit is handled by parent controller).
     */
    public function withQuit(): self
    {
        return clone $this;
    }

    /**
     * Return a new instance with reverted changes.
     */
    public function withRevert(): self
    {
        $clone = clone $this;
        if ($clone->changeTracker !== null) {
            $clone->changeTracker->reset();
        }
        $clone->loadData();
        return $clone;
    }

    // ─── Private Wither Helpers ───────────────────────────────────────────────

    private function withNavigateDown(): self
    {
        $clone = clone $this;
        $maxIndex = $this->getMaxRowIndex();
        if ($clone->selectedRowIndex < $maxIndex) {
            $clone->selectedRowIndex++;
        }
        return $clone;
    }

    private function withNavigateUp(): self
    {
        $clone = clone $this;
        if ($clone->selectedRowIndex > 0) {
            $clone->selectedRowIndex--;
        }
        return $clone;
    }

    private function handleToggle(): array
    {
        if ($this->readOnlyMode) {
            return [$this, null];
        }

        $clone = clone $this;

        switch ($this->activeTab) {
            case self::TAB_INSTRUMENTS:
                return $clone->toggleInstrument();

            case self::TAB_CONSUMERS:
                return $clone->toggleConsumer();

            case self::TAB_ACTORS:
                return $clone->toggleActor();

            case self::TAB_OBJECTS:
                return $clone->toggleObject();

            default:
                return [$this, null];
        }
    }

    /**
     * [c]: compute the exact statement list and arm the confirmation prompt.
     */
    private function armCommitConfirm(): array
    {
        // Plan from the CURRENT arrays: the stored planner was built during
        // loadData() and its copied-in arrays predate any later toggles, so
        // reusing it here would stage zero statements off a dirty page (the
        // old blind-commit path had the same staleness — toggled changes
        // silently evaporated instead of running).
        $planner = new CommitPlanner(
            $this->instruments,
            $this->consumers,
            $this->actors,
            $this->objects,
        );

        try {
            $statements = $planner->commitAll();
        } catch (\Throwable $e) {
            $this->errorMessage = 'Commit planning failed: ' . $e->getMessage();
            return [$this, null];
        }

        if ($statements === []) {
            return [$this->withClearedCommitPrompt('Nothing to commit'), null];
        }

        $clone = clone $this;
        $clone->pendingCommit = $statements;
        $clone->actionMessage = null;
        $clone->errorMessage = '';
        return [$clone, null];
    }

    /** Clear the armed commit prompt, optionally leaving a feedback line. */
    private function withClearedCommitPrompt(?string $message): self
    {
        $clone = clone $this;
        $clone->pendingCommit = null;
        $clone->actionMessage = $message;
        return $clone;
    }

    /**
     * Execute a confirmed statement batch, then reload (was handleCommit()).
     *
     * SQL-P07 (adminpages-028): every statement rides its own bounded leg
     * (C11's alarm seam). Writes cannot ride the async cache — they ARE the
     * write path — so the honesty delivered here is the bound plus the
     * stop-position report: an UPDATE stalled on a metadata lock used to
     * freeze the UI forever, and a mid-list failure said only 'Commit
     * failed', hiding how much of the batch was already applied. Per-item
     * progress painting would need a write-drain the Admin layer does not
     * have; this is the bound arm of the row. The Easy Setup presets run
     * through this same seam (handleEasySetupAction), not a copy.
     *
     * @param list<array{sql:string,params:array<mixed>}> $statements
     * @return array{0: self, 1: ?\Closure}
     */
    private function executeCommit(array $statements, string $label = 'Commit'): array
    {
        $db = $this->context->connection();
        $guard = $this->commitTimeout ?? new StatementTimeout();
        $position = 0;

        try {
            foreach ($statements as $stmt) {
                $position++;
                $sql = $stmt['sql'];
                $params = $stmt['params'];

                // Use prepared statement with bound parameters for safety
                $guard->run(function () use ($db, $sql, $params): void {
                    $prepared = $db->prepare($sql);
                    if ($prepared === null) {
                        throw new \PDOException('Failed to prepare statement: ' . $sql);
                    }
                    $prepared->execute($params);
                });
            }

            // Reload data after commit
            $clone = clone $this;
            $clone->pendingCommit = null;
            $clone->actionMessage = $label === 'Commit'
                ? sprintf('Committed %d statement%s', count($statements), count($statements) === 1 ? '' : 's')
                : sprintf('%s applied — %d statement%s', $label, count($statements), count($statements) === 1 ? '' : 's');
            $clone->loadData();
            if ($clone->changeTracker !== null) {
                $clone->changeTracker->commit();
            }

            return [$clone, null];
        } catch (\PDOException | StatementTimeoutException $e) {
            $clone = clone $this;
            $clone->pendingCommit = null;
            $clone->errorMessage = sprintf(
                '%s failed at statement %d of %d: %s — earlier statements were applied, server state is partial.',
                $label,
                $position,
                count($statements),
                $e->getMessage(),
            );
            // The applied prefix moved server truth: re-arm the arrival gate
            // so the next render shows what actually got through, not the
            // pre-commit snapshot the failed batch was planned from.
            $clone->snapshotLoaded = false;
            $clone->accessMemo = null;
            return [$clone, null];
        }
    }

    public function isModal(): bool
    {
        return $this->pendingCommit !== null;
    }

    /**
     * Handle an Easy Setup action: 1 = enable full, 2 = disable,
     * 3 = reset to defaults. Armed by the [ ] . keys on the Easy Setup tab.
     */
    private function handleEasySetupAction(int $action): array
    {
        if ($this->readOnlyMode) {
            return [$this, null];
        }

        $statements = match ($action) {
            1 => EasySetup::new()->enableStatements(),
            2 => EasySetup::new()->disableStatements(),
            3 => EasySetup::new()->resetToDefaultStatements(),
            default => [],
        };

        if ($statements === []) {
            return [$this, null];
        }

        // SQL-P07 (adminpages-028): the presets run through the SAME bounded,
        // stop-position-reported seam as a confirmed [c]/[y] commit — they
        // used to be a second, copy-maintenance loop of raw exec() calls.
        // The statements are fully-literal UPDATEs (params: empty), so the
        // prepared seam applies them verbatim.
        return $this->executeCommit(
            array_map(
                static fn (string $sql): array => ['sql' => $sql, 'params' => []],
                $statements,
            ),
            'Easy Setup',
        );
    }

    private function toggleInstrument(): array
    {
        $filtered = $this->getFilteredInstruments();
        if ($filtered === [] || !isset($filtered[$this->selectedRowIndex])) {
            return [$this, null];
        }

        $instrument = $filtered[$this->selectedRowIndex];
        $toggled = $instrument->withEnabled(!$instrument->enabled);

        $clone = clone $this;
        $key = array_search($instrument, $clone->instruments, true);
        if ($key !== false) {
            $clone->instruments[$key] = $toggled;
            // SQL-P07: the tree embeds the VOs — a toggle replaces one, so
            // the memo rebuilds (this is the writer enumeration's other arm).
            $clone->treeMemo = null;
        }

        return [$clone, null];
    }

    private function toggleConsumer(): array
    {
        $filtered = $this->getFilteredConsumers();
        if ($filtered === [] || !isset($filtered[$this->selectedRowIndex])) {
            return [$this, null];
        }

        $consumer = $filtered[$this->selectedRowIndex];
        $toggled = $consumer->withEnabled(!$consumer->enabled);

        $clone = clone $this;
        $key = array_search($consumer, $clone->consumers, true);
        if ($key !== false) {
            $clone->consumers[$key] = $toggled;
        }

        return [$clone, null];
    }

    private function toggleActor(): array
    {
        $filtered = $this->getFilteredActors();
        if ($filtered === [] || !isset($filtered[$this->selectedRowIndex])) {
            return [$this, null];
        }

        $actor = $filtered[$this->selectedRowIndex];
        $toggled = $actor->withEnabled(!$actor->enabled);

        $clone = clone $this;
        $key = array_search($actor, $clone->actors, true);
        if ($key !== false) {
            $clone->actors[$key] = $toggled;
        }

        return [$clone, null];
    }

    private function toggleObject(): array
    {
        $filtered = $this->getFilteredObjects();
        if ($filtered === [] || !isset($filtered[$this->selectedRowIndex])) {
            return [$this, null];
        }

        $object = $filtered[$this->selectedRowIndex];
        $toggled = $object->withEnabled(!$object->enabled);

        $clone = clone $this;
        $key = array_search($object, $clone->objects, true);
        if ($key !== false) {
            $clone->objects[$key] = $toggled;
        }

        return [$clone, null];
    }

    // ─── Data Loading ─────────────────────────────────────────────────────────

    private function loadData(): void
    {
        $db = $this->context->connection();

        // Load instruments
        $this->instruments = $this->loadInstruments($db);

        // Load consumers
        $this->consumers = $this->loadConsumers($db);

        // Load actors
        $this->actors = $this->loadActors($db);

        // Load objects
        $this->objects = $this->loadObjects($db);

        // Load threads
        $this->threads = $this->loadThreads($db);

        // Load timers
        $this->timers = $this->loadTimers($db);

        // SQL-P07 (adminpages-008): classify client-side from the rows just
        // loaded. The wired detector previously ran detect() — up to SEVEN
        // full-table aggregate scans (COUNT/SUM over setup_instruments and
        // setup_consumers, several recomputing exactly what loadInstruments()
        // and loadConsumers() already delivered) that the async cache re-armed
        // server-side every TTL window while the pane stayed open.
        // classifyFromData mirrors the identical ladder from these arrays with
        // zero queries.
        $this->setupState = $this->detector !== null
            ? $this->detector->classifyFromData($this->instruments, $this->consumers)
            : $this->detectSetupState();

        // Check privileges for read-only mode
        $this->readOnlyMode = !$this->hasUpdatePrivilege();

        // Initialize change tracker
        $this->changeTracker = new ChangeTracker(
            array_merge($this->instruments, $this->consumers, $this->actors, $this->objects)
        );

        // Initialize commit planner
        $this->commitPlanner = CommitPlanner::new(
            $this->instruments,
            $this->consumers,
            $this->actors,
            $this->objects
        );

        // SQL-P07: fresh server truth landed — the tree memo is stale by
        // definition, and the arrival gate closes until the next ReloadReportMsg.
        $this->treeMemo = null;
        $this->snapshotLoaded = true;
    }

    /**
     * @return list<SetupInstruments>
     */
    private function loadInstruments(DatabaseInterface $db): array
    {
        $instruments = [];
        try {
            $result = $db->query(
                'SELECT `NAME`, `ENABLED`, `TIMED`, `PROPERTIES`, `FLAGS` FROM `performance_schema`.`setup_instruments` WHERE `NAME` NOT LIKE \'memory/%\''
            );
            foreach ($result as $row) {
                $instruments[] = SetupInstruments::new(
                    name: (string) ($row['NAME'] ?? ''),
                    enabled: $this->parseEnabled((string) ($row['ENABLED'] ?? 'NO')),
                    timed: $this->parseEnabled((string) ($row['TIMED'] ?? 'NO')),
                    properties: (string) ($row['PROPERTIES'] ?? ''),
                    flags: (string) ($row['FLAGS'] ?? ''),
                );
            }
        } catch (\PDOException) {
            // Gracefully handle PS being disabled
        }
        return $instruments;
    }

    /**
     * @return list<SetupConsumers>
     */
    private function loadConsumers(DatabaseInterface $db): array
    {
        $consumers = [];
        try {
            $result = $db->query('SELECT `NAME`, `ENABLED` FROM `performance_schema`.`setup_consumers`');
            foreach ($result as $row) {
                $consumers[] = SetupConsumers::new(
                    name: (string) ($row['NAME'] ?? ''),
                    enabled: $this->parseEnabled((string) ($row['ENABLED'] ?? 'NO')),
                );
            }
        } catch (\PDOException) {
            // Gracefully handle PS being disabled
        }
        return $consumers;
    }

    /**
     * @return list<SetupActors>
     */
    private function loadActors(DatabaseInterface $db): array
    {
        // setup_actors table was introduced in MySQL 5.6
        $version = $this->context->version();
        if (!$version->isAtLeast(5, 6)) {
            return [];
        }

        $actors = [];
        try {
            $result = $db->query('SELECT `HOST`, `USER`, `ROLE`, `ENABLED` FROM `performance_schema`.`setup_actors`');
            foreach ($result as $row) {
                $actors[] = SetupActors::new(
                    host: (string) ($row['HOST'] ?? ''),
                    user: (string) ($row['USER'] ?? ''),
                    role: (string) ($row['ROLE'] ?? ''),
                    enabled: $this->parseEnabled((string) ($row['ENABLED'] ?? 'NO')),
                );
            }
        } catch (\PDOException) {
            // Gracefully handle PS being disabled
        }
        return $actors;
    }

    /**
     * @return list<SetupObjects>
     */
    private function loadObjects(DatabaseInterface $db): array
    {
        $objects = [];
        try {
            // ENABLED column was introduced in MySQL 5.6.3
            $version = $this->context->version();
            $hasEnabled = $version->isAtLeast(5, 6, 3);

            if ($hasEnabled) {
                $result = $db->query('SELECT `OBJECT_TYPE`, `OBJECT_SCHEMA`, `OBJECT_NAME`, `ENABLED`, `TIMED` FROM `performance_schema`.`setup_objects`');
                foreach ($result as $row) {
                    $objects[] = SetupObjects::new(
                        objectType: (string) ($row['OBJECT_TYPE'] ?? ''),
                        objectSchema: (string) ($row['OBJECT_SCHEMA'] ?? ''),
                        objectName: (string) ($row['OBJECT_NAME'] ?? ''),
                        enabled: $this->parseEnabled((string) ($row['ENABLED'] ?? 'NO')),
                        timed: $this->parseEnabled((string) ($row['TIMED'] ?? 'NO')),
                    );
                }
            } else {
                // On <5.6.3, only TIMED column exists
                $result = $db->query('SELECT `OBJECT_TYPE`, `OBJECT_SCHEMA`, `OBJECT_NAME`, `TIMED` FROM `performance_schema`.`setup_objects`');
                foreach ($result as $row) {
                    $objects[] = SetupObjects::new(
                        objectType: (string) ($row['OBJECT_TYPE'] ?? ''),
                        objectSchema: (string) ($row['OBJECT_SCHEMA'] ?? ''),
                        objectName: (string) ($row['OBJECT_NAME'] ?? ''),
                        enabled: false, // Not available on <5.6.3
                        timed: $this->parseEnabled((string) ($row['TIMED'] ?? 'NO')),
                    );
                }
            }
        } catch (\PDOException) {
            // Gracefully handle PS being disabled
        }
        return $objects;
    }

    /**
     * @return list<SetupThreads>
     */
    private function loadThreads(DatabaseInterface $db): array
    {
        $threads = [];
        try {
            $result = $db->query(
                'SELECT `THREAD_ID`, `NAME`, `TYPE`, `PROCESSLIST_ID`, `PROCESSLIST_USER`, `PROCESSLIST_COMMAND`, `PROCESSLIST_INFO`, `INSTRUMENTED` FROM `performance_schema`.`threads` LIMIT 100'
            );
            foreach ($result as $row) {
                $threads[] = SetupThreads::new(
                    threadId: (int) ($row['THREAD_ID'] ?? 0),
                    name: (string) ($row['NAME'] ?? ''),
                    type: (string) ($row['TYPE'] ?? 'FOREGROUND'),
                    processlistId: isset($row['PROCESSLIST_ID']) ? (int) $row['PROCESSLIST_ID'] : null,
                    processlistUser: isset($row['PROCESSLIST_USER']) ? (string) $row['PROCESSLIST_USER'] : null,
                    processlistCommand: isset($row['PROCESSLIST_COMMAND']) ? (string) $row['PROCESSLIST_COMMAND'] : null,
                    processlistInfo: isset($row['PROCESSLIST_INFO']) ? (string) $row['PROCESSLIST_INFO'] : null,
                    instrumented: $this->parseInstrumented((string) ($row['INSTRUMENTED'] ?? 'YES')),
                );
            }
        } catch (\PDOException) {
            // Gracefully handle PS being disabled
        }
        return $threads;
    }

    /**
     * @return list<SetupTimers>
     */
    private function loadTimers(DatabaseInterface $db): array
    {
        // On MySQL >= 8.0, setup_timers doesn't exist and timer selection is fixed
        $version = $this->context->version();
        if ($version->isAtLeast(8, 0)) {
            // Load performance_timers for display (read-only on >= 8.0)
            return $this->loadPerformanceTimers($db);
        }

        // On MySQL < 8.0, load from setup_timers (mutable)
        $timers = [];
        try {
            $result = $db->query('SELECT `NAME`, `TIMER_NAME` FROM `performance_schema`.`setup_timers`');
            foreach ($result as $row) {
                $timers[] = SetupTimers::new(
                    name: (string) ($row['NAME'] ?? ''),
                    timerName: (string) ($row['TIMER_NAME'] ?? ''),
                );
            }
        } catch (\PDOException) {
            // Gracefully handle PS being disabled - fall back to performance_timers
            $timers = $this->loadPerformanceTimers($db);
        }
        return $timers;
    }

    /**
     * Load performance_timers (available on all PS versions, but read-only).
     *
     * @return list<SetupTimers>
     */
    private function loadPerformanceTimers(DatabaseInterface $db): array
    {
        $timers = [];
        try {
            $result = $db->query('SELECT `NAME`, `TIMER_NAME` FROM `performance_schema`.`performance_timers`');
            foreach ($result as $row) {
                $timers[] = SetupTimers::new(
                    name: (string) ($row['NAME'] ?? ''),
                    timerName: (string) ($row['TIMER_NAME'] ?? ''),
                );
            }
        } catch (\PDOException) {
            // Gracefully handle PS being disabled
        }
        return $timers;
    }

    private function parseEnabled(string $value): bool
    {
        $lower = strtolower($value);
        return $lower === 'yes' || $lower === 'on' || $value === '1';
    }

    private function parseInstrumented(string $value): bool
    {
        $lower = strtolower($value);
        return $lower === 'yes' || $lower === 'on' || $value === '1';
    }

    /**
     * Fallback detection when no EasySetupDetector is wired.
     *
     * Uses the instrument data already loaded to determine setup state.
     * Note: This is less accurate than EasySetupDetector as it doesn't check
     * TIMED status or query consumers separately.
     *
     * @return string One of: 'fully', 'default', 'custom', 'disabled'
     */
    private function detectSetupState(): string
    {
        $enabledCount = 0;
        $totalCount = count($this->instruments);

        foreach ($this->instruments as $instrument) {
            if ($instrument->enabled) {
                $enabledCount++;
            }
        }

        if ($totalCount === 0) {
            return 'disabled';
        }

        $percentage = ($enabledCount / $totalCount) * 100;

        if ($percentage === 100) {
            return 'fully';
        }

        if ($percentage < 10) {
            return 'disabled';
        }

        // Check for default MySQL setup
        $defaultCategories = ['stage', 'statement', 'wait'];
        $hasDefaultOnly = true;

        foreach ($this->instruments as $instrument) {
            if (!$instrument->enabled) {
                continue;
            }
            $parts = explode('/', $instrument->name);
            if (count($parts) > 0 && !in_array($parts[0], $defaultCategories, true)) {
                $hasDefaultOnly = false;
                break;
            }
        }

        return $hasDefaultOnly ? 'default' : 'custom';
    }

    private function hasUpdatePrivilege(): bool
    {
        // SQL-P01: probe once per session, not once per frame.
        if ($this->updatePrivilegeMemo !== null) {
            return $this->updatePrivilegeMemo;
        }
        try {
            // Try a test UPDATE to see if we have UPDATE privilege
            $this->context->connection()->exec(
                'UPDATE `performance_schema`.`setup_consumers` SET `ENABLED` = `ENABLED` WHERE 1=0'
            );
            return $this->updatePrivilegeMemo = true;
        } catch (\PDOException) {
            return $this->updatePrivilegeMemo = false;
        }
    }

    /**
     * SQL-P01 invalidation hook: drop the memoized privilege probe so the
     * next frame re-checks. Call on any context/session identity change
     * (a fresh page already re-probes because the memo is page-local).
     * SQL-P07: a session identity change changes far more than the probe —
     * rows, access visibility and the tree are all last-session facts — so
     * the hook now re-arms the whole arrival-scoped state, not just the memo.
     */
    public function forgetPrivilegeProbe(): void
    {
        $this->updatePrivilegeMemo = null;
        $this->snapshotLoaded = false;
        $this->accessMemo = null;
        $this->treeMemo = null;
    }

    private function getErrorMessage(\PDOException $e): string
    {
        $code = (string) $e->getCode();

        return match ($code) {
            '1142', '1227' => 'Access denied: missing required privileges',
            '1146', '42S02' => 'Performance Schema is not enabled',
            '2002', '2003', '2013', '08000', '08006' => 'Cannot connect to database',
            default => 'Error: ' . $e->getMessage(),
        };
    }

    // ─── Filtering ────────────────────────────────────────────────────────────

    /**
     * @return list<SetupInstruments>
     */
    private function getFilteredInstruments(): array
    {
        return $this->instruments;
    }

    /**
     * @return list<SetupConsumers>
     */
    private function getFilteredConsumers(): array
    {
        return $this->consumers;
    }

    /**
     * @return list<SetupActors>
     */
    private function getFilteredActors(): array
    {
        return $this->actors;
    }

    /**
     * @return list<SetupObjects>
     */
    private function getFilteredObjects(): array
    {
        return $this->objects;
    }

    private function getMaxRowIndex(): int
    {
        return match ($this->activeTab) {
            self::TAB_INSTRUMENTS => count($this->instruments) - 1,
            self::TAB_CONSUMERS => count($this->consumers) - 1,
            self::TAB_ACTORS => count($this->actors) - 1,
            self::TAB_OBJECTS => count($this->objects) - 1,
            self::TAB_THREADS => count($this->threads) - 1,
            self::TAB_OPTIONS => count($this->timers) - 1,
            default => 0,
        };
    }

    // ─── Rendering ───────────────────────────────────────────────────────────

    private function renderHeader(): string
    {
        $title = Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render('Performance Schema');

        // NB: the old code built the READ-ONLY marker with a single-quoted
        // escape sequence — i.e. a literal backslash-x string, not a real SGR —
        // so it printed garbage. Routing through Style emits a proper sequence.
        $readOnly = $this->readOnlyMode
            ? ' ' . Style::new()->foreground(Color::hex('#f38ba8'))->render('[READ ONLY]')
            : '';

        return "{$title} | {$this->stateBadge()}{$readOnly}";
    }

    /**
     * The setup-state chip shown in the header — a sugar-dashboard Badge so the
     * FULLY/DEFAULT/CUSTOM/DISABLED status colouring lives in the widget.
     */
    private function stateBadge(): string
    {
        return match ($this->setupState) {
            'fully' => Badge::success('FULLY')->render(),
            'default' => Badge::warning('DEFAULT')->render(),
            'custom' => Badge::info('CUSTOM')->render(),
            'disabled' => Badge::error('DISABLED')->render(),
            default => Badge::new('UNKNOWN')->render(),
        };
    }

    private function renderTabBar(): string
    {
        $activeIndex = array_search($this->activeTab, self::TABS, true);

        // Bits\Tabs owns the active/inactive styling and the divider. Width 0
        // disables the widget's truncation guards (the final one clips on
        // ANSI-inclusive byte length); the 7 labels + '│' dividers come to ~77
        // visible cells, which fits an 80-column terminal like the old bar did.
        $tabs = Tabs::new(array_values(self::TAB_LABELS), 0)
            ->withActive($activeIndex === false ? 0 : $activeIndex)
            ->withDivider('│')
            ->withActiveStyle(Style::new()->bold()->foreground(Color::hex('#fde047')))
            ->withInactiveStyle(Style::new()->foreground(Color::hex('#6b7280')));

        return ' ' . $tabs->view();
    }

    private function renderContent(): string
    {
        return match ($this->activeTab) {
            self::TAB_EASY_SETUP => $this->renderEasySetupTab(),
            self::TAB_INSTRUMENTS => $this->renderInstrumentsTab(),
            self::TAB_CONSUMERS => $this->renderConsumersTab(),
            self::TAB_ACTORS => $this->renderActorsTab(),
            self::TAB_OBJECTS => $this->renderObjectsTab(),
            self::TAB_THREADS => $this->renderThreadsTab(),
            self::TAB_OPTIONS => $this->renderOptionsTab(),
            default => '',
        };
    }

    private function renderEasySetupTab(): string
    {
        $muted = Style::new()->foreground(Color::hex('#6b7280'));

        $stateLabel = match ($this->setupState) {
            'fully' => Style::new()->foreground(Color::hex('#a6e3a1'))->render('Fully Enabled'),
            'default' => Style::new()->foreground(Color::hex('#f9e2af'))->render('Default Setup'),
            'custom' => Style::new()->foreground(Color::hex('#89b4fa'))->render('Custom Setup'),
            'disabled' => Style::new()->foreground(Color::hex('#f38ba8'))->render('Disabled'),
            default => $muted->render('Unknown'),
        };

        $lines = [];
        $lines[] = $this->tabTitle('Easy Setup');
        $lines[] = Divider::h()->render();
        $lines[] = sprintf('  Current State: %s', $stateLabel);
        $lines[] = '';

        if ($this->readOnlyMode) {
            $lines[] = $muted->render('  (Read-only mode - no privileges to modify)');
        } else {
            $lines[] = '  [: Enable Full PS   ]: Disable PS   .: Reset to Defaults';
        }

        $lines[] = '';
        $lines[] = $muted->render('  Default instruments: stage/%, statement/%, wait/%');
        $lines[] = $muted->render('  Default consumers: events_statements_history, events_waits_history, etc.');

        return implode("\n", $lines);
    }

    private function renderInstrumentsTab(): string
    {
        // Instruments are ordered hierarchically via InstrumentTree, then
        // flattened with depth info. Group nodes (non-leaf) show tri-state badges.
        $rows = [];
        if ($this->instruments !== []) {
            // SQL-P07: the parse (~1,500 group+leaf nodes) ran every render
            // frame; memoized until a writer of $instruments runs (loadData,
            // toggleInstrument both clear it — exact, no diffing needed).
            if ($this->treeMemo === null) {
                $this->treeMemo = InstrumentTree::fromInstruments($this->instruments);
            }
            $tree = $this->treeMemo;
            foreach ($this->flattenTree($tree) as [$nodeOrInstrument, $depth, $isGroup]) {
                if ($isGroup) {
                    // Group node — show tri-state badge with group name
                    $node = $nodeOrInstrument;
                    $triState = $node->state();
                    // triState: 1=enabled, -1=disabled, 0=mixed
                    // Badge::tristate: true=[x], false=[ ], null=[~]
                    $badgeState = $triState === 1 ? true : ($triState === -1 ? false : null);
                    $groupName = $node->name();
                    $indent = str_repeat('  ', $depth);
                    $displayName = $indent . '[' . $groupName . ']';
                    $rows[] = [$badgeState, Width::truncateMiddle($displayName, 50), true];
                } else {
                    // Instrument node — show enabled badge with instrument name
                    $instrument = $nodeOrInstrument;
                    $indent = str_repeat('  ', $depth);
                    $displayName = $indent . Width::truncateMiddle($instrument->name, 50 - $depth * 2);
                    $rows[] = [$instrument->enabled, $displayName, false];
                }
            }
        }

        return $this->renderToggleList(
            'Instruments',
            $rows,
            '(no instruments available)',
            sprintf('Total: %d instruments', count($this->instruments)),
        );
    }

    private function renderConsumersTab(): string
    {
        $rows = [];
        foreach ($this->consumers as $consumer) {
            $rows[] = [$consumer->enabled, $consumer->name];
        }

        return $this->renderToggleList(
            'Consumers',
            $rows,
            '(no consumers available)',
            sprintf('Total: %d consumers', count($this->consumers)),
        );
    }

    private function renderActorsTab(): string
    {
        $rows = [];
        foreach ($this->actors as $actor) {
            // Actor identity comes from performance_schema users() — server text.
            $rows[] = [$actor->enabled, CellValue::sanitize(sprintf('%s/%s/%s', $actor->host, $actor->user, $actor->role))];
        }

        return $this->renderToggleList(
            'Actors',
            $rows,
            '(no actors configured)',
            sprintf('Total: %d actors', count($this->actors)),
        );
    }

    private function renderObjectsTab(): string
    {
        $rows = [];
        foreach ($this->objects as $object) {
            // Object rules come from performance_schema setup_objects — server text.
            $rows[] = [$object->enabled, CellValue::sanitize(sprintf('%s:%s.%s', $object->objectType, $object->objectSchema, $object->objectName))];
        }

        return $this->renderToggleList(
            'Objects',
            $rows,
            '(no object rules configured)',
            sprintf('Total: %d object rules', count($this->objects)),
        );
    }

    private function renderThreadsTab(): string
    {
        if ($this->threads === []) {
            return implode("\n", [
                $this->tabTitle('Threads'),
                Divider::h()->render(),
                Style::new()->faint()->render('  (no threads available)'),
            ]);
        }

        // sugar-table draws the column header + selectable highlight; the query
        // already caps at 100 rows, so no hand-rolled "… and N more" needed.
        $columns = [
            Column::new('id', 'Thread ID', 10)->withAlignLeft(true),
            Column::new('name', 'Name', 30)->withAlignLeft(true),
            Column::new('type', 'Type', 12)->withAlignLeft(true),
            Column::new('user', 'User', 12)->withAlignLeft(true),
        ];

        $rows = [];
        foreach ($this->threads as $thread) {
            $rows[] = Row::new(RowData::from([
                'id' => (string) $thread->threadId,
                'name' => Width::truncateMiddle($thread->name, 30),
                'type' => $thread->type,
                'user' => $thread->processlistUser ?? '-',
            ]));
        }

        $table = Table::fromColumns($columns)
            ->withRows($rows)
            ->withSelectable(true)
            ->withSelectedIndex($this->selectedRowIndex)
            ->withShowFooter(false)
            ->View();

        return implode("\n", [
            $this->tabTitle('Threads'),
            Divider::h()->render(),
            $table,
            '',
            Style::new()->foreground(Color::hex('#6b7280'))->render(sprintf('Total: %d threads', count($this->threads))),
        ]);
    }

    private function renderOptionsTab(): string
    {
        if ($this->timers === []) {
            return implode("\n", [
                $this->tabTitle('Timer Options'),
                Divider::h()->render(),
                Style::new()->faint()->render('  (no timers available - read-only)'),
            ]);
        }

        $columns = [
            Column::new('name', 'Timer Name', 14)->withAlignLeft(true),
            Column::new('impl', 'Implementation', 22)->withAlignLeft(true),
            Column::new('scale', 'Scale Factor', 14),
        ];

        $rows = [];
        foreach ($this->timers as $timer) {
            $rows[] = Row::new(RowData::from([
                'name' => $timer->name,
                'impl' => $timer->timerName,
                'scale' => match ($timer->timerName) {
                    'NANOSECOND' => '1 ns',
                    'MICROSECOND' => '1 µs',
                    'MILLISECOND' => '1 ms',
                    'SECOND' => '1 s',
                    default => $timer->timerName,
                },
            ]));
        }

        $table = Table::fromColumns($columns)->withRows($rows)->withShowFooter(false)->View();

        return implode("\n", [
            $this->tabTitle('Timer Options'),
            Divider::h()->render(),
            $table,
            '',
            Style::new()->foreground(Color::hex('#6b7280'))->render('Timer configuration is read-only (determined by server build)'),
        ]);
    }

    /**
     * The bold purple per-tab section title (was a hand-rolled bold-magenta SGR).
     */
    private function tabTitle(string $title): string
    {
        return Style::new()->bold()->foreground(Color::hex('#c084fc'))->render($title);
    }

    /**
     * Render a selectable toggle list (instruments / consumers / actors /
     * objects) through Forms\ItemList. Each row's tri-state is a
     * Dash\Badge::tristate() glyph; ItemList owns the cursor highlight and the
     * scroll window, so there is no hand-rolled selection prefix or row cap.
     *
     * Row formats:
     *   - Instruments (groups): [bool|null $badgeState, string $displayText, true $isGroupFlag]
     *     badgeState: true=[x], false=[ ], null=[~] (mixed)
     *   - Instruments (leaves) / consumers / actors / objects: [bool $enabled, string $displayText]
     *
     * @param list<array{0:bool|string,1:string,2?:bool}> $rows
     */
    private function renderToggleList(string $title, array $rows, string $emptyMessage, string $totalLine): string
    {
        $header = $this->tabTitle($title);
        $divider = Divider::h()->render();

        if ($rows === []) {
            return implode("\n", [$header, $divider, Style::new()->faint()->render('  ' . $emptyMessage)]);
        }

        $items = [];
        foreach ($rows as $row) {
            if (count($row) === 3 && $row[2] === true) {
                // Group node: badgeState is at position 0 (can be null for mixed)
                $badgeState = $row[0];
                $text = $row[1];
                $badge = Badge::tristate($badgeState)->render();
            } else {
                // Regular row: enabled is at position 0
                $enabled = $row[0];
                $text = $row[1];
                $badge = Badge::tristate($enabled)->render();
            }
            $items[] = new StringItem($badge . ' ' . $text);
        }

        $list = ItemList::new($items, 80, min(20, max(1, count($items))))
            ->withShowStatusBar(false)
            ->withShowHelp(false)
            ->withShowFilter(false)
            ->select($this->selectedRowIndex);

        return implode("\n", [
            $header,
            $divider,
            $list->view(),
            '',
            Style::new()->foreground(Color::hex('#6b7280'))->render($totalLine),
        ]);
    }

    private function renderFooter(): string
    {
        $navHint = '[j/k] nav  [Space] toggle';
        $actionHint = $this->isDirty() ? '  [c] stage commit ([y] to fire)  [r] revert' : '';
        $quitHint = '  [q] back';
        $base = Style::new()->foreground(Color::hex('#6b7280'))->render($navHint . $actionHint . $quitHint);

        // These two indicators used single-quoted escape literals before, i.e.
        // literal backslash-x text rather than escapes — Style fixes the bug.
        $dirtyCount = $this->countDirty();
        $dirtyIndicator = $dirtyCount > 0
            ? '  ' . Style::new()->foreground(Color::hex('#f9e2af'))
                ->render(sprintf('Pending: %d change%s', $dirtyCount, $dirtyCount === 1 ? '' : 's'))
            : '';

        $readOnlyIndicator = $this->readOnlyMode
            ? '  ' . Style::new()->foreground(Color::hex('#f38ba8'))->render('[READ ONLY]')
            : '';

        return $base . $dirtyIndicator . $readOnlyIndicator;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function flattenTree(InstrumentTree $tree): array
    {
        $result = [];

        // Depth-first traversal that includes both instruments and group nodes
        // Returns array of [instrumentOrNode, depth, isGroupNode]
        $stack = [[$tree, 0]];
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);

            if ($node->instrument() !== null) {
                // This is an instrument node (leaf)
                $result[] = [$node->instrument(), $depth, false];
            } else {
                // This is a group node (non-leaf)
                // Include group nodes in the flattened list so they render as indent rows
                $result[] = [$node, $depth, true];
            }

            // Add children in reverse order so first child is processed first
            $children = $node->children();
            $childKeys = array_keys($children);
            for ($i = count($childKeys) - 1; $i >= 0; $i--) {
                $childKey = $childKeys[$i];
                $stack[] = [$children[$childKey], $depth + 1];
            }
        }

        return $result;
    }

    private function isDirty(): bool
    {
        if ($this->changeTracker === null) {
            return false;
        }

        foreach ($this->instruments as $instrument) {
            if ($instrument->isDirty()) {
                return true;
            }
        }

        foreach ($this->consumers as $consumer) {
            if ($consumer->isDirty()) {
                return true;
            }
        }

        foreach ($this->actors as $actor) {
            if ($actor->isDirty()) {
                return true;
            }
        }

        foreach ($this->objects as $object) {
            if ($object->isDirty()) {
                return true;
            }
        }

        return false;
    }

    private function countDirty(): int
    {
        $count = 0;

        foreach ($this->instruments as $instrument) {
            if ($instrument->isDirty()) {
                $count++;
            }
        }

        foreach ($this->consumers as $consumer) {
            if ($consumer->isDirty()) {
                $count++;
            }
        }

        foreach ($this->actors as $actor) {
            if ($actor->isDirty()) {
                $count++;
            }
        }

        foreach ($this->objects as $object) {
            if ($object->isDirty()) {
                $count++;
            }
        }

        return $count;
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    public function activeTab(): string
    {
        return $this->activeTab;
    }

    public function selectedRowIndex(): int
    {
        return $this->selectedRowIndex;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnlyMode;
    }

    public function setupState(): string
    {
        return $this->setupState;
    }

    /**
     * @return list<SetupInstruments>
     */
    public function instruments(): array
    {
        return $this->instruments;
    }

    /**
     * @return list<SetupConsumers>
     */
    public function consumers(): array
    {
        return $this->consumers;
    }

    /**
     * @return list<SetupActors>
     */
    public function actors(): array
    {
        return $this->actors;
    }

    /**
     * @return list<SetupObjects>
     */
    public function objects(): array
    {
        return $this->objects;
    }

    /**
     * @return list<SetupThreads>
     */
    public function threads(): array
    {
        return $this->threads;
    }

    /**
     * @return list<SetupTimers>
     */
    public function timers(): array
    {
        return $this->timers;
    }
}
