<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\ExceptionMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\Admin\AdminPane;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\CacheTtl;
use SugarCraft\Sql\Admin\AsyncCachingServerContext;
use SugarCraft\Sql\Admin\EmptyServerContext;
use SugarCraft\Sql\Admin\Connections\ConnectionsPage;
use SugarCraft\Sql\Admin\Dashboard\DashboardPage;
use SugarCraft\Sql\Admin\Debug\DebugPage;
use SugarCraft\Sql\Admin\PageBase;
use SugarCraft\Sql\Admin\PostgresServerContext;
use SugarCraft\Sql\Admin\ReactMysqlConnection;
use SugarCraft\Sql\Admin\ReactPostgresConnection;
use SugarCraft\Sql\Admin\Reports\ReportsPage;
use SugarCraft\Sql\Admin\RowNavigable;
use SugarCraft\Sql\Admin\Providers\PostgresAdminProvider;
use SugarCraft\Sql\Admin\PerfSchema\EasySetupDetector;
use SugarCraft\Sql\Admin\PerfSchema\PerfSchemaPage;
use SugarCraft\Sql\Admin\Sampler;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Sql\Admin\ServerStatusSnapshotAdapter;
use SugarCraft\Sql\Admin\Resilience\StatementTimeout;
use SugarCraft\Sql\Admin\Resilience\StatementTimeoutException;
use SugarCraft\Sql\Admin\Variables\Catalog;
use SugarCraft\Sql\Admin\Variables\VariableEditor;
use SugarCraft\Sql\Admin\Variables\VariablesPage;
use SugarCraft\Style\Style;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\MysqlDatabase;
use SugarCraft\Sql\Db\PreviewQuery;
use SugarCraft\Sql\Db\Export\CsvExporter;
use SugarCraft\Sql\Db\ReconnectException;
use SugarCraft\Sql\App\AdminState;
use SugarCraft\Sql\App\AppBuilder;
use SugarCraft\Sql\App\BrowseState;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\App\ExportState;
use SugarCraft\Sql\App\QueryState;
use SugarCraft\Sql\App\UiState;
use SugarCraft\Sql\Admin\History\HistoryRecorder;
use SugarCraft\Sql\Admin\QueryLogger;
use SugarCraft\Sql\Admin\StatusSnapshot;
use SugarCraft\Sql\Core\Msg\AdminDataLoadedMsg;
use SugarCraft\Sql\Core\Msg\AdminDrainCompletedMsg;
use SugarCraft\Sql\Core\Msg\AdminFetchStartedMsg;
use SugarCraft\Sql\Core\Msg\AdminTickMsg;
use SugarCraft\Sql\Core\Msg\QueryRowsLoadedMsg;
use SugarCraft\Sql\Core\Msg\ReloadReportMsg;
use SugarCraft\Sql\Core\Msg\TableRowsLoadedMsg;

/**
 * SQL client as a SugarCraft Model - speaks SQLite, MySQL, MariaDB and
 * PostgreSQL through the PDO drivers, with Workbench-style admin panels.
 * Four focus panes:
 *
 *   - Tables: a list of tables. `/` opens a live name filter; Enter loads
 *             the selected table's rows into the rows pane (movement is
 *             local — SQL-O10 retired the per-cursor preview fetch); the
 *             rows pane's title updates accordingly.
 *   - Rows:   a paginated peek at the selected table's contents.
 *   - Query:  an editor — type SQL, Ctrl+Enter to run. Errors land
 *             on a status line; the rowset replaces the rows pane.
 *
 *   - Admin:  server-introspection dashboard pages (process list,
 *             variables, status, query stats, dashboard, table stats,
 *             Performance Schema, debug). Needs a MySQL or PostgreSQL
 *             server; on SQLite the server context is empty.
 *
 * Tab cycles focus; j/k or arrows move the cursor in list panes;
 * `q` quits from the Tables and Rows panes and returns to Tables
 * from the Admin pane (App::handleKey routing).
 * File lines (SQL-O07): `Ctrl+S`/`x` export the live result to a CSV path,
 * `i` imports one into a named table — the open line owns the keyboard,
 * Enter is the only submit key, and an existing destination arms a
 * refuse-overwrite confirm before anything is replaced.
 *
 * Query editor:
 *   - The Query pane is backed by a sugar-forms {@see TextArea} — it owns
 *     the cursor and UTF-8 editing. Up/Down move the cursor inside a draft;
 *     in an empty editor they instead recall executed statements from
 *     {@see QueryState::$history} (SQL-O01a walk, status-line readout).
 *   - Ctrl+R / Ctrl+E run the query; Ctrl+F favorites it; Ctrl+Shift+F
 *     unfavorites. Executed queries are recorded in {@see QueryState::$history}.
 *
 * Model state is grouped into cohesive value objects (plan 3.3) instead of a
 * ~29-parameter constructor: {@see ConnectionState} (db/flavor/context),
 * {@see BrowseState} (tables + row preview), {@see QueryState} (editor +
 * history), {@see UiState} (focus + status line), {@see AdminState}
 * (admin pane, caches, polling bookkeeping).
 */
final class App implements Model
{
    use Mutable;

    public function __construct(
        public readonly ConnectionState $connection,
        public readonly BrowseState $browse = new BrowseState(),
        public readonly QueryState $query = new QueryState(),
        public readonly UiState $ui = new UiState(),
        public readonly AdminState $admin = new AdminState(),
        public readonly ExportState $io = new ExportState(),
    ) {}

    /**
     * Shared wall-clock cap for the synchronous remote legs (SQL-C11).
     *
     * Transport policy, not user state. The TLS-refused run/browse legs and
     * the boot table list execute on the UI loop, and before this seam a
     * stalled server froze the app indefinitely; now each leg fails with a
     * named StatementTimeoutException inside
     * {@see StatementTimeout::DEFAULT_SECONDS} (or, without pcntl, at the
     * driver's network timeout — see {@see MysqlDatabase::NETWORK_TIMEOUT_SECONDS}).
     * AdminState carries an injected instance as the test seam.
     */
    private function syncTimeout(): StatementTimeout
    {
        return $this->admin->syncTimeoutGuard ?? new StatementTimeout();
    }

    /**
     * @param Flavor::* $flavor Database flavor for driver-specific behavior
     * @param HistoryRecorder|null $historyRecorder When set, admin polls append
     *     snapshots to the history store (wired by bin/candy-sql; the matching
     *     fluent path is AppBuilder::withHistoryDbPath()).
     * @param string|null $sslMode MySQL ssl-mode carried into ConnectionState
     *     for the async-transport TLS gate ({@see App::asyncTransportRefusedByTls()}).
     *     Null (production) derives it from a real MysqlDatabase handle; any
     *     other connection carries ''. An explicit string is the test seam —
     *     MysqlDatabase is final with a private constructor, so doubles cannot
     *     report a mode on their own.
     */
    public static function start(
        DatabaseInterface $db,
        Flavor $flavor = Flavor::Sqlite,
        ?HistoryRecorder $historyRecorder = null,
        ?string $sslMode = null,
    ): self {
        $sslMode ??= ($db instanceof MysqlDatabase ? $db->sslMode() : '');
        // SQL-C11: bootstrap reads the table list on the UI loop too — the
        // first screen must inherit the same stall bound as every later leg.
        $tables = (new StatementTimeout())->run(
            static fn (): array => $db->tables(),
        );
        $a = new self(
            ConnectionState::new($db, $flavor, null, $sslMode),
            BrowseState::new()->withTables($tables),
            admin: new AdminState(historyRecorder: $historyRecorder),
        );
        if ($tables !== []) {
            $a = $a->loadTable($tables[0]);
        }
        if ($a->asyncTransportRefusedByTls()) {
            // Standing status line so the disabled refresh is visible in-app,
            // not just in the launcher's one-time stderr notice.
            $a = $a->mutate([
                'ui' => $a->ui->withStatus('admin live-refresh disabled — TLS-verified ssl-mode (sync transport only)'),
            ]);
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
            // client-027: the throttle stamp belongs to DISPATCH, not to the
            // !loading edge it used to sit behind. Any start dispatched while
            // a fetch was already in flight (Admin entry's batch arms included)
            // left lastFetchAt stale, so the next due tick re-pulled the full
            // STATUS/VARIABLES pair that very window had already paid for.
            return [$this->mutate([
                'admin' => $this->admin->withLoading(true)->withLastFetchAt(microtime(true)),
            ]), null];
        }
        if ($msg instanceof AdminDataLoadedMsg) {
            // After status/server vars are cached, trigger report reload for
            // ReportsPage so its async query is queued for the next tick.
            $newApp = $this->withAdminCachedData($msg->statusVars, $msg->serverVars, $msg->fetchedAt);
            // Pin the lazily-built page into model state the moment data
            // arrives, not merely when a keypress happened to delegate first:
            // adminPage() never persisted its build, so an un-touched pane
            // minted a throwaway page per render — rolling-window widgets
            // (Server Status graphs, Sampler rates) could never accumulate.
            $page = $newApp->admin->page ?? $newApp->adminPage();
            try {
                [$newPage,] = $page->update(new ReloadReportMsg());
                assert($newPage instanceof PageBase);
                $newApp = $newApp->withAdminPage($newPage);
            } catch (\Throwable) {
                // SQL-C01 fold seam: a page-level reload failure must never
                // kill the TUI — drop the reload, keep the arrived data.
            }
            return [$newApp, null];
        }
        if ($msg instanceof TableRowsLoadedMsg) {
            // An async browse fetch finished — fold its rows (or error) in.
            return $this->applyLoadedRows($msg);
        }
        if ($msg instanceof QueryRowsLoadedMsg) {
            // An async query finished — fold its rows (or error) into the result table.
            return [$this->applyQueryRows($msg), null];
        }
        if ($msg instanceof AdminTickMsg) {
            return $this->handleAdminTick();
        }
        if ($msg instanceof AdminDrainCompletedMsg) {
            // Page-driven rows landed in the cache during the status cooldown.
            // Only the page is told to re-read (memo invalidation) — never
            // AdminDataLoadedMsg, whose empty arrays would wipe the cached vars.
            if ($this->admin->page !== null) {
                try {
                    [$newPage,] = $this->admin->page->update(new ReloadReportMsg());
                    assert($newPage instanceof PageBase);
                    return [$this->withAdminPage($newPage), null];
                } catch (\Throwable) {
                    // SQL-C01 fold seam: see AdminDataLoadedMsg above.
                    return [$this, null];
                }
            }
            return [$this, null];
        }
        if ($msg instanceof PasteMsg) {
            // D-M13: bracketed paste (enabled by bin) arrives as a PasteMsg,
            // which the sugar-forms TextArea already understands — it inserts
            // the text at the caret (replacing a selection) instead of letting
            // embedded ESC bytes decode as keys. Route it to the editor the
            // same way ordinary character keys reach it below.
            [$editor,] = $this->editor()->update($msg);
            assert($editor instanceof TextArea);
            return [$this->mutate(['query' => $this->query->withEditor($editor)]), null];
        }
        if ($msg instanceof ColorProfileMsg) {
            // D-M14: sugar-core's ColorProfile::detect is the fleet colour
            // authority (NO_COLOR, CLICOLOR=0, TERM=dumb, COLORTERM, pipes).
            // The Program dispatches this once at startup; honouring it pins
            // the process-ambient profile so every Style::new() minted
            // afterwards renders within the terminal's real capability.
            Style::setDefaultProfile($msg->profile);
            return [$this, null];
        }
        if ($msg instanceof ExceptionMsg) {
            // client-026: sugar-core's Program surfaces every command-handler
            // failure here (rejected promises AND factory throws). The arm was
            // absent, so the failure fell into the non-KeyMsg silence — the
            // admin tick then retried on its 1 s cadence with zero tell. Fold
            // the named cause to the error line; the TUI survives the fault.
            try {
                QueryLogger::log('error', 'command', 0, $msg->exception->getMessage());
            } catch (\Throwable) {
                // Logging must never turn a surfaced fault back into silence-loss.
            }
            return [$this->mutate([
                'ui' => $this->ui->withError('Async command failed: ' . $msg->exception->getMessage()),
            ]), null];
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        // D-C5: the quit keys must not hijack the layers below them.
        // Ctrl+C is the unconditional escape hatch. Escape/q quit only from
        // the browser panes — in the Query pane Escape must not silently
        // discard a drafted query, and on the Admin pane a modal page
        // (variable editor dialog, GTID dialog, kill confirmation) owns
        // every key while open, while a plain q returns to Tables via
        // handleAdminKey() (previously dead code behind this guard).
        if ($msg->ctrl && $msg->rune === 'c') {
            return [$this, Cmd::quit()];
        }
        if ($this->ui->pane === Pane::Admin) {
            $page = $this->admin->page;
            if ($page !== null && ($msg->type === KeyType::Escape || $page->isModal())) {
                [$newPage, $cmd] = $page->update($msg);
                assert($newPage instanceof PageBase);
                return [$this->withAdminPage($newPage), $cmd];
            }
        }
        if (($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q'))
            && ($this->ui->pane === Pane::Tables || $this->ui->pane === Pane::Rows)
            // SQL-O10: while the `/` line is open it owns the keyboard —
            // Escape abandons the line, and 'q' is just a filter letter.
            && !($this->ui->pane === Pane::Tables && $this->browse->filterMode)
            // SQL-O07: same law for an open export/import file line.
            && !$this->io->isPromptOpen()) {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Tab) {
            $nextPane = $this->ui->pane->next();
            if ($nextPane === Pane::Admin) {
                // client-027: enter through the same batch the tick uses — its
                // first Msg (AdminFetchStartedMsg) now folds the loading flag
                // AND stamps lastFetchAt at dispatch. The old direct
                // withAdminLoading(true) set the flag but never stamped, so the
                // first due tick re-pulled the full STATUS/VARIABLES pair the
                // entry fetch had just paid for (duplicate pull every entry).
                return [
                    $this->withPane($nextPane),
                    Cmd::batch(
                        static fn (): Msg => new AdminFetchStartedMsg(),
                        Cmd::promise(fn () => $this->createAdminFetchPromise($this->admin->historyRecorder)),
                    ),
                ];
            }
            return [$this->withPane($nextPane), null];
        }
        // SQL-O07: while a file line is open it owns the keyboard, whichever
        // pane raised it — printable runes build the path, Enter advances.
        if ($this->io->isPromptOpen()) {
            return $this->handleIoKey($msg);
        }
        // Openers: Ctrl+S works from any pane that can hold a result; the
        // letter chords x (export) and i (import) are browser-pane-only so
        // the Query editor keeps typing those letters. Export needs live
        // rows; import starts from a path, not from a result.
        if ($msg->ctrl && $msg->type === KeyType::Char && $msg->rune === 's' && $this->browse->rows !== []) {
            return $this->openIoLine(ExportState::STAGE_EXPORT_PATH);
        }
        if (!$msg->ctrl
            && $msg->type === KeyType::Char
            // SQL-O10 law: an open '/' filter line owns printable letters —
            // 'i'/'x' mid-filter are filter runes, not opener chords.
            && !$this->browse->filterMode
            && ($this->ui->pane === Pane::Tables || $this->ui->pane === Pane::Rows)) {
            if ($msg->rune === 'x' && $this->browse->rows !== []) {
                return $this->openIoLine(ExportState::STAGE_EXPORT_PATH);
            }
            if ($msg->rune === 'i') {
                return $this->openIoLine(ExportState::STAGE_IMPORT_PATH);
            }
        }
        // SQL-O11: PgDn/PgUp page the TABLE PREVIEW (the browse window), in
        // either browser pane. A query-pane result is a full buffer, not a
        // preview — the sentinel selection '(query)' marks exactly that case
        // and the keys stay inert for it.
        if (($msg->type === KeyType::PageDown || $msg->type === KeyType::PageUp)
            && ($this->ui->pane === Pane::Tables || $this->ui->pane === Pane::Rows)
            && $this->browse->selectedTable !== null
            && $this->browse->selectedTable !== '(query)'
            && !$this->browse->rowsLoading
            && !$this->browse->filterMode) {
            return $this->beginPageShift($msg->type === KeyType::PageDown);
        }
        if ($this->ui->pane === Pane::Query) {
            if (($msg->ctrl && ($msg->rune === 'r' || $msg->rune === 'e'))
                || ($msg->type === KeyType::Enter && $msg->ctrl)) {
                return $this->beginRunQuery();
            }
            // SQL-O04 (v1, keyword-only per owner ruling 3a): Ctrl+Space
            // completes the word at the caret against the SQL keyword
            // roster. The chord rides the modern terminal's ctrl-decoded
            // Space (kitty protocol); legacy terminals that report
            // Ctrl+Space as a bare space simply keep typing — the key is
            // inert there, never a wrong insert.
            if ($msg->type === KeyType::Space && $msg->ctrl) {
                return $this->completeKeyword();
            }
            return [$this->editQuery($msg), null];
        }
        if ($this->ui->pane === Pane::Tables) {
            return $this->handleTablesKey($msg);
        }
        if ($this->ui->pane === Pane::Admin) {
            return $this->handleAdminKey($msg);
        }
        return [$this->handleRowsKey($msg), null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    /**
     * Tables-pane navigation. SQL-O10: cursor movement is LOCAL-only — the
     * old arm round-tripped a row-preview fetch for every hovered table,
     * which auto-floated every table on ERP-sized schemas (one server trip
     * per arrow press). Only Enter/Space loads the highlighted table.
     * `/` opens a live type-to-filter line over the names (jorgerojas26/lazysql parity).
     *
     * @return array{self, ?Cmd}
     */
    private function handleTablesKey(KeyMsg $msg): array
    {
        if ($this->browse->filterMode) {
            return $this->handleTablesFilterKey($msg);
        }
        if ($msg->type === KeyType::Char && $msg->rune === '/') {
            return [$this->mutate(['browse' => $this->browse->withFilterMode(true)]), null];
        }
        if ($msg->type === KeyType::Up
            || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return [$this->withTableCursor($this->browse->tableCursor - 1), null];
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return [$this->withTableCursor($this->browse->tableCursor + 1), null];
        }
        if ($msg->type === KeyType::Enter
            || $msg->type === KeyType::Space) {
            $name = $this->browse->visibleTables()[$this->browse->tableCursor] ?? null;
            return $name === null ? [$this, null] : $this->beginLoadTable($name);
        }
        return [$this, null];
    }

    /**
     * Key handling while the `/` filter line is open. Printable runes extend
     * the filter (the line owns k/j too — it is a text field), Backspace
     * deletes one codepoint, arrows move the cursor through the filtered
     * view, Enter commits and loads the highlighted table (the pane's
     * load-on-Enter law holding through the filter), Escape abandons the
     * line and clears the filter without quitting the app.
     *
     * @return array{self, ?Cmd}
     */
    private function handleTablesFilterKey(KeyMsg $msg): array
    {
        $browse = $this->browse;
        if ($msg->type === KeyType::Escape) {
            return [$this->mutate([
                'browse' => $browse->withFilterMode(false)->withFilterText(''),
            ]), null];
        }
        if ($msg->type === KeyType::Backspace) {
            if ($browse->filterText === '') {
                return [$this, null];
            }
            return [$this->filteredTo(
                mb_substr($browse->filterText, 0, mb_strlen($browse->filterText) - 1),
            ), null];
        }
        if ($msg->type === KeyType::Enter || $msg->type === KeyType::Space) {
            $closed = $this->mutate(['browse' => $browse->withFilterMode(false)]);
            $name = $closed->browse->visibleTables()[$closed->browse->tableCursor] ?? null;
            return $name === null ? [$closed, null] : $closed->beginLoadTable($name);
        }
        if ($msg->type === KeyType::Up) {
            return [$this->withTableCursor($browse->tableCursor - 1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->withTableCursor($browse->tableCursor + 1), null];
        }
        if ($msg->type === KeyType::Char && !$msg->ctrl && $msg->rune !== '') {
            return [$this->filteredTo($browse->filterText . $msg->rune), null];
        }
        return [$this, null];
    }

    /**
     * Replace the filter text and pin the cursor to the top of the newly
     * narrowed list — keeping a stale index would point at a different row
     * than the user just typed toward.
     */
    private function filteredTo(string $filterText): self
    {
        return $this->mutate([
            'browse' => $this->browse
                ->withFilterText($filterText)
                ->withTableCursor(0),
        ]);
    }

    /**
     * Raise a file line (SQL-O07). The hint lands on the status line but the
     * prompt renderer owns the visible text while the line is open.
     *
     * @return array{self, ?Cmd}
     */
    private function openIoLine(string $stage): array
    {
        return [$this->mutate([
            'io' => ExportState::new()->withStage($stage),
            'ui' => $this->ui->withError(null)->withStatus(null),
        ]), null];
    }

    /**
     * Key handling while an export/import line is open. Enter advances the
     * stage machine (it is the ONLY submit key — spaces are reserved for
     * future path input, so no letter or the space bar can fire a write);
     * Escape closes the line without quitting; printable runes extend the
     * buffer, and on the refuse-overwrite arm any edit disarms back to
     * plain path input.
     *
     * @return array{self, ?Cmd}
     */
    private function handleIoKey(KeyMsg $msg): array
    {
        $io = $this->io;
        if ($msg->type === KeyType::Escape) {
            return [$this->mutate([
                'io' => $io->closed(),
                'ui' => $this->ui->withStatus('file line cancelled'),
            ]), null];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->ioAdvance();
        }
        if ($msg->type === KeyType::Backspace) {
            if ($io->stage === ExportState::STAGE_EXPORT_OVERWRITE) {
                return [$this->mutate(['io' => $io->withStage(ExportState::STAGE_EXPORT_PATH)]), null];
            }
            if ($io->buffer === '') {
                return [$this, null];
            }

            return [$this->mutate(['io' => $io->withBuffer(mb_substr($io->buffer, 0, mb_strlen($io->buffer) - 1))]), null];
        }
        if ($msg->type === KeyType::Char && !$msg->ctrl && $msg->rune !== '') {
            if ($io->stage === ExportState::STAGE_EXPORT_OVERWRITE) {
                // Any edit disarms the overwrite confirm, char included.
                return [$this->mutate(['io' => $io
                    ->withStage(ExportState::STAGE_EXPORT_PATH)
                    ->withBuffer($io->buffer . $msg->rune),
                ]), null];
            }

            return [$this->mutate(['io' => $io->withBuffer($io->buffer . $msg->rune)]), null];
        }

        return [$this, null];
    }

    /**
     * Enter on a file line: the stage machine.
     *
     * @return array{self, ?Cmd}
     */
    private function ioAdvance(): array
    {
        $io = $this->io;
        if ($io->stage === ExportState::STAGE_EXPORT_PATH) {
            if ($io->buffer === '') {
                return [$this->mutate(['ui' => $this->ui->withStatus('a path is required — esc cancels')]), null];
            }
            if (is_file($io->buffer)) {
                return [$this->mutate([
                    'io' => $io->withStage(ExportState::STAGE_EXPORT_OVERWRITE),
                    'ui' => $this->ui->withStatus(null),
                ]), null];
            }

            return $this->doExport($io->buffer);
        }
        if ($io->stage === ExportState::STAGE_EXPORT_OVERWRITE) {
            return $this->doExport($io->buffer);
        }
        if ($io->stage === ExportState::STAGE_IMPORT_PATH) {
            // The picker is file-gated: advance only onto something real,
            // so a typo cannot silently name an unreadable source.
            if ($io->buffer === '' || !is_file($io->buffer)) {
                return [$this->mutate(['ui' => $this->ui->withStatus('no such file — keep typing or esc cancels')]), null];
            }

            return [$this->mutate([
                'io' => $io->withStage(ExportState::STAGE_IMPORT_TABLE)->withPath($io->buffer)->withBuffer(''),
                'ui' => $this->ui->withStatus(null),
            ]), null];
        }
        if ($io->stage === ExportState::STAGE_IMPORT_TABLE) {
            if ($io->buffer === '') {
                return [$this->mutate(['ui' => $this->ui->withStatus('name the target table — esc cancels')]), null];
            }

            return $this->doImport($io->path, $io->buffer);
        }

        return [$this, null];
    }

    /**
     * @return array{self, ?Cmd}
     */
    private function doExport(string $path): array
    {
        $rows = $this->browse->rows;
        $columns = $rows === [] ? [] : array_map('strval', array_keys((array) $rows[0]));
        try {
            (new CsvExporter($this->connection->db))->exportReportResults($path, $columns, $rows);
        } catch (\RuntimeException | \PDOException $e) {
            return [$this->mutate([
                'io' => $this->io->closed(),
                'ui' => $this->ui->withError('export failed: ' . $e->getMessage())->withStatus(null),
            ]), null];
        }

        return [$this->mutate([
            'io' => $this->io->closed(),
            'ui' => $this->ui->withError(null)->withStatus('exported ' . count($rows) . ' rows to ' . $path),
        ]), null];
    }

    /**
     * @return array{self, ?Cmd}
     */
    private function doImport(string $path, string $table): array
    {
        try {
            (new CsvExporter($this->connection->db))->importCsv($path, $table);
        } catch (\RuntimeException | \PDOException $e) {
            return [$this->mutate([
                'io' => $this->io->closed(),
                'ui' => $this->ui->withError('import failed: ' . $e->getMessage())->withStatus(null),
            ]), null];
        }

        $done = $this->mutate([
            'io' => $this->io->closed(),
            'ui' => $this->ui->withError(null)->withStatus('imported ' . $path . ' into ' . $table),
        ]);
        if ($done->browse->selectedTable === $table) {
            // The table on screen IS the import target — re-show its window.
            return [$done->loadTable($table), null];
        }

        return [$done, null];
    }

    private function handleRowsKey(KeyMsg $msg): self
    {
        if ($msg->type === KeyType::Up
            || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->mutate([
                'browse' => $this->browse->withRowCursor(max(0, $this->browse->rowCursor - 1)),
            ]);
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->mutate([
                'browse' => $this->browse->withRowCursor(
                    min(max(0, count($this->browse->rows) - 1), $this->browse->rowCursor + 1),
                ),
            ]);
        }
        // Wide results scroll column-wise via h/l + ←/→ — query runs AND table
        // previews alike ride the same ResultTable (SQL-C14/client-014).
        if ($this->browse->resultTable !== null) {
            if ($msg->type === KeyType::Left
                || ($msg->type === KeyType::Char && $msg->rune === 'h')) {
                return $this->mutate([
                    'browse' => $this->browse->withResultTable($this->browse->resultTable->scrollLeft()),
                ]);
            }
            if ($msg->type === KeyType::Right
                || ($msg->type === KeyType::Char && $msg->rune === 'l')) {
                return $this->mutate([
                    'browse' => $this->browse->withResultTable($this->browse->resultTable->scrollRight()),
                ]);
            }
        }
        return $this;
    }

    /**
     * Handle keyboard input while the Admin pane is focused.
     *
     * App-level keys handled here first: digits 1-8 (pane select), q (back
     * to Tables), p (pause polling), r (drop the query cache), and sidebar
     * j/k/arrows — but only when the focused page does NOT implement
     * {@see RowNavigable}; row-navigable pages receive j/k/arrows through
     * the delegation below so their in-table cursors work. Keys that are
     * globally consumed in update() BEFORE reaching this method at all:
     * Tab (pane cycle) and Escape/Ctrl+C (quit). Everything else — Space,
     * 'a', 'w', 's', 'h', 'l', '/' and any other editing keys — is
     * delegated to the active page's update() so pages like VariablesPage
     * and PerfSchemaPage can respond. The page returned by update() is
     * stored back in App, preserving in-memory state (cursor, active tab,
     * pending edits) across refresh cycles.
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
            if (isset($allPanes[$index]) && $allPanes[$index] !== $this->admin->pane) {
                $newApp = $this->withAdminCursor($index)->withAdminPane($allPanes[$index])->withAdminLoading(true);
                return [$newApp, Cmd::promise(fn() => $this->createAdminFetchPromise($this->admin->historyRecorder))];
            }
            if (isset($allPanes[$index])) {
                return [$this->withAdminCursor($index), null];
            }
        }
        // [q] returns to Tables pane
        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this->withPane(Pane::Tables), null];
        }
        // j/k or arrows navigate — but a RowNavigable page owns these keys:
        // its cursor lives inside the table, and intercepting them here made
        // the page handlers dead code (review M8). The sidebar is
        // still fully reachable via digits, and arrow navigation is kept for
        // pages without row navigation (ServerStatus, Dashboard).
        if (!$this->adminPage() instanceof RowNavigable) {
            if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
                $newCursor = ($this->admin->cursor + 1) % $count;
                $newPane = $allPanes[$newCursor];
                if ($newPane !== $this->admin->pane) {
                    $newApp = $this->withAdminCursor($newCursor)->withAdminPane($newPane)->withAdminLoading(true);
                    return [$newApp, Cmd::promise(fn() => $this->createAdminFetchPromise($this->admin->historyRecorder))];
                }
                return [$this->withAdminCursor($newCursor), null];
            }
            if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
                $newCursor = ($this->admin->cursor - 1 + $count) % $count;
                $newPane = $allPanes[$newCursor];
                if ($newPane !== $this->admin->pane) {
                    $newApp = $this->withAdminCursor($newCursor)->withAdminPane($newPane)->withAdminLoading(true);
                    return [$newApp, Cmd::promise(fn() => $this->createAdminFetchPromise($this->admin->historyRecorder))];
                }
                return [$this->withAdminCursor($newCursor), null];
            }
        }
        // [p] pause/resume polling (sync with DashboardPage if present)
        if ($msg->type === KeyType::Char && $msg->rune === 'p') {
            $newPaused = !$this->admin->paused;
            $page = $this->adminPage();
            if ($page instanceof \SugarCraft\Sql\Admin\Dashboard\DashboardPage) {
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
            try {
                [$newPage, ] = $page->update($msg);
                assert($newPage instanceof PageBase);
            } catch (\Throwable) {
                // SQL-C01 fold seam: a page reacting to reset must not kill the TUI.
                return [$this, null];
            }
            if ($newPage !== $page) {
                return [$this->withAdminPage($newPage), null];
            }
            return [$this, null];
        }

        // Delegate unhandled keys to the active page's update(), following the
        // precedence documented on handleAdminKey(): app-level digits, q, p,
        // r (and sidebar j/k for non-RowNavigable pages) are handled above;
        // page keys (j/k/arrows on RowNavigable pages, w, s, /, Space, etc.)
        // reach the page here. This ensures pages like VariablesPage can
        // respond to their own keys while preserving the returned page state
        // across poll-tick refreshes.
        $page = $this->adminPage();
        try {
            [$newPage, $cmd] = $page->update($msg);
            assert($newPage instanceof PageBase);
        } catch (\Throwable) {
            // SQL-C01 fold seam: page-level key handling failures degrade to
            // "key ignored", never to a dead TUI.
            return [$this, null];
        }
        return [$this->withAdminPage($newPage), $cmd];
    }

    /**
     * SQL-O04 v1: Ctrl+Space completion for the keyword at the caret. The
     * replacement is driven through the widget's own doors (Backspace
     * KeyMsgs + insertString) so cursor and surrounding text stay the
     * widget's business — no cross-lib edit of sugar-forms. One candidate
     * completes outright; several advance the caret word to their longest
     * common prefix when that is longer, otherwise the status line lists
     * them (capped — the roster is a floor, not a ceiling to show).
     */
    private function completeKeyword(): array
    {
        $editor = $this->editor();
        $word = Completer::wordAt($editor->lines, $editor->line(), $editor->column());
        if ($word === '') {
            return [$this->withQueryStatus('nothing to complete — type the start of a keyword'), null];
        }
        $candidates = Completer::candidates($word);
        if ($candidates === []) {
            return [$this->withQueryStatus('no keyword completes "' . $word . '"'), null];
        }
        $insert = count($candidates) === 1
            ? $candidates[0]
            : (strlen(Completer::longestCommonPrefix($candidates)) > strlen($word)
                ? Completer::longestCommonPrefix($candidates)
                : '');
        if ($insert === '') {
            $listed = array_slice($candidates, 0, 8);
            $more = count($candidates) - count($listed);
            return [$this->withQueryStatus(
                count($candidates) . ' complete "' . $word . '": ' . implode(', ', $listed)
                . ($more > 0 ? ' …' : '')
            ), null];
        }
        $next = $editor;
        for ($i = 0, $n = strlen($word); $i < $n; $i++) {
            [$next, ] = $next->update(new KeyMsg(KeyType::Backspace, ''));
            assert($next instanceof TextArea);
        }
        $next = $next->insertString($insert);
        return [$this->mutate([
            'query' => $this->query->withEditor($next)->withRecallIndex(null),
            'ui'    => $this->ui->withStatus('completed ' . $word . ' → ' . $insert),
        ]), null];
    }

    /** Completion echoes ride the query pane's status channel. */
    private function withQueryStatus(string $status): self
    {
        return $this->mutate(['ui' => $this->ui->withStatus($status)->withError(null)]);
    }

    private function editQuery(KeyMsg $msg): self
    {
        // Ctrl+F / Ctrl+Shift+F favorite/unfavorite shortcuts are intercepted
        // before the widget sees them.
        if ($msg->ctrl && !$msg->shift && $msg->rune === 'f') {
            return $this->favoriteQuery();
        }
        if ($msg->ctrl && $msg->shift && $msg->rune === 'f') {
            return $this->unfavoriteQuery();
        }
        // SQL-O01a: bare Up/Down recall executed history instead of moving
        // the cursor — in an EMPTY editor (the sync-run path clears it, so
        // ↑ after a run restages the statement that just executed) or while
        // a recall walk still matches the editor text verbatim. A drafted
        // query is never clobbered: once the text diverges, Up/Down revert
        // to plain cursor movement in the widget.
        if (($msg->type === KeyType::Up || $msg->type === KeyType::Down)
            && !$msg->ctrl && !$msg->shift
            && $this->recallApplies()) {
            return $this->recallHistory($msg->type === KeyType::Up);
        }
        // Everything else drives the editor: chars, backspace, space,
        // Enter→newline, and Up/Down move the cursor between lines. Direct
        // input ends any recall walk (SQL-O01a).
        [$editor, ] = $this->editor()->update($msg);
        assert($editor instanceof TextArea);
        return $this->mutate(['query' => $this->query->withEditor($editor)->withRecallIndex(null)]);
    }

    /**
     * SQL-O01a guard: recall applies when the editor is empty or still holds
     * the walked history entry verbatim — the check that keeps the walk from
     * overwriting a modified draft.
     */
    private function recallApplies(): bool
    {
        if ($this->query->history === []) {
            return false;
        }
        if ($this->editor()->value() === '') {
            return true;
        }
        $index = $this->query->recallIndex;
        return $index !== null
            && $this->editor()->value() === ($this->query->history[$index] ?? null);
    }

    /**
     * SQL-O01a: stage the next-older (Up) or next-newer (Down) entry of the
     * capped history into the editor and move the walk cursor, with a
     * position readout on the status line. The cap lives in
     * {@see QueryState::HISTORY_MAX} alone — this surface never renumbers it.
     */
    private function recallHistory(bool $older): self
    {
        $count = count($this->query->history);
        $current = $this->query->recallIndex;
        $next = $older
            ? min(($current ?? -1) + 1, $count - 1)
            : max(($current ?? 1) - 1, 0);
        $sql = $this->query->history[$next];
        return $this->mutate([
            'query' => $this->query
                ->withEditor($this->editor()->setValue($sql))
                ->withRecallIndex($next),
            'ui' => $this->ui->withStatus('history ' . ($next + 1) . '/' . $count)->withError(null),
        ]);
    }

    private function runQuery(): self
    {
        $sql = $this->editor()->value();
        $trimmed = trim($sql);
        if ($trimmed === '') {
            return $this;
        }
        // Record in history (front = newest); dedupe + cap live in one
        // place, QueryState::withRecordedQuery (client-016). A plain run
        // also replaces any live EXPLAIN plan (SQL-O09).
        $query = $this->query->withRecordedQuery($trimmed)->withPlan(null);
        try {
            // SQL-C11: the sync leg runs on the UI loop (that is what "sync
            // transport" means here) — the shared wall-clock cap turns an
            // unbounded server stall into a named error inside 30 s.
            $rows = $this->syncTimeout()->run(
                fn (): ?array => $this->connection->db->query($sql),
            );
            // Guard against null (e.g., connection lost after handled reconnect).
            if ($rows === null) {
                return $this->mutate([
                    'query' => $query,
                    'ui' => $this->ui->withError('connection lost; retry')->withStatus(null),
                ]);
            }
            return $this->mutate([
                'browse' => $this->browse
                    ->withSelectedTable('(query)')
                    ->withRows($rows)
                    ->withRowCursor(0)
                    // Query results render through ResultTable (horizontal scroll,
                    // JSON pretty, styled NULL). Cleared again on table browse.
                    ->withResultTable(ResultTable::fromRows($rows)),
                'query' => $query->withEditor($this->editor()->reset()),
                'ui' => $this->ui->withError(null)->withStatus(self::rowsSummary(count($rows))),
            ]);
        } catch (\PDOException | ReconnectException | StatementTimeoutException $e) {
            return $this->mutate([
                'query' => $query,
                'ui' => $this->ui->withError($e->getMessage())->withStatus(null),
            ]);
        }
    }

    /**
     * Statements that destroy data or privileges irreversibly (D-M27). The
     * match is anchored on the leading keyword of the trimmed statement; a
     * multi-statement script that hides a DROP behind a leading SELECT is
     * outside this gate (the editor runs single statements in practice).
     */
    private static function isDestructiveStatement(string $sql): bool
    {
        return preg_match('/^(DROP|TRUNCATE|DELETE|ALTER|RENAME|GRANT|REVOKE)\b/i', $sql) === 1;
    }

    /**
     * Begin executing a query, either synchronously (SQLite) or asynchronously
     * (MySQL/Postgres).
     *
     * For SQLite: runs the query immediately and returns the updated model
     * with no pending command. For async flavors: marks the model as loading,
     * records history immediately, and returns a promise that resolves to a
     * {@see QueryRowsLoadedMsg} which is folded back via applyQueryRows().
     *
     * @return array{self, ?Cmd}
     */
    private function beginRunQuery(): array
    {
        $sql = $this->editor()->value();
        $trimmed = trim($sql);
        if ($trimmed === '') {
            return [$this, null];
        }

        // D-M27: the editor's run keys used to fire DROP/TRUNCATE/… with zero
        // confirmation while KILL and SET GLOBAL gated behind prompts. First
        // press on a destructive statement only WARNS and arms; a second
        // consecutive press whose trimmed statement equals the staged SQL
        // confirms. Any other query run, or an edit whose trimmed text differs
        // from the staged SQL, disarms — a whitespace-only edit trims to the
        // same statement and leaves the arm standing.
        if (self::isDestructiveStatement($trimmed)) {
            if ($this->ui->armedDestructive !== $trimmed) {
                return [$this->mutate(['ui' => $this->ui
                    ->withArmedDestructive($trimmed)
                    ->withError(null)
                    ->withStatus('⚠ destructive statement — ctrl+r again (unmodified) to confirm')]), null];
            }
            return $this->mutate(['ui' => $this->ui->withArmedDestructive(null)])->dispatchRunQuery();
        }
        if ($this->ui->armedDestructive !== null) {
            // A different (safe) statement ran — the armed one is stale.
            return $this->mutate(['ui' => $this->ui->withArmedDestructive(null)])->dispatchRunQuery();
        }
        return $this->dispatchRunQuery();
    }

    /**
     * The actual run dispatch, split out of beginRunQuery() so the D-M27
     * confirm arm can be consumed on a fresh model (PHP forbids rebinding
     * $this inside a method).
     *
     * @return array{self, ?Cmd}
     */
    private function dispatchRunQuery(): array
    {
        $sql = $this->editor()->value();
        $trimmed = trim($sql);

        // SQL-O09: a leading EXPLAIN is answered with the coloured plan tree,
        // not the flat provider grid the statement used to produce. The
        // inspector never executes what it inspects: an 'ANALYZE' modifier
        // (and any explicit FORMAT clause — the providers speak their own
        // dialect) is STRIPPED here, never honoured, because PostgreSQL's
        // EXPLAIN ANALYZE RUNS the statement. The plan fetch is the
        // statement-analysis leg, so it rides the same bounded sync path the
        // MDL-tab [3] explain already uses.
        if (($inner = self::explainInner($trimmed)) !== null) {
            return $this->runExplainPlan($inner, $this->query->withRecordedQuery($trimmed));
        }

        // Record in history immediately for the async leg; the sync leg
        // falls through to runQuery(), which records there. Both arms
        // dedupe+cap through the same QueryState::withRecordedQuery
        // (client-016) on the same trimmed text, so neither path can
        // append the statement twice. A plain run also replaces any live
        // EXPLAIN plan (SQL-O09: the tree yields to whatever answers next).
        $query = $this->query->withRecordedQuery($trimmed)->withPlan(null);

        // SQLite runs synchronously — a local file cannot freeze the event loop
        // and there is no React driver for it. A TLS-verified MySQL-family
        // connection runs synchronously too: the React mysql:// transport
        // cannot carry TLS, so the sync PDO session (which can) is the only
        // honest path — see asyncTransportRefusedByTls().
        if (!$this->asyncTransportAvailable()) {
            // Run synchronously and return without a pending command.
            // runQuery() handles null-safety, error state and its own
            // history recording — beginRunQuery() already refused the
            // empty editor, so the old "unchanged model, restore history"
            // arm was unreachable and is gone (client-016).
            return [$this->runQuery(), null];
        }

        // Async flavor (MySQL/Postgres): mark loading and dispatch promise.
        // rowsLoading must be TRUE while the promise is in flight — the Rows
        // pane renders 'loading…' from it; a false here painted "(empty)" over
        // a still-running query (review C3), indistinguishable from a real
        // zero-row result.
        //
        // client-012: bump the query generation BEFORE dispatch so the in-flight
        // reply carries a number the fold can compare. The old staleness arm
        // matched SQL TEXT (history[0] === $msg->sql), so SELECT → write →
        // re-SELECT re-admitted the FIRST leg's pre-write rows over the fresh
        // run. A monotonic gen makes superseded legs unconditionally droppable.
        $query = $query->withIncrementedQueryGen();
        $loading = $this->mutate([
            'browse' => $this->browse
                ->withSelectedTable('(query)')
                ->withRows([])
                ->withRowCursor(0)
                ->withResultTable(null)
                ->withRowsLoading(true),
            'query' => $query,
            'ui' => $this->ui->withError(null)->withStatus('running query…'),
        ]);
        return [$loading, Cmd::promise(fn () => $this->createQueryPromise($trimmed, $query->queryGen))];
    }

    /**
     * Cache key for the pooled async admin/query connection.
     *
     * The credential belongs in the identity (admindata-010): flavor, DSN and
     * user can all survive a password rotation while the old authenticated
     * session they warmed must not — without the fingerprint every post-
     * rotation leg rode the stale session. sha256 of the secret, never the
     * secret: the key can surface in a dump without leaking the credential.
     */
    private static function adminConnKey(bool $isPostgres, string $dsn, string $username, string $password): string
    {
        return ($isPostgres ? 'pgsql' : 'mysql') . '|' . $dsn . '|' . $username . '|' . hash('sha256', $password);
    }

    /**
     * Build the async query execution promise for MySQL/Postgres.
     *
     * Runs the query on the React loop via the same pooled async connection
     * used by createRowsPromise/admin (same DSN → same connection key).
     * Always resolves (never rejects) to prevent unhandled promise rejections.
     *
     * @return \React\Promise\PromiseInterface<QueryRowsLoadedMsg>
     */
    private function createQueryPromise(string $sql, int $queryGen): \React\Promise\PromiseInterface
    {
        try {
            $context = $this->connection->serverContext ?? $this->createContext();
        } catch (\Throwable $e) {
            return \React\Promise\resolve(new QueryRowsLoadedMsg($sql, [], $e->getMessage(), $queryGen));
        }
        if ($context === null) {
            return \React\Promise\resolve(
                new QueryRowsLoadedMsg($sql, [], 'This driver does not support async queries', $queryGen),
            );
        }

        $dsn = $context->connection()->dsn();
        $username = $context->connection()->username();
        $password = $context->password();
        $isPostgres = $context instanceof PostgresServerContext;

        $cache = AdminQueryCache::instance();
        $connKey = self::adminConnKey($isPostgres, $dsn, $username, $password);
        try {
            $connection = $cache->connection($connKey, static function () use ($isPostgres, $dsn, $username, $password) {
                return $isPostgres
                    ? new ReactPostgresConnection($dsn, $username, $password)
                    : new ReactMysqlConnection($dsn, $username, $password);
            });
        } catch (\Throwable $e) {
            // A07 fail-fast doors (empty user / unrecognised sslmode) throw
            // at construction; this seam keeps the method's documented
            // always-resolves contract — the pane surfaces the refusal as
            // the error text instead of the throw crashing the loop.
            return \React\Promise\resolve(new QueryRowsLoadedMsg($sql, [], $e->getMessage(), $queryGen));
        }

        return $connection->query($sql)
            ->then(
                static fn (array $rows): QueryRowsLoadedMsg => new QueryRowsLoadedMsg($sql, $rows, null, $queryGen),
            )
            ->otherwise(
                static fn (\Throwable $e): QueryRowsLoadedMsg => new QueryRowsLoadedMsg($sql, [], $e->getMessage(), $queryGen),
            );
    }

    /**
     * Fold an async query result into the model.
     *
     * Mirrors applyLoadedRows for the table-browse path. A result whose
     * dispatch generation no longer matches the current one is stale — a
     * fresher query was issued after it was dispatched — and is dropped so an
     * in-flight answer for an old run can never overwrite a newer result. The
     * generation (not the SQL text) is the staleness authority (client-012):
     * running the same statement twice with a write between is exactly the
     * case text comparison got wrong.
     */
    private function applyQueryRows(QueryRowsLoadedMsg $msg): self
    {
        // Stale result: a query with a newer generation was dispatched since.
        if ($msg->queryGen !== $this->query->queryGen) {
            return $this;
        }
        if ($msg->error !== null) {
            return $this->mutate([
                'browse' => $this->browse->withRows([])->withResultTable(null)->withRowsLoading(false),
                'ui' => $this->ui->withError($msg->error)->withStatus(null),
            ]);
        }
        return $this->mutate([
            'browse' => $this->browse
                ->withRows($msg->rows)
                ->withRowCursor(0)
                ->withResultTable(ResultTable::fromRows($msg->rows))
                ->withRowsLoading(false),
            'ui' => $this->ui->withError(null)->withStatus(self::rowsSummary(count($msg->rows))),
        ]);
    }

    /**
     * Begin loading a table's row preview.
     *
     * SQLite loads synchronously — a local file can't freeze the event loop and
     * there is no React driver for it. MySQL/Postgres load on the React loop so
     * a large or remote table never blocks the UI: the model is marked loading
     * and the rows arrive later via {@see TableRowsLoadedMsg}. Either way the
     * preview is blob-safe (see {@see PreviewQuery}) — no BLOB bytes cross the
     * wire just to peek at a table.
     *
     * @return array{self, ?Cmd}
     */
    private function beginLoadTable(string $name, int $pageOffset = 0): array
    {
        if (!$this->asyncTransportAvailable()) {
            return [$this->loadTable($name, $pageOffset), null];
        }
        // client-012: bump the load generation before dispatch. Re-selecting the
        // SAME table leaves selectedTable unchanged, so the name guard in the
        // fold cannot see the older leg is now superseded — the gen can.
        $browse = $this->browse
            ->withTableCursor(array_search($name, $this->browse->visibleTables(), true) ?: 0)
            ->withSelectedTable($name)
            ->withRows([])
            ->withRowCursor(0)
            ->withResultTable(null)
            ->withRowsLoading(true)
            ->withIncrementedLoadGen()
            ->withPageOffset($pageOffset);
        $loading = $this->mutate([
            'browse' => $browse,
            'query' => $this->query->withPlan(null),
            'ui' => $this->ui->withError(null)->withStatus('loading…'),
        ]);
        return [$loading, Cmd::promise(fn () => $this->createRowsPromise($name, $browse->loadGen, $pageOffset))];
    }

    /**
     * SQL-O11: PgDn/PgUp on a live preview. Forward is only possible while the
     * current page is full (a short page IS the table end — claiming more
     * would lie); backward from page 1 says so instead of re-fetching it.
     * The fetch itself reuses the browse load leg, so sync (SQLite) and async
     * (MySQL/Postgres) entry flavors page identically.
     *
     * @return array{self, ?Cmd}
     */
    /**
     * Split a leading-EXPLAIN statement into the plan question it asks
     * (SQL-O09). Returns the inner statement to explain, or null when the
     * text is not a single explainable statement (an embedded semicolon
     * keeps multi-statement batches out of the plan path — they belong to
     * the ordinary run leg and its own guards).
     */
    private static function explainInner(string $trimmed): ?string
    {
        if (preg_match('/^EXPLAIN(?:\s+ANALYZE)?(?:\s*\(\s*FORMAT\s+\w+\s*\)|\s+FORMAT\s*=\s*\w+)?\s+(\S.*)$/is', $trimmed, $m) !== 1) {
            return null;
        }
        $inner = trim($m[1], "; \t\n\r");
        if ($inner === '' || str_contains($inner, ';')) {
            return null;
        }

        return $inner;
    }

    /**
     * Fetch and fold the plan tree. Deliberately synchronous on every
     * flavor: a plan query is an introspection leg, the same cost class as
     * the admin panels' sync introspection, and it stays under the shared
     * StatementTimeout bound.
     *
     * @return array{self, ?Cmd}
     */
    private function runExplainPlan(string $inner, QueryState $query): array
    {
        try {
            $view = $this->syncTimeout()->run(
                fn (): ExplainView => ExplainView::run($this->connection->db, $inner),
            );
        } catch (\PDOException | ReconnectException | StatementTimeoutException $e) {
            return [$this->mutate([
                'query' => $query->withPlan(null),
                'ui' => $this->ui->withError('EXPLAIN failed: ' . $e->getMessage())->withStatus(null),
            ]), null];
        }

        return [$this->mutate([
            'browse' => $this->browse
                ->withRows([])
                ->withRowCursor(0)
                ->withResultTable(null)
                ->withSelectedTable(null),
            'query' => $query->withPlan($view->render()),
            'ui' => $this->ui->withError(null)->withStatus('explain plan — any query run or table preview replaces it'),
        ]), null];
    }

    private function beginPageShift(bool $forward): array
    {
        $limit = PreviewQuery::DEFAULT_LIMIT;
        $table = (string) $this->browse->selectedTable;
        if ($forward) {
            if (count($this->browse->rows) < $limit) {
                return [$this->mutate([
                    'ui' => $this->ui->withStatus('end of table — no further page'),
                ]), null];
            }
            return $this->beginLoadTable($table, $this->browse->pageOffset + $limit);
        }
        if ($this->browse->pageOffset === 0) {
            return [$this->mutate([
                'ui' => $this->ui->withStatus('already at the first page'),
            ]), null];
        }
        return $this->beginLoadTable($table, max(0, $this->browse->pageOffset - $limit));
    }

    /**
     * Whether table-row browsing for this flavor must run on the React loop.
     * SQLite is a local file (synchronous is fine and there is no async driver).
     */
    private function isAsyncFlavor(): bool
    {
        return match ($this->connection->flavor) {
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona, Flavor::Postgres => true,
            Flavor::Sqlite => false,
        };
    }

    /**
     * TLS gate: whether the ReactPHP async transport must NOT be used for
     * this connection.
     *
     * ReactMysqlConnection dials a `mysql://` URI that carries no TLS
     * material, while MysqlDatabase::sslDriverOptions() treats require /
     * verify_ca / verify_identity as a DEMAND for a verified TLS session.
     * Running background or user queries on the async transport under those
     * modes would negotiate plaintext on a connection whose owner asked for
     * verification — a silent security downgrade the caller cannot see.
     * So for MySQL-family flavors in a verified mode the app refuses the
     * async transport outright: queries and table browse fall back to the
     * synchronous TLS-speaking PDO connection ({@see asyncTransportAvailable()}),
     * and the admin live-refresh stands down ({@see subscriptions()} and
     * createAdminFetchPromise()), announced by a one-time launcher notice
     * (bin/candy-sql) plus a standing status line (App::start()).
     *
     * The mode is fixed for the life of the connection (the config is
     * immutable), so this predicate is constant once built — safe to branch
     * at subscription-declaration time, unlike the per-tick throttle decision
     * documented in subscriptions(). PostgreSQL is never gated: its async
     * connector carries TLS natively — {@see ReactPostgresConnection} threads
     * the DSN's libpq sslmode into PgAsync's `tls` parameter, which refuses
     * plaintext when a required mode cannot be negotiated (class docblock
     * there records the verify-ca parity caveat).
     */
    private function asyncTransportRefusedByTls(): bool
    {
        return match ($this->connection->flavor) {
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona
                => MysqlDatabase::requiresVerifiedTls($this->connection->sslMode),
            default => false,
        };
    }

    /**
     * Async transport available for this connection: an async-capable flavor
     * whose TLS demand the React transport can honour. The single door all
     * dialing call sites consult (beginRunQuery, beginLoadTable) — the three
     * ReactMysqlConnection/ReactPostgresConnection construction sites live
     * behind promises minted only through these two entry points and the
     * admin fetch, which refuses internally.
     */
    private function asyncTransportAvailable(): bool
    {
        return $this->isAsyncFlavor() && !$this->asyncTransportRefusedByTls();
    }

    /**
     * Fold an async browse result into the model. A result for a table the user
     * has since navigated away from, or a superseded load of the SAME table
     * (out-of-order settle — client-012), is stale and dropped, leaving the
     * current in-flight load's spinner untouched.
     */
    private function applyLoadedRows(TableRowsLoadedMsg $msg): array
    {
        if ($msg->table !== $this->browse->selectedTable) {
            return [$this, null];
        }
        // Re-selecting the same table keeps selectedTable unchanged; only the
        // generation can tell the older in-flight leg from the current one.
        if ($msg->loadGen !== $this->browse->loadGen) {
            return [$this, null];
        }
        if ($msg->error !== null) {
            return [$this->mutate([
                'browse' => $this->browse->withRows([])->withRowsLoading(false),
                'ui' => $this->ui->withError($msg->error)->withStatus(null),
            ]), null];
        }
        // SQL-O11: a PgDn onto an exactly-full last table answers with an
        // empty page. That is the far edge we just walked past — rewind to the
        // last non-empty page and re-fetch it rather than parking the user on
        // an empty pane.
        if ($msg->rows === [] && $this->browse->pageOffset > 0) {
            $lastFull = $this->browse->pageOffset - PreviewQuery::DEFAULT_LIMIT;

            return $this->beginLoadTable($msg->table, max(0, $lastFull));
        }
        return [$this->mutate([
            'browse' => $this->browse
                ->withRows($msg->rows)
                ->withRowCursor(0)
                // SQL-C14/client-014: browse joins the query path on ONE grid
                // object — the old null here meant the Rows pane fell back to
                // the non-scrolling sugar-table arm, so h/l silently died and
                // a wide preview cut mid-word (the capability depended on the
                // ENTRY, not the pane).
                ->withResultTable(ResultTable::fromRows($msg->rows))
                ->withRowsLoading(false),
            'ui' => $this->ui->withError(null)->withStatus(self::browseSummary(count($msg->rows), $this->browse->pageOffset)),
        ]), null];
    }

    /**
     * Build the non-blocking browse fetch: introspect the table's columns then
     * fetch the blob-safe preview, BOTH on the React loop, so neither the (tiny)
     * metadata query nor the row fetch blocks the UI. Reuses the same pooled
     * async connection as the admin pane (same DSN → same connection key).
     */
    private function createRowsPromise(string $name, int $loadGen, int $pageOffset = 0): \React\Promise\PromiseInterface
    {
        try {
            $context = $this->connection->serverContext ?? $this->createContext();
        } catch (\Throwable $e) {
            return \React\Promise\resolve(new TableRowsLoadedMsg($name, [], $e->getMessage(), $loadGen));
        }
        if ($context === null) {
            return \React\Promise\resolve(
                new TableRowsLoadedMsg($name, [], 'Async browse unsupported for this driver', $loadGen),
            );
        }

        $dsn = $context->connection()->dsn();
        $username = $context->connection()->username();
        $password = $context->password();
        $isPostgres = $context instanceof PostgresServerContext;
        $flavor = $isPostgres ? Flavor::Postgres : $this->connection->flavor;

        $cache = AdminQueryCache::instance();
        $connKey = self::adminConnKey($isPostgres, $dsn, $username, $password);
        try {
            $connection = $cache->connection($connKey, static function () use ($isPostgres, $dsn, $username, $password) {
                return $isPostgres
                    ? new ReactPostgresConnection($dsn, $username, $password)
                    : new ReactMysqlConnection($dsn, $username, $password);
            });
        } catch (\Throwable $e) {
            // A07: the dial doors throw at construction; the browse seam
            // folds them into the rows-msg error text like any other fetch
            // failure, never a loop-crashing sync throw.
            return \React\Promise\resolve(new TableRowsLoadedMsg($name, [], $e->getMessage(), $loadGen));
        }

        return $connection->query(PreviewQuery::columnsSql($flavor, $name))
            ->then(function (array $colRows) use ($connection, $flavor, $name, $pageOffset): \React\Promise\PromiseInterface {
                $columns = PreviewQuery::classify($flavor, $colRows);
                return $connection->query(PreviewQuery::build($flavor, $name, $columns, PreviewQuery::DEFAULT_LIMIT, $pageOffset));
            })
            ->then(
                static fn (array $rows): TableRowsLoadedMsg => new TableRowsLoadedMsg($name, $rows, null, $loadGen),
            )
            ->otherwise(
                static fn (\Throwable $e): TableRowsLoadedMsg => new TableRowsLoadedMsg($name, [], $e->getMessage(), $loadGen),
            );
    }

    /**
     * Synchronously load a blob-safe row preview. Used at startup (no event loop
     * yet) and for SQLite browsing. The preview never selects BLOB/large-text
     * bytes — see {@see PreviewQuery} — so even a media table loads cheaply.
     */
    private function loadTable(string $name, int $pageOffset = 0): self
    {
        try {
            // SQL-C11: the startup/SQLite browse legs join the run leg under
            // the same wall-clock cap — one stall class, one bound.
            $columns = PreviewQuery::classify(
                $this->connection->flavor,
                $this->syncTimeout()->run(
                    fn (): ?array => $this->connection->db->query(PreviewQuery::columnsSql($this->connection->flavor, $name)),
                ) ?? [],
            );
            $rows = $this->syncTimeout()->run(
                fn (): ?array => $this->connection->db->query(
                    PreviewQuery::build($this->connection->flavor, $name, $columns, PreviewQuery::DEFAULT_LIMIT, $pageOffset),
                ),
            ) ?? [];
            return $this->mutate([
                'browse' => $this->browse
                    ->withTableCursor(array_search($name, $this->browse->visibleTables(), true) ?: 0)
                    ->withSelectedTable($name)
                    ->withRows($rows)
                    ->withRowCursor(0)
                    // SQL-C14/client-014: the sync browse arms (SQLite + startup)
                    // join the async one on the SAME grid object — entry flavor
                    // must not decide whether h/l scrolls.
                    ->withResultTable(ResultTable::fromRows($rows))
                    ->withRowsLoading(false)
                    ->withPageOffset($pageOffset),
                'query' => $this->query->withPlan(null),
                'ui' => $this->ui->withError(null)->withStatus(self::browseSummary(count($rows), $pageOffset)),
            ]);
        } catch (\PDOException | ReconnectException | StatementTimeoutException $e) {
            return $this->mutate([
                'browse' => $this->browse->withRowsLoading(false),
                'query' => $this->query->withPlan(null),
                'ui' => $this->ui->withError($e->getMessage())->withStatus(null),
            ]);
        }
    }

    private function withTableCursor(int $i): self
    {
        // SQL-O10: the cursor indexes the FILTERED view — a stale raw-list
        // size would let it point past the end the moment a filter narrows.
        $size = count($this->browse->visibleTables());
        if ($size === 0) {
            return $this;
        }
        // Wrap around: moving up past the top lands on the bottom of the list,
        // and moving down past the bottom lands back on the top. The extra
        // "+ $size" keeps the result non-negative for the up direction (-1).
        return $this->mutate([
            'browse' => $this->browse->withTableCursor((($i % $size) + $size) % $size),
        ]);
    }

    private function withAdminPane(AdminPane $adminPane): self
    {
        // AdminState::withPane resets the page to null, forcing lazy
        // recreation against the new pane.
        return $this->mutate(['admin' => $this->admin->withPane($adminPane)]);
    }

    private function withAdminCursor(int $adminCursor): self
    {
        $allPanes = AdminPane::orderedCases();
        return $this->mutate([
            'admin' => $this->admin->withCursor(max(0, min($adminCursor, count($allPanes) - 1))),
        ]);
    }

    private function withPaused(bool $paused): self
    {
        return $this->mutate(['admin' => $this->admin->withPaused($paused)]);
    }

    private function withAdminPage(PageBase $newPage): self
    {
        return $this->mutate(['admin' => $this->admin->withPage($newPage)]);
    }

    /**
     * Lazily create the admin page for the currently selected admin pane.
     *
     * Uses ServerContext (MySQL) or PostgresServerContext (PostgreSQL) to
     * instantiate the appropriate PageBase subclass. Cache is invalidated
     * when the admin pane changes (via AdminState::withPane resetting the
     * page to null).
     */
    public function adminPage(): PageBase
    {
        if ($this->admin->page !== null) {
            return $this->admin->page;
        }

        $context = $this->connection->serverContext ?? $this->createContext();

        if ($context === null) {
            // Unsupported flavor (e.g., SQLite) - use empty context to avoid render errors
            $context = new EmptyServerContext();
        }

        // Build context with cached data if available
        $context = new AsyncCachingServerContext(
            $context,
            $this->admin->cachedStatusVars,
            $this->admin->cachedServerVars,
            $this->admin->loading,
            // AdminState keeps the payload's true fetchedAt beside the vars;
            // pass it so a pane rebuild stamps the snapshot's real age
            // instead of "now" (review MINOR-1 — false Running on rebuild).
            statusVarsArrivedAt: $this->admin->cacheTs > 0.0 ? $this->admin->cacheTs : null,
        );

        // SQL-C01 fold seam (build leg): a page constructor or its data
        // seed throwing must not escape into the render path and kill the
        // TUI. The Dashboard page over the same (sentinel-safe) context is
        // the floor that provably cannot throw; its empty-state legs paint
        // "no data" honestly.
        try {
            return match ($this->admin->pane) {
                AdminPane::ProcessList => ConnectionsPage::new($context),
                AdminPane::Dashboard => new DashboardPage($context),
                AdminPane::Variables => self::buildVariablesPage($context),
                AdminPane::Status => self::buildServerStatusPage($context),
                // SQL-P14 (adminpages-025): both report panes shared one
                // undifferentiated page; each now seeds its own category and
                // surfaces its pane name in the header.
                AdminPane::QueryStats => ReportsPage::new($context, $context->connection(), ReportsPage::seedCategoryFor(AdminPane::QueryStats), AdminPane::QueryStats->label()),
                AdminPane::TableStats => ReportsPage::new($context, $context->connection(), ReportsPage::seedCategoryFor(AdminPane::TableStats), AdminPane::TableStats->label()),
                AdminPane::PerfSchema => PerfSchemaPage::new($context, EasySetupDetector::fromContext($context)),
                AdminPane::Debug => DebugPage::new($context),
            };
        } catch (\Throwable) {
            return new DashboardPage($context);
        }
    }

    /**
     * Create the appropriate server context based on database flavor.
     * Returns null for unsupported flavors (e.g., SQLite).
     *
     * The built context is memoized per database handle in AdminQueryCache,
     * NOT minted fresh per call (E718): adminPage() re-runs this on every pane
     * reset, and a cold ServerContext means a cold status-variables TTL cache,
     * which means a synchronous SHOW GLOBAL STATUS on the render path on every
     * navigation. One shared instance lets the TTL window actually cover
     * repeated renders.
     */
    private function createContext(): ?ServerContextInterface
    {
        $db = $this->connection->db;
        $flavor = $this->connection->flavor;

        return match ($flavor) {
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => AdminQueryCache::instance()->serverContext(
                $db,
                static fn(DatabaseInterface $connection): ServerContextInterface => new ServerContext($connection, $flavor),
            ),
            Flavor::Postgres => AdminQueryCache::instance()->serverContext(
                $db,
                fn(DatabaseInterface $connection): ServerContextInterface => $this->createPostgresContext(),
            ),
            default => null,
        };
    }

    /**
     * Create a PostgresServerContext wrapping PostgresAdminProvider.
     */
    private function createPostgresContext(): ServerContextInterface
    {
        $provider = PostgresAdminProvider::new($this->connection->db);
        return PostgresServerContext::new($this->connection->db, $provider);
    }

    /**
     * Build a VariablesPage with its Catalog and VariableEditor collaborators.
     *
     * SQL-C02 (client-002 + adminpages-030 merged): the collaborators must
     * actually REACH the page — and the edit bridge is MySQL-family only.
     * VariableEditor issues SET GLOBAL / SET PERSIST with backtick names,
     * which PostgreSQL answers 42601; off the MySQL family the page runs in
     * its existing read-only no-edit mode (editor=null), while the Catalog —
     * pure metadata — still powers the category tree and [rw] display.
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

        $editor = match ($context->flavor()) {
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => VariableEditor::new($context, $catalog),
            default => null,
        };

        return new VariablesPage($context, $catalog, $editor);
    }

    /**
     * Build a ServerStatusPage with a Sampler for per-second rate sampling.
     *
     * The Sampler requires a StatusSnapshotProviderInterface, so we wrap
     * the ServerContextInterface in a ServerStatusSnapshotAdapter.
     */
    private static function buildServerStatusPage(ServerContextInterface $context): ServerStatusPage
    {
        $adapter = new ServerStatusSnapshotAdapter($context);
        $sampler = new Sampler($adapter);

        return ServerStatusPage::new($context, $sampler);
    }

    private function withPane(Pane $p): self
    {
        // The editor is focused only while the Query pane is active, so its
        // cursor renders only there (matching the old per-pane cursor mark).
        $editor = $p === Pane::Query ? $this->editor()->focus()[0] : $this->editor()->blur();
        return $this->mutate([
            'ui' => $this->ui->withPane($p),
            'query' => $this->query->withEditor($editor),
        ]);
    }

    /**
     * The SQL editor, lazily defaulted so a freshly constructed App (e.g. in
     * tests) needn't supply one. {@see withPane()} swaps in the focused
     * instance when the Query pane is entered.
     */
    /**
     * D-M25: count-aware status copy — one row reads "1 row", not "1 rows".
     */
    private static function rowsSummary(int $count): string
    {
        return $count === 1 ? '1 row' : $count . ' rows';
    }

    /**
     * SQL-O11: the browse status line as a page counter. Small tables (all
     * rows on one unfiltered page) keep the plain 'N rows' summary; anything
     * windowed reads 'page X (rows a–b)' — absolute table coordinates, no
     * claim about the total the page never saw.
     */
    private static function browseSummary(int $count, int $pageOffset): string
    {
        $limit = PreviewQuery::DEFAULT_LIMIT;
        if ($pageOffset === 0 && $count < $limit) {
            return self::rowsSummary($count);
        }
        if ($count === 0) {
            return self::rowsSummary(0);
        }

        $page = intdiv($pageOffset, $limit) + 1;
        $first = $pageOffset + 1;
        $last = $pageOffset + $count;

        return "page {$page} (rows {$first}–{$last})";
    }

    public function editor(): TextArea
    {
        return $this->query->editor ?? self::newEditor();
    }

    private static function newEditor(): TextArea
    {
        return TextArea::new()->withPlaceholder('-- type SQL, ctrl+r to run --');
    }

    private function favoriteQuery(): self
    {
        // SQL-O01b: every Ctrl+F chord answers on the status line. The
        // favorites list has no pane yet (the drawer rides a later leg),
        // so without a readout save/duplicate/remove were byte-identical
        // UI — state mutation with zero observability (once-001).
        $trimmed = trim($this->editor()->value());
        if ($trimmed === '') {
            return $this->mutate(['ui' => $this->ui->withStatus('nothing to save')->withError(null)]);
        }
        if (in_array($trimmed, $this->query->favorites, true)) {
            return $this->mutate(['ui' => $this->ui->withStatus(
                'already saved (' . count($this->query->favorites) . ')'
            )->withError(null)]);
        }
        $favorites = $this->query->favorites;
        array_unshift($favorites, $trimmed);
        return $this->mutate([
            'query' => $this->query->withFavorites($favorites),
            'ui' => $this->ui->withStatus('saved (' . count($favorites) . ')')->withError(null),
        ]);
    }

    private function unfavoriteQuery(): self
    {
        // SQL-O01b: the unfavorite chord reports too — removals and
        // misses are distinguishable without the drawer.
        $trimmed = trim($this->editor()->value());
        if (!in_array($trimmed, $this->query->favorites, true)) {
            return $this->mutate(['ui' => $this->ui->withStatus(
                'not in favorites (' . count($this->query->favorites) . ')'
            )->withError(null)]);
        }
        $favorites = array_values(array_filter(
            $this->query->favorites,
            fn(string $f) => $f !== $trimmed
        ));
        return $this->mutate([
            'query' => $this->query->withFavorites($favorites),
            'ui' => $this->ui->withStatus('removed (' . count($favorites) . ')')->withError(null),
        ]);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        if ($this->ui->pane !== Pane::Admin) {
            return null;
        }

        // TLS gate: no admin fetch can run on the refused transport, so the
        // tick itself stands down. Declaration-time is safe HERE (unlike the
        // throttle decision below): the ssl-mode is immutable for the life of
        // the connection, so this branch cannot need to change its mind
        // across ticks within one pane visit.
        if ($this->asyncTransportRefusedByTls()) {
            return null;
        }

        // ONE stable declaration while the Admin pane is open. The throttle
        // decision (full fetch vs. pending-drain vs. silence) is made per-fire
        // inside update()'s AdminTickMsg arm, NOT here: Program::reconcileSubscriptions()
        // diffs subscriptions only by id, so whichever closure this method
        // returned at install time would be locked in until the pane leaves
        // Admin and re-enters — a declaration-time branch can never change
        // its mind across ticks.
        return (new Subscriptions())->withTick('admin-fetch', 1.0, static fn (): Msg => new AdminTickMsg());
    }

    /**
     * Per-fire admin throttle. Beyond the dashboard refresh window
     * (CacheTtl::DASHBOARD — Workbench's 1s cadence) run the full fetch —
     * fetching inside the window would only refill a still-fresh cache.
     * During cooldown the tick still matters: pages register
     * pending SQL through AdminQueryCache (that is how SHOW FULL PROCESSLIST
     * results ever arrive), so drain it; otherwise emit nothing.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleAdminTick(): array
    {
        $elapsed = microtime(true) - $this->admin->lastFetchAt;

        // In-flight guard: while a full fetch is still running, the next due
        // tick must not pile a second one onto the same connection — it falls
        // through to drain-only/silence instead. The in-flight fetch drains the
        // pending queue on completion, so nothing starves.
        if ($elapsed >= CacheTtl::DASHBOARD && !$this->admin->loading) {
            return [$this, Cmd::batch(
                static fn (): Msg => new AdminFetchStartedMsg(),
                Cmd::promise(fn () => $this->createAdminFetchPromise($this->admin->historyRecorder)),
            )];
        }

        if (AdminQueryCache::instance()->hasPending()) {
            return [$this, Cmd::promise(fn () => $this->createAdminFetchPromise($this->admin->historyRecorder, drainOnly: true))];
        }

        return [$this, null];
    }

    /**
     * @param bool $drainOnly When true, skip the status/server fetch entirely and
     *        run only the page-registered pending SQL — used by the throttled
     *        ticks inside CacheTtl::DASHBOARD so page queries still land during the
     *        status cooldown. Resolves AdminDrainCompletedMsg (NOT
     *        AdminDataLoadedMsg — empty arrays there would wipe cached vars),
     *        or null when there is nothing pending.
     */
    private function createAdminFetchPromise(?HistoryRecorder $recorder = null, bool $drainOnly = false): \React\Promise\PromiseInterface
    {
        // TLS gate: under a verified ssl-mode the React transport cannot
        // honour the connection's TLS demand, so the admin refresh stands
        // down BEFORE any context/connection resolution — the refusal must
        // be the reason nothing dials, not a side effect of a failed lookup.
        // Same resolve-shape as the null-context arm: the full fetch settles
        // empty (clears loading, stamps lastFetchAt), a drain settles null.
        if ($this->asyncTransportRefusedByTls()) {
            return \React\Promise\resolve($drainOnly ? null : new AdminDataLoadedMsg([], [], microtime(true)));
        }

        // Wrap entire async flow in try-catch to ensure we ALWAYS return a promise.
        // This prevents synchronous exceptions from causing unhandled promise rejections.
        try {
            $context = $this->connection->serverContext ?? $this->createContext();
        } catch (\Throwable $e) {
            // Any exception during context creation (not just RuntimeException)
            $msg = $e->getMessage();
            QueryLogger::log('error', 'createContext', 0, $msg);
            return \React\Promise\resolve($drainOnly ? null : new AdminDataLoadedMsg([], [], microtime(true)));
        }

        // Null context means unsupported flavor
        if ($context === null) {
            return \React\Promise\resolve($drainOnly ? null : new AdminDataLoadedMsg([], [], microtime(true)));
        }

        $dsn = $context->connection()->dsn();
        $username = $context->connection()->username();
        $password = $context->password();

        $isPostgres = $context instanceof PostgresServerContext;

        // Reuse a single async connection across ticks (lazy connect — avoids
        // opening a fresh TCP/auth connection every poll).
        $cache = AdminQueryCache::instance();
        $connKey = self::adminConnKey($isPostgres, $dsn, $username, $password);
        try {
            $connection = $cache->connection($connKey, static function () use ($isPostgres, $dsn, $username, $password) {
                return $isPostgres
                    ? new ReactPostgresConnection($dsn, $username, $password)
                    : new ReactMysqlConnection($dsn, $username, $password);
            });
        } catch (\Throwable $e) {
            // A07: the dial doors (empty user / unrecognised sslmode) throw
            // at construction. Honour the method's "wrap entire async flow"
            // intent here too — a refused dial folds into the same safe
            // empty-data resolution the context-failure arm produces, after
            // a logged reason, instead of escaping the tick into the loop.
            $msg = $e->getMessage();
            QueryLogger::log('error', 'adminDial', 0, $msg);

            return \React\Promise\resolve($drainOnly ? null : new AdminDataLoadedMsg([], [], microtime(true)));
        }

        if ($isPostgres) {
            $statusQuery = "SELECT datname, numbackends, xact_commit, xact_rollback, blks_read, blks_hit, tup_returned, tup_fetched, tup_inserted, tup_updated, tup_deleted, conflicts, temp_files, temp_bytes, deadlocks FROM pg_stat_database";
            $serverQuery = 'SELECT name, setting FROM pg_settings';
        } else {
            $statusQuery = 'SHOW GLOBAL STATUS';
            $serverQuery = 'SHOW GLOBAL VARIABLES';
        }

        // Ternary (not if/else around a 80-line literal) so the status/server
        // queries are never even issued in drain mode — only the selected
        // branch of a ternary is evaluated.
        $promises = $drainOnly ? [] : [
            'status' => $connection->query($statusQuery)
                ->then(function(array $rows) use ($isPostgres): array {
                    if ($isPostgres) {
                        // pg_stat_database returns one row per database with columns like
                        // datname, numbackends, xact_commit, blks_read, etc.
                        // Aggregate all rows by summing numeric columns to produce
                        // a single system-wide snapshot the calc engine can read.
                        // WHY: PostgresWidgetCatalog widgets look up keys like
                        // pg_stat_database.tup_fetched (RatePerSecond) or
                        // pg_stat_database.numbackends (StatusVar) directly.
                        // The aggregated keys take the form pg_stat_database.<column>
                        // (e.g. pg_stat_database.tup_fetched, pg_stat_database.numbackends).
                        // NOTE: we do NOT aggregate shared_buffers here (it's not
                        // in pg_stat_database; it comes from pg_settings server vars).
                        $out = [];
                        foreach ($rows as $row) {
                            foreach ($row as $key => $value) {
                                if ($key === 'datname') {
                                    continue; // skip database name identifier
                                }
                                if (is_numeric($value)) {
                                    $out['pg_stat_database.' . $key] = isset($out['pg_stat_database.' . $key])
                                        ? (string) (((int) $out['pg_stat_database.' . $key]) + ((int) $value))
                                        : (string) ((int) $value);
                                }
                            }
                        }
                        return $out;
                    }
                    // MySQL: SHOW GLOBAL STATUS returns Variable_name/Value pairs
                    $out = [];
                    foreach ($rows as $row) {
                        if (isset($row['Variable_name'], $row['Value'])) {
                            $out[(string)$row['Variable_name']] = (string)$row['Value'];
                        }
                    }
                    return $out;
                }),
            'server' => $connection->query($serverQuery)
                ->then(function(array $rows) use ($isPostgres): array {
                    $out = [];
                    if ($isPostgres) {
                        // pg_settings returns name/setting pairs.
                        // PostgreSQL's shared_buffers is expressed in 8KB blocks (the unit
                        // pg uses internally), but the PostgresWidgetCatalog cache panel
                        // displays it with a byte format string (%.0f B).  We therefore
                        // scale it here: shared_buffers value * block_size = bytes.
                        // pg_settings.block_size is 8192 bytes on all currently supported
                        // PG versions; we read it dynamically in case that ever changes.
                        $blockSize = 8192;
                        foreach ($rows as $row) {
                            if (isset($row['name'], $row['setting'])) {
                                $name = (string) $row['name'];
                                $setting = (string) $row['setting'];
                                if ($name === 'block_size' && is_numeric($setting)) {
                                    $blockSize = (int) $setting;
                                }
                            }
                        }
                        foreach ($rows as $row) {
                            if (isset($row['name'], $row['setting'])) {
                                $name = (string) $row['name'];
                                $value = (string) $row['setting'];
                                if ($name === 'shared_buffers' && is_numeric($value)) {
                                    // shared_buffers is stored in 8KB blocks; convert to bytes
                                    $value = (string) (((int) $value) * $blockSize);
                                }
                                $out[$name] = $value;
                            }
                        }
                    } else {
                        foreach ($rows as $row) {
                            if (isset($row['Variable_name'], $row['Value'])) {
                                $out[(string)$row['Variable_name']] = (string)$row['Value'];
                            } elseif (isset($row['name'], $row['setting'])) {
                                $out[(string)$row['name']] = (string)$row['setting'];
                            }
                        }
                    }
                    return $out;
                }),
        ];

        // Drain page-driven queries (process list, replica status, sys reports,
        // availability) requested during the last render. Each is isolated so a
        // single failing query can't sink the batch, and every result — even an
        // empty one — is cached so the next render renders instead of blocking.
        $pending = $cache->takePending();
        if ($drainOnly && $pending === []) {
            // Guard against a race where the queue emptied between the
            // hasPending() check and takePending() — silently resolve, never
            // emit an empty AdminDataLoadedMsg that would wipe cached vars.
            return \React\Promise\resolve(null);
        }
        foreach ($pending as $sql) {
            // client-012 never-reject law, dispatch side: a dead pooled client
            // can throw synchronously from query() — that must mark the leg
            // errored like any other, never escape the arm into silence.
            try {
                $leg = $connection->query($sql);
            } catch (\Throwable $e) {
                $cache->markError($sql, $e->getMessage());
                QueryLogger::log('error', $sql, 0, $e->getMessage());
                continue;
            }
            $promises[] = $leg->then(
                static function(array $rows) use ($cache, $sql): void {
                    $cache->store($sql, $rows);
                    QueryLogger::log('query', $sql, count($rows));
                },
                static function(\Throwable $e) use ($cache, $sql): void {
                    // client-012 (:1328): MARK the leg errored instead of
                    // caching an empty list — a stored [] rendered as an
                    // authoritative "No data" grid on the flagship surfaces.
                    $cache->markError($sql, $e->getMessage());
                    QueryLogger::log('error', $sql, 0, $e->getMessage());
                },
            );
        }

        if ($drainOnly) {
            // client-026: the never-reject law closes here. A throwing leg
            // handler used to reject the whole all(), the promise died in
            // silence, and the tick looped into a 1 s retry with no tell.
            return \React\Promise\all($promises)->then(
                static fn(array $results): Msg => new AdminDrainCompletedMsg(),
            )->otherwise(
                static function(\Throwable $e): Msg {
                    try {
                        QueryLogger::log('error', 'admin drain', 0, $e->getMessage());
                    } catch (\Throwable) {
                        // Logging must not re-break the recovery path.
                    }
                    // The pages still need their re-read signal: stale/absent
                    // cache values will re-pend themselves via lookup().
                    return new AdminDrainCompletedMsg();
                },
            );
        }

        return \React\Promise\all($promises)->then(function(array $results) use ($recorder, $cache): AdminDataLoadedMsg {
            // Record status snapshot to history if recorder is configured
            if ($recorder !== null && isset($results['status'])) {
                $ts = microtime(true);
                $snapshot = new StatusSnapshot($results['status'], $ts);
                $recorder->record($snapshot);
            }

            // Store status and server variables in the shared cache so
            // AsyncCachingServerContext can read them even before the App model
            // is updated with the result.
            if (isset($results['status'])) {
                $cache->storeStatusSnapshot($results['status']);
                QueryLogger::log('status', 'SHOW GLOBAL STATUS', count($results['status']));
            }
            if (isset($results['server'])) {
                $cache->storeServerSnapshot($results['server']);
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
        // D-M3 stale-serving: every failure/stand-down arm of the admin fetch
        // resolves AdminDataLoadedMsg with EMPTY arrays, and SHOW GLOBAL
        // STATUS/VARIABLES never come back legitimately empty from a live
        // server — so an empty leg always means "this fetch produced nothing
        // usable". Overwriting the cache with it wiped a healthy dashboard on
        // one transient hiccup. Keep the previous leg instead (candy-query's
        // AsyncCachingServerContext lesson); the new timestamp still advances
        // the fetch cooldown, so retry cadence is unchanged.
        $status = $statusVars !== [] ? $statusVars : ($this->admin->cachedStatusVars ?? []);
        $server = $serverVars !== [] ? $serverVars : ($this->admin->cachedServerVars ?? []);
        return $this->mutate([
            'admin' => $this->admin->withCachedData($status, $server, $ts),
        ]);
    }

    /**
     * Mark admin data fetch as loading or complete.
     *
     * Unlike the old behaviour (which nulled the admin page on every tick),
     * this preserves the existing page instance so that in-memory state —
     * active tab, cursor position, pending edits — survives a poll-tick
     * refresh. The page reads fresh data from the shared AdminQueryCache on
     * the next render. Only AdminState::withPane() resets the page when the
     * pane changes.
     *
     * @see AdminState::withPane() resets the page when the active pane changes.
     */
    private function withAdminLoading(bool $loading): self
    {
        return $this->mutate(['admin' => $this->admin->withLoading($loading)]);
    }
}
