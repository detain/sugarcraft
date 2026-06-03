<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Bits\Tabs\Tabs;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Components\Card\Badge;
use SugarCraft\Dash\Components\Card\Divider;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;

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
 * Keyboard shortcuts:
 *   [j/k] or [↑/↓] - Navigate items
 *   [Space] or [Enter] - Toggle/select item
 *   [Tab] - Switch tabs
 *   [c] - Commit pending changes (when dirty)
 *   [r] - Revert pending changes
 *   [q] - Quit to previous view
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema
 */
final class PerfSchemaPage extends PageBase
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

    /** @var list<string> Tab display labels */
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

    public function __construct(
        ServerContextInterface $context,
        ?EasySetupDetector $detector = null,
        ?CommitPlanner $commitPlanner = null,
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
    ): self {
        return new self($context, $detector, $commitPlanner);
    }

    /**
     * Verify we can access Performance Schema before rendering.
     */
    protected function validate(): bool
    {
        try {
            // Try to query setup_instruments to verify PS is accessible
            $result = $this->context->connection()->query(
                'SELECT COUNT(*) FROM `performance_schema`.`setup_instruments` WHERE `NAME` NOT LIKE \'memory/%\''
            );
            return is_array($result);
        } catch (\PDOException $e) {
            $this->errorMessage = $this->getErrorMessage($e);
            return false;
        }
    }

    /**
     * Build the complete page output.
     */
    protected function build(): string
    {
        $this->loadData();

        $lines = [];

        $lines[] = $this->renderHeader();
        $lines[] = '';
        $lines[] = $this->renderTabBar();
        $lines[] = '';
        $lines[] = $this->renderContent();
        $lines[] = '';
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
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        // Ensure data is loaded for navigation operations
        if ($this->instruments === [] && $this->consumers === []) {
            $this->loadData();
        }

        $ch = $msg->rune ?? '';
        $type = $msg->type;

        // Tab switches tabs
        if ($type === KeyType::Tab && !$msg->shift) {
            return [$this->withNextTab(), null];
        }

        // Shift+Tab goes to previous tab
        if ($type === KeyType::Tab && $msg->shift) {
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

        // c commits pending changes
        if ($ch === 'c' && $this->isDirty()) {
            return $this->handleCommit();
        }

        // r reverts pending changes
        if ($ch === 'r' && $this->isDirty()) {
            return [$this->withRevert(), null];
        }

        // Number keys for Easy Setup tab
        if (($ch === '1' || $ch === '2' || $ch === '3') && $this->activeTab === self::TAB_EASY_SETUP) {
            return $this->handleEasySetupAction((int) $ch);
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

    private function handleCommit(): array
    {
        if ($this->commitPlanner === null || !$this->isDirty()) {
            return [$this, null];
        }

        try {
            $statements = $this->commitPlanner->commitAll();
            $db = $this->context->connection();

            foreach ($statements as $sql) {
                $db->exec($sql);
            }

            // Reload data after commit
            $clone = clone $this;
            $clone->loadData();
            if ($clone->changeTracker !== null) {
                $clone->changeTracker->commit();
            }

            return [$clone, null];
        } catch (\PDOException $e) {
            $this->errorMessage = 'Commit failed: ' . $e->getMessage();
            return [$this, null];
        }
    }

    /**
     * Handle Easy Setup tab actions (1/2/3 keys).
     */
    private function handleEasySetupAction(int $action): array
    {
        if ($this->readOnlyMode) {
            return [$this, null];
        }

        try {
            $statements = match ($action) {
                1 => EasySetup::new()->enableStatements(),
                2 => EasySetup::new()->disableStatements(),
                3 => EasySetup::new()->resetToDefaultStatements(),
                default => [],
            };

            if ($statements === []) {
                return [$this, null];
            }

            $db = $this->context->connection();
            foreach ($statements as $sql) {
                $db->exec($sql);
            }

            // Reload data after executing Easy Setup action
            $clone = clone $this;
            $clone->loadData();

            return [$clone, null];
        } catch (\PDOException $e) {
            $this->errorMessage = 'Easy Setup action failed: ' . $e->getMessage();
            return [$this, null];
        }
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

        // Detect setup state
        if ($this->detector !== null) {
            $this->setupState = $this->detector->detect();
        } else {
            $this->setupState = $this->detectSetupState();
        }

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
                'SELECT `THREAD_ID`, `NAME`, `TYPE`, `PROCESSLIST_ID`, `PROCESSLIST_USER`, `PROCESSLIST_COMMAND`, `PROCESSLIST_INFO` FROM `performance_schema`.`threads` LIMIT 100'
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
        $timers = [];
        try {
            $result = $db->query('SELECT `NAME`, `TIMER_NAME`, `SCALE_FACTOR` FROM `performance_schema`.`performance_timers`');
            foreach ($result as $row) {
                $timers[] = SetupTimers::new(
                    name: (string) ($row['NAME'] ?? ''),
                    timerName: (string) ($row['TIMER_NAME'] ?? ''),
                    scaleFactor: (float) ($row['SCALE_FACTOR'] ?? 1.0),
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
        try {
            // Try a test UPDATE to see if we have UPDATE privilege
            $this->context->connection()->exec(
                'UPDATE `performance_schema`.`setup_consumers` SET `ENABLED` = `ENABLED` WHERE 1=0'
            );
            return true;
        } catch (\PDOException) {
            return false;
        }
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
     * The setup-state chip shown in the header — a sugar-dash Badge so the
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
            $lines[] = '  [1] Enable Full PS';
            $lines[] = '  [2] Disable PS';
            $lines[] = '  [3] Reset to Defaults';
        }

        $lines[] = '';
        $lines[] = $muted->render('  Default instruments: stage/%, statement/%, wait/%');
        $lines[] = $muted->render('  Default consumers: events_statements_history, events_waits_history, etc.');

        return implode("\n", $lines);
    }

    private function renderInstrumentsTab(): string
    {
        // Instruments are ordered hierarchically via InstrumentTree, then
        // flattened; ItemList owns the cursor highlight + scroll window, so the
        // old 50-row cap and "… and N more" indicator are gone.
        $rows = [];
        if ($this->instruments !== []) {
            foreach ($this->flattenTree(InstrumentTree::fromInstruments($this->instruments)) as $instrument) {
                $rows[] = [$instrument->enabled, Width::truncateMiddle($instrument->name, 50)];
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
            $rows[] = [$actor->enabled, sprintf('%s/%s/%s', $actor->host, $actor->user, $actor->role)];
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
            $rows[] = [$object->enabled, sprintf('%s:%s.%s', $object->objectType, $object->objectSchema, $object->objectName)];
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

        $table = Table::withColumns($columns)
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
                'scale' => number_format($timer->scaleFactor, 2),
            ]));
        }

        $table = Table::withColumns($columns)->withRows($rows)->withShowFooter(false)->View();

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
     * @param list<array{0:bool,1:string}> $rows  [enabled, displayText] pairs
     */
    private function renderToggleList(string $title, array $rows, string $emptyMessage, string $totalLine): string
    {
        $header = $this->tabTitle($title);
        $divider = Divider::h()->render();

        if ($rows === []) {
            return implode("\n", [$header, $divider, Style::new()->faint()->render('  ' . $emptyMessage)]);
        }

        $items = [];
        foreach ($rows as [$enabled, $text]) {
            $items[] = new StringItem(Badge::tristate($enabled)->render() . ' ' . $text);
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
        $navHint = '[j/k] nav  [Space] toggle  [Tab] tabs';
        $actionHint = $this->isDirty() ? '  [c] commit  [r] revert' : '';
        $quitHint = '  [q] quit';
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

        // Depth-first traversal
        $stack = [[$tree, 0]];
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            if ($node->instrument() !== null) {
                $result[] = $node->instrument();
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
