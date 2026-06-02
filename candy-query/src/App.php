<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\AdminPane;
use SugarCraft\Query\Admin\Connections\ConnectionsPage;
use SugarCraft\Query\Admin\Dashboard\DashboardPage;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\PostgresServerContext;
use SugarCraft\Query\Admin\Reports\ReportsPage;
use SugarCraft\Query\Admin\Providers\PostgresAdminProvider;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Query\Admin\Variables\VariablesPage;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\App\AppBuilder;

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
 * Query history:
 *   - Up/Down arrows in Query pane cycle through history
 *   - Ctrl+F favorites the current query
 *   - Ctrl+Shift+F unfavorites
 */
final class App implements Model
{
    /**
     * @param list<string> $tables
     * @param list<array<string,mixed>> $rows
     * @param list<string> $queryHistory Recently executed queries (newest first)
     * @param list<string> $queryFavorites Saved/favorited queries
     * @param string|null $savedBuf Buffer saved when navigating into history (restored on historyDown from 0)
     */
    public function __construct(
        public readonly DatabaseInterface $db,
        public readonly Flavor $flavor = Flavor::Sqlite,
        public readonly array $tables = [],
        public readonly int $tableCursor = 0,
        public readonly ?string $selectedTable = null,
        public readonly array $rows = [],
        public readonly int $rowCursor = 0,
        public readonly string $queryBuf = '',
        public readonly Pane $pane = Pane::Tables,
        public readonly ?string $error = null,
        public readonly ?string $status = null,
        public readonly array $queryHistory = [],
        public readonly array $queryFavorites = [],
        public readonly int $historyIndex = -1,  // -1 means current buffer, 0 = most recent
        public readonly ?string $savedBuf = null,  // temp storage for current buffer when navigating history
        public readonly AdminPane $adminPane = AdminPane::ProcessList,
        public readonly int $adminCursor = 0,
        public readonly bool $paused = false,
        public readonly ?ServerContextInterface $serverContext = null,
        public readonly ?PageBase $adminPage = null,
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
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q' && $this->pane !== Pane::Query)
            || ($msg->ctrl && $msg->rune === 'c')) {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Tab) {
            return [$this->withPane($this->pane->next()), null];
        }
        if ($this->pane === Pane::Query) {
            return [$this->editQuery($msg), null];
        }
        if ($this->pane === Pane::Tables) {
            return [$this->handleTablesKey($msg), null];
        }
        if ($this->pane === Pane::Admin) {
            return [$this->handleAdminKey($msg), null];
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
            $newCursor = $this->tableCursor - 1;
            $name = $this->tables[$newCursor] ?? null;
            if ($name !== null && $name !== $this->selectedTable) {
                return $this->loadTable($name);
            }
            return $this->withTableCursor($newCursor);
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            $newCursor = $this->tableCursor + 1;
            $name = $this->tables[$newCursor] ?? null;
            if ($name !== null && $name !== $this->selectedTable) {
                return $this->loadTable($name);
            }
            return $this->withTableCursor($newCursor);
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
            return new self(
                db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: max(0, $this->rowCursor - 1),
                queryBuf: $this->queryBuf, pane: $this->pane,
                error: $this->error, status: $this->status,
                queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
                historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
                adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
                serverContext: $this->serverContext, adminPage: $this->adminPage,
            );
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return new self(
                db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: min(max(0, count($this->rows) - 1), $this->rowCursor + 1),
                queryBuf: $this->queryBuf, pane: $this->pane,
                error: $this->error, status: $this->status,
                queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
                historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
                adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
                serverContext: $this->serverContext, adminPage: $this->adminPage,
            );
        }
        return $this;
    }

    private function handleAdminKey(KeyMsg $msg): self
    {
        $allPanes = AdminPane::cases();
        $count = count($allPanes);

        // [1-6] keys directly select an admin pane
        if ($msg->type === KeyType::Char && $msg->rune >= '1' && $msg->rune <= '6') {
            $index = (int) $msg->rune - 1;
            if (isset($allPanes[$index])) {
                return $this->withAdminCursor($index)->withAdminPane($allPanes[$index]);
            }
        }
        // [q] returns to Tables pane
        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return $this->withPane(Pane::Tables);
        }
        // j/k or arrows navigate within admin sidebar (with wrap-around)
        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            $newCursor = ($this->adminCursor + 1) % $count;
            $newPane = $allPanes[$newCursor];
            return $this->withAdminCursor($newCursor)->withAdminPane($newPane);
        }
        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            $newCursor = ($this->adminCursor - 1 + $count) % $count;
            $newPane = $allPanes[$newCursor];
            return $this->withAdminCursor($newCursor)->withAdminPane($newPane);
        }
        // [p] pause/resume polling (sync with DashboardPage if present)
        if ($msg->type === KeyType::Char && $msg->rune === 'p') {
            $newPaused = !$this->paused;
            $page = $this->adminPage();
            if ($page instanceof \SugarCraft\Query\Admin\Dashboard\DashboardPage) {
                $page = $page->withPaused($newPaused);
                return $this->withPaused($newPaused)->withAdminPage($page);
            }
            return $this->withPaused($newPaused);
        }
        // [r] reset — delegate to admin page's update
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            $page = $this->adminPage();
            [$newPage, ] = $page->update($msg);
            if ($newPage !== $page) {
                return $this->withAdminPage($newPage);
            }
            return $this;
        }
        return $this;
    }

    private function editQuery(KeyMsg $msg): self
    {
        // Up arrow: navigate to older query in history
        if ($msg->type === KeyType::Up) {
            return $this->historyUp();
        }
        // Down arrow: navigate to newer query in history
        if ($msg->type === KeyType::Down) {
            return $this->historyDown();
        }
        // Ctrl+F: favorite the current query
        if ($msg->ctrl && !$msg->shift && $msg->rune === 'f') {
            return $this->favoriteQuery();
        }
        // Ctrl+Shift+F: unfavorite the current query
        if ($msg->ctrl && $msg->shift && $msg->rune === 'f') {
            return $this->unfavoriteQuery();
        }
        if (($msg->ctrl && ($msg->rune === 'r' || $msg->rune === 'e'))
            || ($msg->type === KeyType::Enter && $msg->ctrl)) {
            return $this->runQuery();
        }
        if ($msg->type === KeyType::Backspace) {
            return $this->withQueryBuf(self::dropLast($this->queryBuf));
        }
        if ($msg->type === KeyType::Enter) {
            return $this->withQueryBuf($this->queryBuf . "\n");
        }
        if ($msg->type === KeyType::Space) {
            return $this->withQueryBuf($this->queryBuf . ' ');
        }
        if ($msg->type === KeyType::Char && !$msg->ctrl) {
            return $this->withQueryBuf($this->queryBuf . $msg->rune);
        }
        return $this;
    }

    private function runQuery(): self
    {
        $trimmed = trim($this->queryBuf);
        if ($trimmed === '') {
            return $this;
        }
        // Add to history (front = newest), reset historyIndex, clear buffer
        $history = $this->queryHistory;
        if (($history[0] ?? '') !== $trimmed) {
            array_unshift($history, $trimmed);
        }
        $newHistoryIndex = -1;
        try {
            $rows = $this->db->query($this->queryBuf);
            return new self(
                db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: '(query)', rows: $rows, rowCursor: 0,
                queryBuf: '', pane: $this->pane,
                error: null, status: count($rows) . ' rows',
                queryHistory: $history, queryFavorites: $this->queryFavorites,
                historyIndex: $newHistoryIndex, savedBuf: $this->savedBuf,
                adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
                serverContext: $this->serverContext, adminPage: $this->adminPage,
            );
        } catch (\PDOException $e) {
            return new self(
                db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: $this->rowCursor, queryBuf: $this->queryBuf,
                pane: $this->pane,
                error: $e->getMessage(), status: null,
                queryHistory: $history, queryFavorites: $this->queryFavorites,
                historyIndex: $newHistoryIndex, savedBuf: $this->savedBuf,
                adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
                serverContext: $this->serverContext, adminPage: $this->adminPage,
            );
        }
    }

    private function loadTable(string $name): self
    {
        try {
            $rows = $this->db->rows($name);
            return new self(
                db: $this->db, flavor: $this->flavor, tables: $this->tables,
                tableCursor: array_search($name, $this->tables, true) ?: 0,
                selectedTable: $name, rows: $rows, rowCursor: 0,
                queryBuf: $this->queryBuf, pane: $this->pane,
                error: null, status: count($rows) . ' rows',
                queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
                historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
                adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
                serverContext: $this->serverContext, adminPage: $this->adminPage,
            );
        } catch (\PDOException $e) {
            return new self(
                db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: $this->rowCursor, queryBuf: $this->queryBuf,
                pane: $this->pane,
                error: $e->getMessage(), status: null,
                queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
                historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
                adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
                serverContext: $this->serverContext, adminPage: $this->adminPage,
            );
        }
    }

    private function withTableCursor(int $i): self
    {
        $size = count($this->tables);
        if ($size === 0) return $this;
        $i = max(0, min($size - 1, $i));
        return new self(
            db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $i,
            selectedTable: $this->selectedTable, rows: $this->rows,
            rowCursor: $this->rowCursor, queryBuf: $this->queryBuf,
            pane: $this->pane, error: $this->error, status: $this->status,
            queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
            historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
            adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
            serverContext: $this->serverContext, adminPage: $this->adminPage,
        );
    }

    private function withAdminPane(AdminPane $adminPane): self
    {
        return new self(
            db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows, rowCursor: $this->rowCursor,
            queryBuf: $this->queryBuf, pane: $this->pane,
            error: $this->error, status: $this->status,
            queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
            historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
            adminPane: $adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
            serverContext: $this->serverContext, adminPage: null,  // reset adminPage to force recreation
        );
    }

    private function withAdminCursor(int $adminCursor): self
    {
        $allPanes = AdminPane::cases();
        $adminCursor = max(0, min($adminCursor, count($allPanes) - 1));
        return new self(
            db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows, rowCursor: $this->rowCursor,
            queryBuf: $this->queryBuf, pane: $this->pane,
            error: $this->error, status: $this->status,
            queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
            historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
            adminPane: $this->adminPane, adminCursor: $adminCursor, paused: $this->paused,
            serverContext: $this->serverContext, adminPage: $this->adminPage,
        );
    }

    private function withPaused(bool $paused): self
    {
        return new self(
            db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows, rowCursor: $this->rowCursor,
            queryBuf: $this->queryBuf, pane: $this->pane,
            error: $this->error, status: $this->status,
            queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
            historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
            adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $paused,
            serverContext: $this->serverContext, adminPage: $this->adminPage,
        );
    }

    private function withAdminPage(PageBase $newPage): self
    {
        return new self(
            db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows, rowCursor: $this->rowCursor,
            queryBuf: $this->queryBuf, pane: $this->pane,
            error: $this->error, status: $this->status,
            queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
            historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
            adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
            serverContext: $this->serverContext, adminPage: $newPage,
        );
    }

    /**
     * Lazily create the admin page for the currently selected admin pane.
     *
     * Uses ServerContext (MySQL) or PostgresServerContext (PostgreSQL) to
     * instantiate the appropriate PageBase subclass. Cache is invalidated
     * when adminPane changes (via withAdminPane resetting adminPage to null).
     *
     * Note: ProcessList and ConnStats currently use DashboardPage as placeholder
     * since ConnectionsPage does not extend PageBase. Full ConnectionsPage
     * integration will come in a later phase.
     */
    public function adminPage(): PageBase
    {
        if ($this->adminPage !== null) {
            return $this->adminPage;
        }

        $context = $this->serverContext ?? $this->createContext();

        return match ($this->adminPane) {
            // TODO: Use ConnectionsPage once it extends PageBase
            AdminPane::ProcessList, AdminPane::ConnStats => new DashboardPage($context),
            AdminPane::Variables => VariablesPage::new($context),
            AdminPane::Status => ServerStatusPage::new($context),
            AdminPane::QueryStats, AdminPane::TableStats => ReportsPage::new($context),
        };
    }

    /**
     * Create the appropriate server context based on database flavor.
     */
    private function createContext(): ServerContextInterface
    {
        return match ($this->flavor) {
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => new ServerContext($this->db, $this->flavor),
            Flavor::Postgres => $this->createPostgresContext(),
            default => throw new \RuntimeException('Admin not supported for ' . $this->flavor->value),
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

    private function withPane(Pane $p): self
    {
        return new self(
            db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows,
            rowCursor: $this->rowCursor, queryBuf: $this->queryBuf,
            pane: $p, error: $this->error, status: $this->status,
            queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
            historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
            adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
            serverContext: $this->serverContext, adminPage: $this->adminPage,
        );
    }

    private function withQueryBuf(string $buf): self
    {
        return new self(
            db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows,
            rowCursor: $this->rowCursor, queryBuf: $buf,
            pane: $this->pane, error: $this->error, status: $this->status,
            queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
            historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
            adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
            serverContext: $this->serverContext, adminPage: $this->adminPage,
        );
    }

    private function historyUp(): self
    {
        // If history is empty, no-op
        if ($this->queryHistory === []) {
            return $this;
        }
        $historySize = count($this->queryHistory);
        // If historyIndex is -1 (at current buffer), save current buffer and go to older (index 1 if exists)
        if ($this->historyIndex === -1) {
            if ($historySize >= 2) {
                return new self(
                    db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
                    selectedTable: $this->selectedTable, rows: $this->rows,
                    rowCursor: $this->rowCursor,
                    queryBuf: $this->queryHistory[1],
                    pane: $this->pane, error: $this->error, status: $this->status,
                    queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
                    historyIndex: 1,
                    savedBuf: $this->queryBuf,
                    adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
                    serverContext: $this->serverContext, adminPage: $this->adminPage,
                );
            }
            // Only one item, go to index 0
            return new self(
                db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: $this->rowCursor,
                queryBuf: $this->queryHistory[0],
                pane: $this->pane, error: $this->error, status: $this->status,
                queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
                historyIndex: 0,
                savedBuf: $this->queryBuf,
                adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
                serverContext: $this->serverContext, adminPage: $this->adminPage,
            );
        }
        // If historyIndex > 0, decrement index (going toward older)
        if ($this->historyIndex > 0) {
            $newIndex = $this->historyIndex - 1;
            return new self(
                db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: $this->rowCursor,
                queryBuf: $this->queryHistory[$newIndex],
                pane: $this->pane, error: $this->error, status: $this->status,
                queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
                historyIndex: $newIndex,
                savedBuf: $this->savedBuf,
                adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
                serverContext: $this->serverContext, adminPage: $this->adminPage,
            );
        }
        return $this;
    }

    private function historyDown(): self
    {
        // If historyIndex is -1 (at current buffer), no-op
        if ($this->historyIndex === -1) {
            return $this;
        }
        // If historyIndex > 0, decrement index (going toward newer/most recent)
        if ($this->historyIndex > 0) {
            $newIndex = $this->historyIndex - 1;
            return new self(
                db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: $this->rowCursor,
                queryBuf: $this->queryHistory[$newIndex],
                pane: $this->pane, error: $this->error, status: $this->status,
                queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
                historyIndex: $newIndex,
                savedBuf: $this->savedBuf,
                adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
                serverContext: $this->serverContext, adminPage: $this->adminPage,
            );
        }
        // If at index 0 (newest), go back to -1 and restore savedBuf
        return new self(
            db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows,
            rowCursor: $this->rowCursor,
            queryBuf: $this->savedBuf ?? '',
            pane: $this->pane, error: $this->error, status: $this->status,
            queryHistory: $this->queryHistory, queryFavorites: $this->queryFavorites,
            historyIndex: -1,
            savedBuf: null,
            adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
            serverContext: $this->serverContext, adminPage: $this->adminPage,
        );
    }

    private function favoriteQuery(): self
    {
        $trimmed = trim($this->queryBuf);
        // If queryBuf is empty or already in favorites, return $this
        if ($trimmed === '' || in_array($trimmed, $this->queryFavorites, true)) {
            return $this;
        }
        $favorites = $this->queryFavorites;
        array_unshift($favorites, $trimmed);
        return new self(
            db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows,
            rowCursor: $this->rowCursor, queryBuf: $this->queryBuf,
            pane: $this->pane, error: $this->error, status: $this->status,
            queryHistory: $this->queryHistory, queryFavorites: $favorites,
            historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
            adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
            serverContext: $this->serverContext, adminPage: $this->adminPage,
        );
    }

    private function unfavoriteQuery(): self
    {
        $trimmed = trim($this->queryBuf);
        $favorites = array_values(array_filter(
            $this->queryFavorites,
            fn(string $f) => $f !== $trimmed
        ));
        return new self(
            db: $this->db, flavor: $this->flavor, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows,
            rowCursor: $this->rowCursor, queryBuf: $this->queryBuf,
            pane: $this->pane, error: $this->error, status: $this->status,
            queryHistory: $this->queryHistory, queryFavorites: $favorites,
            historyIndex: $this->historyIndex, savedBuf: $this->savedBuf,
            adminPane: $this->adminPane, adminCursor: $this->adminCursor, paused: $this->paused,
            serverContext: $this->serverContext, adminPage: $this->adminPage,
        );
    }

    private static function dropLast(string $s): string
    {
        if ($s === '') return $s;
        $i = strlen($s) - 1;
        while ($i > 0 && (ord($s[$i]) & 0xc0) === 0x80) {
            $i--;
        }
        return substr($s, 0, $i);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
