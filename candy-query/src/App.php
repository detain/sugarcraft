<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Query\Admin\AdminPane;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\CachingServerContext;
use SugarCraft\Query\Admin\EmptyServerContext;
use SugarCraft\Query\Admin\Connections\ConnectionsPage;
use SugarCraft\Query\Admin\Dashboard\DashboardPage;
use SugarCraft\Query\Admin\Debug\DebugPage;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\PostgresServerContext;
use SugarCraft\Query\Admin\ReactMysqlConnection;
use SugarCraft\Query\Admin\ReactPostgresConnection;
use SugarCraft\Query\Admin\Reports\ReportsPage;
use SugarCraft\Query\Admin\Providers\PostgresAdminProvider;
use SugarCraft\Query\Admin\PerfSchema\PerfSchemaPage;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Query\Admin\Variables\Catalog;
use SugarCraft\Query\Admin\Variables\VariableEditor;
use SugarCraft\Query\Admin\Variables\VariablesPage;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\App\AppBuilder;
use SugarCraft\Query\Admin\History\HistoryRecorder;
use SugarCraft\Query\Admin\QueryLogger;
use SugarCraft\Query\Admin\StatusSnapshot;
use SugarCraft\Query\Core\Msg\AdminDataLoadedMsg;
use SugarCraft\Query\Core\Msg\AdminFetchStartedMsg;
use SugarCraft\Query\Core\Msg\ReloadReportMsg;

/**
 * SQLite browser as a SugarCraft Model. Three panes:
 *
 *   - Tables: a list of tables. Enter loads its rows into the
 *             rows pane; the rows pane's title updates accordingly.
 *   - Rows:   a paginated peek at the selected table's contents.
 *   - Query:  an editor — type SQL, Ctrl+Enter to run. Errors land
 *             on a status line; the rowset replaces the rows pane.
 *
 * Tab cycles focus; j/k or arrows move the cursor in list panes;
 * `q` quits.
 *
 * Query editor:
 *   - The Query pane is backed by a candy-forms {@see TextArea} — it owns
 *     the cursor, UTF-8 editing, and Up/Down line movement.
 *   - Ctrl+R / Ctrl+E run the query; Ctrl+F favorites it; Ctrl+Shift+F
 *     unfavorites. Executed queries are recorded in {@see $queryHistory}.
 */
final class App implements Model
{
    use Mutable;

    /**
     * @param list<string> $tables
     * @param list<array<string,mixed>> $rows
     * @param list<string> $queryHistory Recently executed queries (newest first)
     * @param list<string> $queryFavorites Saved/favorited queries
     * @param TextArea|null $queryEditor Multi-line SQL editor widget (candy-forms);
     *        null until the Query pane is first focused (see {@see editor()}).
     */
    public function __construct(
        public readonly DatabaseInterface $db,
        public readonly Flavor $flavor = Flavor::Sqlite,
        public readonly array $tables = [],
        public readonly int $tableCursor = 0,
        public readonly ?string $selectedTable = null,
        public readonly array $rows = [],
        public readonly int $rowCursor = 0,
        public readonly ?ResultTable $resultTable = null,
        public readonly ?TextArea $queryEditor = null,
        public readonly Pane $pane = Pane::Tables,
        public readonly ?string $error = null,
        public readonly ?string $status = null,
        public readonly array $queryHistory = [],
        public readonly array $queryFavorites = [],
        public readonly AdminPane $adminPane = AdminPane::ProcessList,
        public readonly int $adminCursor = 0,
        public readonly bool $paused = false,
        public readonly ?ServerContextInterface $serverContext = null,
        public readonly ?PageBase $adminPage = null,
        public readonly ?array $adminCachedStatusVars = null,
        public readonly ?array $adminCachedServerVars = null,
        public readonly float $adminCacheTs = 0.0,
        public readonly bool $adminLoading = false,
        public readonly ?HistoryRecorder $historyRecorder = null,
    ) {}

    /**
     * @param Flavor::* $flavor Database flavor for driver-specific behavior
     */
    public static function start(DatabaseInterface $db, Flavor $flavor = Flavor::Sqlite): self
    {
        $tables = $db->tables();
        $a = new self(db: $db, flavor: $flavor, tables: $tables, adminPane: AdminPane::ProcessList, adminCursor: 0, paused: false);
        if ($tables !== []) {
            $a = $a->loadTable($tables[0]);
        }
        return $a;
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * Create an App via a fluent builder.
     */
    public static function builder(): AppBuilder
    {
        return new AppBuilder();
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            // The Program is the single source of truth for terminal size:
            // it emits this at startup and on every SIGWINCH. Forward it to
            // the renderer so our layout always matches the screen the
            // framework's own renderer is driving (no stale/independent
            // detection, and resizes are honored).
            Renderer::setSize($msg->cols, $msg->rows);
            return [$this, null];
        }
        if ($msg instanceof AdminFetchStartedMsg) {
            if (!$this->adminLoading) {
                return [$this->withAdminLoading(true), null];
            }
            return [$this, null];
        }
        if ($msg instanceof AdminDataLoadedMsg) {
            // After status/server vars are cached, trigger report reload for
            // ReportsPage so its async query is queued for the next tick.
            $newApp = $this->withAdminCachedData($msg->statusVars, $msg->serverVars, $msg->fetchedAt);
            // adminPage may be null if msg arrives before first render (e.g. tests).
            if ($this->adminPage !== null) {
                [$newPage,] = $this->adminPage->update(new ReloadReportMsg());
                $newApp = $newApp->withAdminPage($newPage);
            }
            return [$newApp, null];
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q' && $this->pane !== Pane::Query)
            || ($msg->ctrl && $msg->rune === 'c')) {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Tab) {
            $nextPane = $this->pane->next();
            if ($nextPane === Pane::Admin) {
                // First time entering admin - set loading immediately and trigger fetch
                return [
                    $this->withPane($nextPane)->withAdminLoading(true),
                    Cmd::promise(fn() => $this->createAdminFetchPromise($this->historyRecorder)),
                ];
            }
            return [$this->withPane($nextPane), null];
        }
        if ($this->pane === Pane::Query) {
            return [$this->editQuery($msg), null];
        }
        if ($this->pane === Pane::Tables) {
            return [$this->handleTablesKey($msg), null];
        }
        if ($this->pane === Pane::Admin) {
            return $this->handleAdminKey($msg);
        }
        return [$this->handleRowsKey($msg), null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    private function handleTablesKey(KeyMsg $msg): self
    {
        if ($msg->type === KeyType::Up
            || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            $newApp = $this->withTableCursor($this->tableCursor - 1);
            $name = $newApp->tables[$newApp->tableCursor] ?? null;
            return $name === null ? $newApp : $newApp->loadTable($name);
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            $newApp = $this->withTableCursor($this->tableCursor + 1);
            $name = $newApp->tables[$newApp->tableCursor] ?? null;
            return $name === null ? $newApp : $newApp->loadTable($name);
        }
        if ($msg->type === KeyType::Enter
            || $msg->type === KeyType::Space) {
            $name = $this->tables[$this->tableCursor] ?? null;
            return $name === null ? $this : $this->loadTable($name);
        }
        return $this;
    }

    private function handleRowsKey(KeyMsg $msg): self
    {
        if ($msg->type === KeyType::Up
            || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->mutate(['rowCursor' => max(0, $this->rowCursor - 1)]);
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->mutate([
                'rowCursor' => min(max(0, count($this->rows) - 1), $this->rowCursor + 1),
            ]);
        }
        // For wide query results, h/l + ←/→ scroll the ResultTable's columns.
        if ($this->resultTable !== null) {
            if ($msg->type === KeyType::Left
                || ($msg->type === KeyType::Char && $msg->rune === 'h')) {
                return $this->mutate(['resultTable' => $this->resultTable->scrollLeft()]);
            }
            if ($msg->type === KeyType::Right
                || ($msg->type === KeyType::Char && $msg->rune === 'l')) {
                return $this->mutate(['resultTable' => $this->resultTable->scrollRight()]);
            }
        }
        return $this;
    }

    /**
     * Handle keyboard input while the Admin pane is focused.
     *
     * App-level keys (digits 1-9, q, j/k, p, r) are handled first. All other
     * keys — Tab, Space, 'a', 'w', 's', and any other navigation/editing keys
     * — are delegated to the active page's update() method so pages like
     * VariablesPage and DashboardPage can respond to them. The page returned
     * by update() is stored back in App, preserving any in-memory state
     * (cursor, active tab, pending edits) that survived the refresh cycle.
     *
     * @return array{App, ?Cmd} [updated App, optional command to execute]
     */
    private function handleAdminKey(KeyMsg $msg): array
    {
        $allPanes = AdminPane::orderedCases();
        $count = count($allPanes);

        // Number keys directly select an admin pane (1 = first pane). The range
        // spans every pane. Uses orderedCases() so digit N selects the same
        // pane that appears at row N in the sidebar (section-grouped display
        // order: Management first, then Performance). The isset() guard below
        // ignores digits with no matching pane.
        if ($msg->type === KeyType::Char && $msg->rune >= '1' && $msg->rune <= '9') {
            $index = (int) $msg->rune - 1;
            if (isset($allPanes[$index]) && $allPanes[$index] !== $this->adminPane) {
                $newApp = $this->withAdminCursor($index)->withAdminPane($allPanes[$index])->withAdminLoading(true);
                return [$newApp, Cmd::promise(fn() => $this->createAdminFetchPromise($this->historyRecorder))];
            }
            if (isset($allPanes[$index])) {
                return [$this->withAdminCursor($index), null];
            }
        }
        // [q] returns to Tables pane
        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this->withPane(Pane::Tables), null];
        }
        // j/k or arrows navigate within admin sidebar (with wrap-around)
        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            $newCursor = ($this->adminCursor + 1) % $count;
            $newPane = $allPanes[$newCursor];
            if ($newPane !== $this->adminPane) {
                $newApp = $this->withAdminCursor($newCursor)->withAdminPane($newPane)->withAdminLoading(true);
                return [$newApp, Cmd::promise(fn() => $this->createAdminFetchPromise($this->historyRecorder))];
            }
            return [$this->withAdminCursor($newCursor), null];
        }
        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            $newCursor = ($this->adminCursor - 1 + $count) % $count;
            $newPane = $allPanes[$newCursor];
            if ($newPane !== $this->adminPane) {
                $newApp = $this->withAdminCursor($newCursor)->withAdminPane($newPane)->withAdminLoading(true);
                return [$newApp, Cmd::promise(fn() => $this->createAdminFetchPromise($this->historyRecorder))];
            }
            return [$this->withAdminCursor($newCursor), null];
        }
        // [p] pause/resume polling (sync with DashboardPage if present)
        if ($msg->type === KeyType::Char && $msg->rune === 'p') {
            $newPaused = !$this->paused;
            $page = $this->adminPage();
            if ($page instanceof \SugarCraft\Query\Admin\Dashboard\DashboardPage) {
                $page = $page->withPaused($newPaused);
                return [$this->withPaused($newPaused)->withAdminPage($page), null];
            }
            return [$this->withPaused($newPaused), null];
        }
        // [r] reset — drop cached query results so the next tick re-fetches
        // everything immediately, then delegate to the admin page's update.
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            AdminQueryCache::instance()->forget();
            $page = $this->adminPage();
            [$newPage, ] = $page->update($msg);
            if ($newPage !== $page) {
                return [$this->withAdminPage($newPage), null];
            }
            return [$this, null];
        }

        // Delegate unhandled keys to the active page's update(), following the
        // precedence: app-level keys (digits, q, j/k, p, r) are handled above;
        // page keys (Tab, w, s, etc.) reach the page here. This ensures pages
        // like VariablesPage can respond to Tab/Space/etc. while preserving
        // the returned page state across poll-tick refreshes.
        $page = $this->adminPage();
        [$newPage, $cmd] = $page->update($msg);
        return [$this->withAdminPage($newPage), $cmd];
    }

    private function editQuery(KeyMsg $msg): self
    {
        // candy-query-specific shortcuts are intercepted before the widget
        // sees them (Ctrl+E would otherwise be the TextArea's move-to-line-end).
        if (($msg->ctrl && ($msg->rune === 'r' || $msg->rune === 'e'))
            || ($msg->type === KeyType::Enter && $msg->ctrl)) {
            return $this->runQuery();
        }
        if ($msg->ctrl && !$msg->shift && $msg->rune === 'f') {
            return $this->favoriteQuery();
        }
        if ($msg->ctrl && $msg->shift && $msg->rune === 'f') {
            return $this->unfavoriteQuery();
        }
        // Everything else drives the editor: chars, backspace, space,
        // Enter→newline, and Up/Down move the cursor between lines.
        [$editor, ] = $this->editor()->update($msg);
        return $this->mutate(['queryEditor' => $editor]);
    }

    private function runQuery(): self
    {
        $sql = $this->editor()->value();
        $trimmed = trim($sql);
        if ($trimmed === '') {
            return $this;
        }
        // Record in history (front = newest), de-duping consecutive repeats.
        $history = $this->queryHistory;
        if (($history[0] ?? '') !== $trimmed) {
            array_unshift($history, $trimmed);
        }
        try {
            $rows = $this->db->query($sql);
            return $this->mutate([
                'selectedTable' => '(query)',
                'rows' => $rows,
                'rowCursor' => 0,
                // Query results render through ResultTable (horizontal scroll,
                // JSON pretty, styled NULL). Cleared again on table browse.
                'resultTable' => ResultTable::fromRows($rows),
                'queryEditor' => $this->editor()->reset(),
                'error' => null,
                'status' => count($rows) . ' rows',
                'queryHistory' => $history,
            ]);
        } catch (\PDOException $e) {
            return $this->mutate([
                'error' => $e->getMessage(),
                'status' => null,
                'queryHistory' => $history,
            ]);
        }
    }

    private function loadTable(string $name): self
    {
        try {
            $rows = $this->db->rows($name);
            return $this->mutate([
                'tableCursor' => array_search($name, $this->tables, true) ?: 0,
                'selectedTable' => $name,
                'rows' => $rows,
                'rowCursor' => 0,
                // Browsing a table → the sugar-table grid, not the query viewer.
                'resultTable' => null,
                'error' => null,
                'status' => count($rows) . ' rows',
            ]);
        } catch (\PDOException $e) {
            return $this->mutate([
                'error' => $e->getMessage(),
                'status' => null,
            ]);
        }
    }

    private function withTableCursor(int $i): self
    {
        $size = count($this->tables);
        if ($size === 0) return $this;
        return $this->mutate(['tableCursor' => max(0, min($size - 1, $i))]);
    }

    private function withAdminPane(AdminPane $adminPane): self
    {
        // adminPage reset to null forces lazy recreation against the new pane.
        return $this->mutate(['adminPane' => $adminPane, 'adminPage' => null]);
    }

    private function withAdminCursor(int $adminCursor): self
    {
        $allPanes = AdminPane::orderedCases();
        return $this->mutate([
            'adminCursor' => max(0, min($adminCursor, count($allPanes) - 1)),
        ]);
    }

    private function withPaused(bool $paused): self
    {
        return $this->mutate(['paused' => $paused]);
    }

    private function withAdminPage(PageBase $newPage): self
    {
        return $this->mutate(['adminPage' => $newPage]);
    }

    /**
     * Lazily create the admin page for the currently selected admin pane.
     *
     * Uses ServerContext (MySQL) or PostgresServerContext (PostgreSQL) to
     * instantiate the appropriate PageBase subclass. Cache is invalidated
     * when adminPane changes (via withAdminPane resetting adminPage to null).
     */
    public function adminPage(): PageBase
    {
        if ($this->adminPage !== null) {
            return $this->adminPage;
        }

        $context = $this->serverContext ?? $this->createContext();

        if ($context === null) {
            // Unsupported flavor (e.g., SQLite) - use empty context to avoid render errors
            $context = new EmptyServerContext();
        }

        // Build context with cached data if available
        $context = new CachingServerContext(
            $context,
            $this->adminCachedStatusVars,
            $this->adminCachedServerVars,
            $this->adminLoading,
        );

        return match ($this->adminPane) {
            AdminPane::ProcessList => ConnectionsPage::new($context),
            AdminPane::Dashboard => new DashboardPage($context),
            AdminPane::Variables => self::buildVariablesPage($context),
            AdminPane::Status => ServerStatusPage::new($context),
            AdminPane::QueryStats, AdminPane::TableStats => ReportsPage::new($context, $context->connection()),
            AdminPane::PerfSchema => PerfSchemaPage::new($context),
            AdminPane::Debug => DebugPage::new($context),
        };
    }

    /**
     * Create the appropriate server context based on database flavor.
     * Returns null for unsupported flavors (e.g., SQLite).
     */
    private function createContext(): ?ServerContextInterface
    {
        return match ($this->flavor) {
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => new ServerContext($this->db, $this->flavor),
            Flavor::Postgres => $this->createPostgresContext(),
            default => null,
        };
    }

    /**
     * Create a PostgresServerContext wrapping PostgresAdminProvider.
     */
    private function createPostgresContext(): ServerContextInterface
    {
        $provider = PostgresAdminProvider::new($this->db);
        return PostgresServerContext::new($this->db, $provider);
    }

    /**
     * Build a VariablesPage with its Catalog and VariableEditor collaborators.
     *
     * The catalog is loaded eagerly so loadCategories() and isEditable() work
     * immediately. If loading fails (e.g., missing metadata file), the page
     * still renders — it just shows an empty category tree and [rw] will be
     * absent for all variables.
     */
    private static function buildVariablesPage(ServerContextInterface $context): VariablesPage
    {
        $catalog = Catalog::new();
        try {
            $catalog->load();
        } catch (\Throwable) {
            // Missing metadata is non-fatal — page renders without categories.
        }

        $editor = VariableEditor::new($context, $catalog);

        return VariablesPage::new($context, $catalog, $editor);
    }

    private function withPane(Pane $p): self
    {
        // The editor is focused only while the Query pane is active, so its
        // cursor renders only there (matching the old per-pane cursor mark).
        $editor = $p === Pane::Query ? $this->editor()->focus()[0] : $this->editor()->blur();
        return $this->mutate(['pane' => $p, 'queryEditor' => $editor]);
    }

    /**
     * The SQL editor, lazily defaulted so a freshly constructed App (e.g. in
     * tests) needn't supply one. {@see withPane()} swaps in the focused
     * instance when the Query pane is entered.
     */
    public function editor(): TextArea
    {
        return $this->queryEditor ?? self::newEditor();
    }

    private static function newEditor(): TextArea
    {
        return TextArea::new()->withPlaceholder('-- type SQL, ctrl+r to run --');
    }

    private function favoriteQuery(): self
    {
        $trimmed = trim($this->editor()->value());
        // No-op on an empty editor or an already-saved query.
        if ($trimmed === '' || in_array($trimmed, $this->queryFavorites, true)) {
            return $this;
        }
        $favorites = $this->queryFavorites;
        array_unshift($favorites, $trimmed);
        return $this->mutate(['queryFavorites' => $favorites]);
    }

    private function unfavoriteQuery(): self
    {
        $trimmed = trim($this->editor()->value());
        $favorites = array_values(array_filter(
            $this->queryFavorites,
            fn(string $f) => $f !== $trimmed
        ));
        return $this->mutate(['queryFavorites' => $favorites]);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        if ($this->pane !== Pane::Admin) {
            return null;
        }

        // Skip tick if fetch is already in progress (avoid double-fetch)
        if ($this->adminLoading) {
            return null;
        }

        // Tick at 1s so page-driven queries (process list, sys reports, etc.)
        // discovered during render are drained promptly. The adminLoading gate
        // above prevents overlapping fetches, so a slow report in the batch
        // simply delays the next poll rather than piling up.
        return (new Subscriptions())->withTick('admin-fetch', 1.0, function(): \SugarCraft\Core\Msg {
            return Cmd::batch(
                fn() => new AdminFetchStartedMsg(),
                Cmd::promise(fn() => $this->createAdminFetchPromise($this->historyRecorder)),
            )();
        });
    }

    private function createAdminFetchPromise(?HistoryRecorder $recorder = null): \React\Promise\PromiseInterface
    {
        // Wrap entire async flow in try-catch to ensure we ALWAYS return a promise.
        // This prevents synchronous exceptions from causing unhandled promise rejections.
        try {
            $context = $this->serverContext ?? $this->createContext();
        } catch (\Throwable $e) {
            // Any exception during context creation (not just RuntimeException)
            $msg = $e->getMessage();
            QueryLogger::log('error', 'createContext', 0, $msg);
            return \React\Promise\resolve(new AdminDataLoadedMsg([], [], microtime(true)));
        }

        // Null context means unsupported flavor
        if ($context === null) {
            return \React\Promise\resolve(new AdminDataLoadedMsg([], [], microtime(true)));
        }

        $dsn = $context->connection()->dsn();
        $username = $context->connection()->username() ?? '';
        $password = $context->password();

        $isPostgres = $context instanceof PostgresServerContext;

        // Reuse a single async connection across ticks (lazy connect — avoids
        // opening a fresh TCP/auth connection every poll).
        $cache = AdminQueryCache::instance();
        $connKey = ($isPostgres ? 'pgsql' : 'mysql') . '|' . $dsn . '|' . $username;
        $connection = $cache->connection($connKey, static function () use ($isPostgres, $dsn, $username, $password) {
            return $isPostgres
                ? new ReactPostgresConnection($dsn, $username, $password)
                : new ReactMysqlConnection($dsn, $username, $password);
        });

        if ($isPostgres) {
            $statusQuery = "SELECT datname, numbackends, xact_commit, xact_rollback, blks_read, blks_hit, tup_returned, tup_fetched, tup_inserted, tup_updated, tup_deleted, conflicts, temp_files, temp_bytes, deadlocks FROM pg_stat_database";
            $serverQuery = 'SELECT name, setting FROM pg_settings';
        } else {
            $statusQuery = 'SHOW GLOBAL STATUS';
            $serverQuery = 'SHOW GLOBAL VARIABLES';
        }

        $promises = [
            'status' => $connection->query($statusQuery)
                ->then(function(array $rows): array {
                    $out = [];
                    foreach ($rows as $row) {
                        if (isset($row['Variable_name'], $row['Value'])) {
                            $out[(string)$row['Variable_name']] = (string)$row['Value'];
                        }
                    }
                    return $out;
                }),
            'server' => $connection->query($serverQuery)
                ->then(function(array $rows): array {
                    $out = [];
                    foreach ($rows as $row) {
                        if (isset($row['Variable_name'], $row['Value'])) {
                            $out[(string)$row['Variable_name']] = (string)$row['Value'];
                        } elseif (isset($row['name'], $row['setting'])) {
                            $out[(string)$row['name']] = (string)$row['setting'];
                        }
                    }
                    return $out;
                }),
        ];

        // Drain page-driven queries (process list, replica status, sys reports,
        // availability) requested during the last render. Each is isolated so a
        // single failing query can't sink the batch, and every result — even an
        // empty one — is cached so the next render renders instead of blocking.
        foreach ($cache->takePending() as $sql) {
            $promises[] = $connection->query($sql)->then(
                static function(array $rows) use ($cache, $sql): void {
                    $cache->store($sql, $rows);
                    QueryLogger::log('query', $sql, count($rows));
                },
                static function(\Throwable $e) use ($cache, $sql): void {
                    $cache->store($sql, []);
                    QueryLogger::log('error', $sql, 0, $e->getMessage());
                },
            );
        }

        return \React\Promise\all($promises)->then(function(array $results) use ($recorder): AdminDataLoadedMsg {
            // Record status snapshot to history if recorder is configured
            if ($recorder !== null && isset($results['status'])) {
                $ts = microtime(true);
                $snapshot = new StatusSnapshot($results['status'], $ts);
                $recorder->record($snapshot);
            }

            // Store status and server variables in the shared cache so
            // CachingServerContext can read them even before the App model
            // is updated with the result.
            if (isset($results['status'])) {
                $cache->store('status', $results['status']);
                QueryLogger::log('status', 'SHOW GLOBAL STATUS', count($results['status']));
            }
            if (isset($results['server'])) {
                $cache->store('server', $results['server']);
                QueryLogger::log('server', 'SHOW GLOBAL VARIABLES', count($results['server']));
            }

            return new AdminDataLoadedMsg(
                statusVars: $results['status'] ?? [],
                serverVars: $results['server'] ?? [],
                fetchedAt: microtime(true),
            );
        })->otherwise(function(\Throwable $e): AdminDataLoadedMsg {
            // Defensively wrap QueryLogger in try-catch to prevent logging errors
            // from creating new unhandled rejections
            try {
                QueryLogger::log('error', 'admin fetch', 0, $e->getMessage());
            } catch (\Throwable $logError) {
                // Swallow logging errors to preserve error recovery path
            }
            return new AdminDataLoadedMsg([], [], microtime(true));
        });
    }

    private function withAdminCachedData(array $statusVars, array $serverVars, float $ts): self
    {
        return $this->mutate([
            'adminCachedStatusVars' => $statusVars,
            'adminCachedServerVars' => $serverVars,
            'adminCacheTs' => $ts,
            'adminLoading' => false,
        ]);
    }

    /**
     * Mark admin data fetch as loading or complete.
     *
     * Unlike the old behaviour (which nulled adminPage on every tick), this
     * preserves the existing page instance so that in-memory state — active
     * tab, cursor position, pending edits — survives a poll-tick refresh.
     * The page reads fresh data from the shared AdminQueryCache on the next
     * render. Only withAdminPane() resets adminPage when the pane changes.
     *
     * @see withAdminPane() resets adminPage when the active pane changes.
     */
    private function withAdminLoading(bool $loading): self
    {
        return $this->mutate(['adminLoading' => $loading]);
    }
}
