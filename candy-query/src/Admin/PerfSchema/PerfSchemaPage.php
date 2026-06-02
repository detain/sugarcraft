<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;

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
        $stateLabel = match ($this->setupState) {
            'fully' => "\x1b[32mFULLY\x1b[0m",
            'default' => "\x1b[33mDEFAULT\x1b[0m",
            'custom' => "\x1b[36mCUSTOM\x1b[0m",
            'disabled' => "\x1b[31mDISABLED\x1b[0m",
            default => "\x1b[90mUNKNOWN\x1b[0m",
        };

        $readOnlyLabel = $this->readOnlyMode ? ' \x1b[31m[READ ONLY]\x1b[0m' : '';

        return "\x1b[1;36mPerformance Schema\x1b[0m | {$stateLabel}{$readOnlyLabel}";
    }

    private function renderTabBar(): string
    {
        $tabs = [];

        foreach (self::TABS as $tab) {
            $label = self::TAB_LABELS[$tab];
            $isActive = $tab === $this->activeTab;

            $tabStr = $isActive
                ? "\x1b[1;33m[{$label}]\x1b[0m"
                : "\x1b[90m[{$label}]\x1b[0m";

            $tabs[] = $tabStr;
        }

        return '  ' . implode(' ', $tabs);
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
        $lines = [];
        $lines[] = "\x1b[1;35mEasy Setup\x1b[0m";
        $lines[] = $this->renderSeparator();

        $stateLabel = match ($this->setupState) {
            'fully' => "\x1b[32mFully Enabled\x1b[0m",
            'default' => "\x1b[33mDefault Setup\x1b[0m",
            'custom' => "\x1b[36mCustom Setup\x1b[0m",
            'disabled' => "\x1b[31mDisabled\x1b[0m",
            default => "\x1b[90mUnknown\x1b[0m",
        };

        $lines[] = sprintf('  Current State: %s', $stateLabel);
        $lines[] = '';

        if ($this->readOnlyMode) {
            $lines[] = "\x1b[90m  (Read-only mode - no privileges to modify)\x1b[0m";
        } else {
            $lines[] = '  [1] Enable Full PS';
            $lines[] = '  [2] Disable PS';
            $lines[] = '  [3] Reset to Defaults';
        }

        $lines[] = '';
        $lines[] = "\x1b[90m  Default instruments: stage/%, statement/%, wait/%\x1b[0m";
        $lines[] = "\x1b[90m  Default consumers: events_statements_history, events_waits_history, etc.\x1b[0m";

        return implode("\n", $lines);
    }

    private function renderInstrumentsTab(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mInstruments\x1b[0m";
        $lines[] = $this->renderSeparator();

        if ($this->instruments === []) {
            $lines[] = "\x1b[90m  (no instruments available)\x1b[0m";
            return implode("\n", $lines);
        }

        // Build tree for hierarchical display
        $tree = InstrumentTree::fromInstruments($this->instruments);
        $flatList = $this->flattenTree($tree);

        $maxDisplay = min(50, count($flatList));
        for ($i = 0; $i < $maxDisplay; $i++) {
            $instrument = $flatList[$i];
            $isSelected = $i === $this->selectedRowIndex;
            $stateIndicator = $this->renderTristate($instrument->enabled);

            $displayName = $instrument->name;
            if (strlen($displayName) > 50) {
                $displayName = '...' . substr($displayName, -47);
            }

            $prefix = $isSelected ? "\x1b[7m> \x1b[0m" : '  ';
            $line = sprintf('%s%s %s', $prefix, $stateIndicator, $displayName);

            if ($isSelected) {
                $line = "\x1b[1;37m" . substr($line, 0, 2) . "\x1b[0m" . substr($line, 2);
            }

            $lines[] = $line;
        }

        if (count($flatList) > $maxDisplay) {
            $lines[] = sprintf("\x1b[90m  ... and %d more instruments\x1b[0m", count($flatList) - $maxDisplay);
        }

        $lines[] = '';
        $lines[] = sprintf("\x1b[90m  Total: %d instruments\x1b[0m", count($this->instruments));

        return implode("\n", $lines);
    }

    private function renderConsumersTab(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mConsumers\x1b[0m";
        $lines[] = $this->renderSeparator();

        if ($this->consumers === []) {
            $lines[] = "\x1b[90m  (no consumers available)\x1b[0m";
            return implode("\n", $lines);
        }

        foreach ($this->consumers as $index => $consumer) {
            $isSelected = $index === $this->selectedRowIndex;
            $stateIndicator = $this->renderTristate($consumer->enabled);

            $prefix = $isSelected ? "\x1b[7m> \x1b[0m" : '  ';
            $line = sprintf('%s%s %s', $prefix, $stateIndicator, $consumer->name);

            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = sprintf("\x1b[90m  Total: %d consumers\x1b[0m", count($this->consumers));

        return implode("\n", $lines);
    }

    private function renderActorsTab(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mActors\x1b[0m";
        $lines[] = $this->renderSeparator();

        if ($this->actors === []) {
            $lines[] = "\x1b[90m  (no actors configured)\x1b[0m";
            return implode("\n", $lines);
        }

        foreach ($this->actors as $index => $actor) {
            $isSelected = $index === $this->selectedRowIndex;
            $stateIndicator = $this->renderTristate($actor->enabled);

            $display = sprintf('%s/%s/%s', $actor->host, $actor->user, $actor->role);

            $prefix = $isSelected ? "\x1b[7m> \x1b[0m" : '  ';
            $line = sprintf('%s%s %s', $prefix, $stateIndicator, $display);

            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = sprintf("\x1b[90m  Total: %d actors\x1b[0m", count($this->actors));

        return implode("\n", $lines);
    }

    private function renderObjectsTab(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mObjects\x1b[0m";
        $lines[] = $this->renderSeparator();

        if ($this->objects === []) {
            $lines[] = "\x1b[90m  (no object rules configured)\x1b[0m";
            return implode("\n", $lines);
        }

        foreach ($this->objects as $index => $object) {
            $isSelected = $index === $this->selectedRowIndex;
            $stateIndicator = $this->renderTristate($object->enabled);

            $display = sprintf('%s:%s.%s', $object->objectType, $object->objectSchema, $object->objectName);

            $prefix = $isSelected ? "\x1b[7m> \x1b[0m" : '  ';
            $line = sprintf('%s%s %s', $prefix, $stateIndicator, $display);

            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = sprintf("\x1b[90m  Total: %d object rules\x1b[0m", count($this->objects));

        return implode("\n", $lines);
    }

    private function renderThreadsTab(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mThreads\x1b[0m";
        $lines[] = $this->renderSeparator();

        if ($this->threads === []) {
            $lines[] = "\x1b[90m  (no threads available)\x1b[0m";
            return implode("\n", $lines);
        }

        $lines[] = sprintf(
            "  \x1b[1;33m%-8s %-30s %-12s %-10s\x1b[0m",
            'Thread ID',
            'Name',
            'Type',
            'User'
        );
        $lines[] = $this->renderSeparator();

        $maxDisplay = min(30, count($this->threads));
        for ($i = 0; $i < $maxDisplay; $i++) {
            $thread = $this->threads[$i];
            $isSelected = $i === $this->selectedRowIndex;

            $name = $thread->name;
            if (strlen($name) > 28) {
                $name = '...' . substr($name, -25);
            }

            $prefix = $isSelected ? "\x1b[7m>\x1b[0m" : ' ';
            $lines[] = sprintf(
                '%s %-8d %-30s %-12s %-10s',
                $prefix,
                $thread->threadId,
                $name,
                $thread->type,
                $thread->processlistUser ?? '-'
            );
        }

        if (count($this->threads) > $maxDisplay) {
            $lines[] = sprintf("\x1b[90m  ... and %d more threads\x1b[0m", count($this->threads) - $maxDisplay);
        }

        $lines[] = '';
        $lines[] = sprintf("\x1b[90m  Total: %d threads\x1b[0m", count($this->threads));

        return implode("\n", $lines);
    }

    private function renderOptionsTab(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mTimer Options\x1b[0m";
        $lines[] = $this->renderSeparator();

        if ($this->timers === []) {
            $lines[] = "\x1b[90m  (no timers available - read-only)\x1b[0m";
            return implode("\n", $lines);
        }

        $lines[] = sprintf(
            "  \x1b[1;33m%-12s %-20s %-15s\x1b[0m",
            'Timer Name',
            'Implementation',
            'Scale Factor'
        );
        $lines[] = $this->renderSeparator();

        foreach ($this->timers as $timer) {
            $lines[] = sprintf(
                '  %-12s %-20s %-15.2f',
                $timer->name,
                $timer->timerName,
                $timer->scaleFactor
            );
        }

        $lines[] = '';
        $lines[] = "\x1b[90m  Timer configuration is read-only (determined by server build)\x1b[0m";

        return implode("\n", $lines);
    }

    private function renderTristate(bool $enabled): string
    {
        return $enabled ? "\x1b[32m[x]\x1b[0m" : "\x1b[90m[ ]\x1b[0m";
    }

    private function renderTristateMixed(): string
    {
        return "\x1b[33m[~]\x1b[0m";
    }

    private function renderSeparator(): string
    {
        return "\x1b[36m──\x1b[0m" . str_repeat('─', 20);
    }

    private function renderFooter(): string
    {
        $dirtyCount = $this->countDirty();
        $dirtyIndicator = $dirtyCount > 0
            ? sprintf(' \x1b[33mPending: %d change%s\x1b[0m', $dirtyCount, $dirtyCount === 1 ? '' : 's')
            : '';

        $readOnlyIndicator = $this->readOnlyMode ? ' \x1b[31m[READ ONLY]\x1b[0m' : '';

        $navHint = '[j/k] nav  [Space] toggle  [Tab] tabs';
        $actionHint = $this->isDirty() ? '  [c] commit  [r] revert' : '';
        $quitHint = '  [q] quit';

        return "\x1b[90m{$navHint}{$actionHint}{$quitHint}{$dirtyIndicator}{$readOnlyIndicator}\x1b[0m";
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
