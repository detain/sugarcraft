<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\Admin\PerfSchema\EasySetupDetector;
use SugarCraft\Sql\Admin\PerfSchema\PerfSchemaPage;
use SugarCraft\Sql\Admin\Resilience\StatementTimeout;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Core\Msg\ReloadReportMsg;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\PreparedStatementInterface;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-P07 frame-economics pins (adminpages-007/-008/-028).
 *
 * The pane used to run its ENTIRE data path — access probe, six setup-table
 * pulls, the detector's up-to-seven server-side aggregate scans, and a
 * ~1,500-node tree parse — on every render frame, silently rolling back
 * uncommitted toggles in the process (an arrival mid-edit does the same in
 * miniature). The batch is now arrival-scoped (one per ReloadReportMsg,
 * the App's admin load/drain signal), the classification rides the loaded
 * rows with zero queries, and the confirmed commit / Easy Setup write loops
 * run per-statement bounded with stop-position reporting.
 */
final class PerfSchemaFrameEconomicsTest extends TestCase
{
    private const INSTRUMENTS_SQL =
        'SELECT `NAME`, `ENABLED`, `TIMED`, `PROPERTIES`, `FLAGS` FROM `performance_schema`.`setup_instruments` WHERE `NAME` NOT LIKE \'memory/%\'';

    private const ACCESS_SQL =
        'SELECT COUNT(*) FROM `performance_schema`.`setup_instruments` WHERE `NAME` NOT LIKE \'memory/%\'';

    private function seededDb(): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->setQueryResult([
            ['NAME' => 'wait/io/file/sql/binlog', 'ENABLED' => 'YES', 'TIMED' => 'YES', 'PROPERTIES' => '', 'FLAGS' => ''],
            ['NAME' => 'statement/abstract/new_packet', 'ENABLED' => 'YES', 'TIMED' => 'YES', 'PROPERTIES' => 'abstract', 'FLAGS' => ''],
        ]);
        return $db;
    }

    private function pageWithDetector(FakeDatabase $db): PerfSchemaPage
    {
        $context = new ServerContext($db);
        return new PerfSchemaPage($context, EasySetupDetector::fromContext($context));
    }

    /** @return array<string,int> every SQL the fake was asked, with counts */
    private function queryLedger(FakeDatabase $db): array
    {
        $prop = new \ReflectionProperty(FakeDatabase::class, 'queryCounts');
        $prop->setAccessible(true);
        /** @var array<string,int> $ledger */
        $ledger = $prop->getValue($db);
        return $ledger;
    }

    private function prop(PerfSchemaPage $page, string $name): mixed
    {
        $p = new \ReflectionProperty(PerfSchemaPage::class, $name);
        $p->setAccessible(true);
        return $p->getValue($page);
    }

    private function key(string $rune): KeyMsg
    {
        return new KeyMsg(type: KeyType::Char, rune: $rune);
    }

    public function testFullDataBatchFiresOncePerArrivalNotPerFrame(): void
    {
        $db = $this->seededDb();
        $page = $this->pageWithDetector($db);

        for ($frame = 0; $frame < 8; $frame++) {
            $page->view();
        }

        $ledger = $this->queryLedger($db);
        $this->assertSame(1, $ledger[self::INSTRUMENTS_SQL] ?? 0, 'the instrument table is pulled once per arrival');
        $this->assertSame(1, $ledger[self::ACCESS_SQL] ?? 0, 'the access probe is memoized per arrival too');
        // 7 loads (access, instruments, consumers, actors, objects, threads,
        // timers) and NOTHING from the detector's aggregate ladder — the old
        // detect() would have added up to seven COUNT/SUM scans per frame.
        $this->assertCount(7, $ledger, 'no detector aggregate queries reached the wire');

        [$reloaded,] = $page->update(new ReloadReportMsg());
        \assert($reloaded instanceof PerfSchemaPage);
        for ($frame = 0; $frame < 4; $frame++) {
            $reloaded->view();
        }

        $this->assertSame(
            2,
            $this->queryLedger($db)[self::INSTRUMENTS_SQL] ?? 0,
            'an arrival re-arms exactly one fresh batch',
        );
    }

    public function testUncommittedToggleSurvivesFramesAndFeedsTheCommitPrompt(): void
    {
        $db = $this->seededDb();
        $page = $this->pageWithDetector($db);
        $page = $page->withTab(PerfSchemaPage::TAB_INSTRUMENTS);
        $page->view();

        [$toggled,] = $page->update(new KeyMsg(KeyType::Char, ' '));
        \assert($toggled instanceof PerfSchemaPage);

        for ($frame = 0; $frame < 3; $frame++) {
            $toggled->view();
        }

        $instruments = $this->prop($toggled, 'instruments');
        $this->assertIsArray($instruments);
        $this->assertFalse($instruments[0]->enabled, 'frames must not roll the toggle back to server truth');

        [$armed,] = $toggled->update($this->key('c'));
        \assert($armed instanceof PerfSchemaPage);
        $pending = $this->prop($armed, 'pendingCommit');
        $this->assertIsArray($pending, 'the staged commit must plan from the surviving toggle');
        $this->assertNotCount(0, $pending);
    }

    public function testArrivalWhileDirtyKeepsTheEdits(): void
    {
        $db = $this->seededDb();
        $page = $this->pageWithDetector($db);
        $page = $page->withTab(PerfSchemaPage::TAB_INSTRUMENTS);
        $page->view();
        [$toggled,] = $page->update(new KeyMsg(KeyType::Char, ' '));
        \assert($toggled instanceof PerfSchemaPage);

        [$held,] = $toggled->update(new ReloadReportMsg());

        $this->assertSame($toggled, $held, 'an arrival may not evaporate uncommitted edits');
        $this->assertTrue($this->prop($held, 'snapshotLoaded'), 'the dirty page keeps its snapshot until commit/revert');
    }

    public function testInstrumentTreeMemoSurvivesNavigationAndDropsOnToggle(): void
    {
        $db = $this->seededDb();
        $page = $this->pageWithDetector($db);
        $page = $page->withTab(PerfSchemaPage::TAB_INSTRUMENTS);

        $page->view();
        $treeA = $this->prop($page, 'treeMemo');
        $page->view();
        $treeB = $this->prop($page, 'treeMemo');
        $this->assertSame($treeA, $treeB, 'the parse is reused across frames');
        $this->assertNotNull($treeA, 'the memo was armed at render');

        [$naved,] = $page->update($this->key('j'));
        \assert($naved instanceof PerfSchemaPage);
        $naved->view();
        $this->assertSame($treeA, $this->prop($naved, 'treeMemo'), 'navigation reuses the memo');

        [$toggled,] = $page->update(new KeyMsg(KeyType::Char, ' '));
        \assert($toggled instanceof PerfSchemaPage);
        $this->assertNull($this->prop($toggled, 'treeMemo'), 'a row mutation invalidates the memo');
    }

    // ─── adminpages-008: classifier equivalence, zero queries ────────────────

    /** @return list<\SugarCraft\Sql\Admin\PerfSchema\SetupInstruments> */
    private function instruments(bool $allOn = true): array
    {
        $rows = [];
        foreach (['wait/io/file/sql/binlog', 'statement/abstract/command'] as $name) {
            $rows[] = \SugarCraft\Sql\Admin\PerfSchema\SetupInstruments::new(
                name: $name,
                enabled: $allOn,
                timed: $allOn,
                properties: '',
                flags: '',
            );
        }
        return $rows;
    }

    /** @return list<\SugarCraft\Sql\Admin\PerfSchema\SetupConsumers> */
    private function consumers(bool $allOn = true): array
    {
        $rows = [];
        foreach (['events_waits_current', 'events_statements_current'] as $name) {
            $rows[] = \SugarCraft\Sql\Admin\PerfSchema\SetupConsumers::new(name: $name, enabled: $allOn);
        }
        return $rows;
    }

    public function testClassifierMirrorsTheLadderFromLoadedRowsWithZeroQueries(): void
    {
        $db = $this->seededDb();
        // Every query throws — if the classifier walked the old ladder it
        // would misread 'disabled' (and the ledger pin below would trip).
        $db->setQueryThrows(new \PDOException('access denied', 1142));
        $detector = EasySetupDetector::fromContext(new ServerContext($db));

        $this->assertSame(
            'fully',
            $detector->classifyFromData($this->instruments(), $this->consumers()),
            'the same rows the old access-error detect() would call disabled still classify client-side',
        );
        $this->assertSame('disabled', $detector->classifyFromData([], $this->consumers()));

        $custom = [
            \SugarCraft\Sql\Admin\PerfSchema\SetupInstruments::new(
                name: 'wait/io/file/sql/binlog',
                enabled: true,
                timed: false, // untimed default row: not fully, and off-profile
                properties: '',
                flags: '',
            ),
            \SugarCraft\Sql\Admin\PerfSchema\SetupInstruments::new(
                name: 'stage/sql/nonexistent',
                enabled: true,
                timed: true,
                properties: '',
                flags: '',
            ),
        ];
        $this->assertSame('custom', $detector->classifyFromData($custom, $this->consumers(false)));

        $this->assertSame(
            0,
            array_sum($this->queryLedger($db)),
            'classification touched the wire zero times',
        );
    }

    public function testDefaultProfileClassificationMatchesTheVersionLists(): void
    {
        $db = new FakeDatabase();
        $detector = EasySetupDetector::fromContext(new ServerContext($db));

        $defaults = (new \ReflectionClass(EasySetupDetector::class))->getConstants();
        $defaultInstruments = $db->serverVersion() >= '5.7'
            ? $defaults['DEFAULT_INSTRUMENTS_57']
            : $defaults['DEFAULT_INSTRUMENTS_56'];
        $defaultConsumers = $db->serverVersion() >= '5.7'
            ? $defaults['DEFAULT_CONSUMERS_57']
            : $defaults['DEFAULT_CONSUMERS_56'];

        // The MySQL default profile: a subset of the default list enabled,
        // everything else on the server disabled — both SUM(IF) legs zero,
        // so the verdict is 'default', NOT 'fully' (percentage < 100).
        $instruments = [];
        $take = array_slice($defaultInstruments, 0, 20);
        foreach ($defaultInstruments as $i => $name) {
            $on = in_array($name, $take, true);
            $instruments[] = \SugarCraft\Sql\Admin\PerfSchema\SetupInstruments::new(
                name: $name,
                enabled: $on,
                timed: $on,
                properties: '',
                flags: '',
            );
            unset($i);
        }
        $instruments[] = \SugarCraft\Sql\Admin\PerfSchema\SetupInstruments::new(
            name: 'wait/synch/extra/notdefault',
            enabled: false,
            timed: false,
            properties: '',
            flags: '',
        );

        $consumers = [];
        foreach ($defaultConsumers as $name) {
            $consumers[] = \SugarCraft\Sql\Admin\PerfSchema\SetupConsumers::new(name: $name, enabled: true);
        }
        $consumers[] = \SugarCraft\Sql\Admin\PerfSchema\SetupConsumers::new(name: 'events_extra_off', enabled: false);

        $this->assertSame('default', $detector->classifyFromData($instruments, $consumers));

        // One extra consumer enabled breaks the profile — off to 'custom'.
        $consumers[0] = \SugarCraft\Sql\Admin\PerfSchema\SetupConsumers::new(name: 'events_extra_off', enabled: true);
        $this->assertSame('custom', $detector->classifyFromData($instruments, $consumers));
    }

    // ─── adminpages-028: bounded commit legs with stop position ──────────────

    /**
     * Scripted connection: the prepared executes either fail or stall on a
     * chosen call number (query/exec otherwise inert so the post-failure
     * re-armed render can still complete its loads).
     */
    private function scriptedDb(int $strike, bool $viaStall): DatabaseInterface
    {
        $db = new class ($strike, $viaStall) implements DatabaseInterface {
            /** @var list<array{sql:string,values:array<mixed>}> */
            public array $executions = [];
            public int $execCalls = 0;

            public function __construct(
                private readonly int $strike,
                private readonly bool $viaStall,
            ) {}

            public function tables(): array { return []; }
            public function rows(string $table, int $limit = 100, int $offset = 0): array { return []; }
            public function query(string $sql): array|null { return []; }
            public function lastInsertId(): string|int { return 0; }
            public function quote(string $value): string { return "'" . $value . "'"; }
            public function exec(string $sql): int
            {
                // Record AFTER the strike check: a statement that threw was
                // never applied, so the prefix count stays honest.
                $this->execCalls++;
                if ($this->execCalls === $this->strike) {
                    if ($this->viaStall) {
                        usleep(3_000_000); // real pcntl alarm must cut this off
                    }
                    throw new \PDOException('boom on strike');
                }
                $this->executions[] = ['sql' => $sql, 'values' => []];
                return 0;
            }
            public function close(): void {}
            public function serverVersion(): string { return '8.0.32'; }
            public function driverName(): string { return 'mysql'; }
            public function ping(): bool { return true; }
            public function databases(): array { return []; }
            public function prepare(string $sql): ?PreparedStatementInterface
            {
                return new class ($sql, function () use ($sql): void {
                    $this->strikePrepared($sql);
                }) implements PreparedStatementInterface {
                    public function __construct(
                        private readonly string $sql,
                        private readonly \Closure $run,
                    ) {}

                    public function execute(?array $params = null): bool
                    {
                        ($this->run)();
                        return true;
                    }
                    public function fetch(): array|false { return false; }
                    public function fetchAll(): array { return []; }
                    public function rowCount(): int { return 0; }
                    public function closeCursor(): bool { return true; }
                };
            }
            /** @internal strike logic for prepared legs, kept on the db. */
            public function strikePrepared(string $sql): void
            {
                $strike = count($this->executions) + 1;
                if ($strike === $this->strike) {
                    if ($this->viaStall) {
                        usleep(3_000_000);
                    }
                    throw new \PDOException('boom on strike');
                }
                $this->executions[] = ['sql' => $sql, 'values' => []];
            }
            public function dsn(): string { return 'mysql:host=h;dbname=d'; }
            public function username(): string { return 'u'; }
        };
        return $db;
    }

    /**
     * @param list<string> $sqls
     */
    private function stageCommit(PerfSchemaPage $page, array $sqls): PerfSchemaPage
    {
        // Arm the session state cold so [y] goes straight to the batch: a
        // lazy loadData here would run the privilege probe's exec through
        // the same scripted db and pollute the prefix ledger.
        foreach ([['updatePrivilegeMemo', true], ['snapshotLoaded', true]] as [$name, $value]) {
            $p = new \ReflectionProperty(PerfSchemaPage::class, $name);
            $p->setAccessible(true);
            $p->setValue($page, $value);
        }
        $pending = array_map(
            static fn (string $sql): array => ['sql' => $sql, 'params' => []],
            $sqls,
        );
        $prop = new \ReflectionProperty(PerfSchemaPage::class, 'pendingCommit');
        $prop->setAccessible(true);
        $prop->setValue($page, $pending);
        return $page;
    }

    public function testPartialCommitNamesTheStopPositionAndReplacesTheDisplay(): void
    {
        $db = $this->scriptedDb(2, false);
        $page = $this->stageCommit(
            new PerfSchemaPage(new ServerContext($db)),
            ['UPDATE a', 'UPDATE b', 'UPDATE c'],
        );

        [$done,] = $page->update($this->key('y'));
        \assert($done instanceof PerfSchemaPage);

        $this->assertSame(1, count($db->executions), 'only the prefix before the failure ran');
        $error = (string) $this->prop($done, 'errorMessage');
        $this->assertStringContainsString('at statement 2 of 3', $error);
        $this->assertStringContainsString('partial', $error);
        $this->assertNull($this->prop($done, 'pendingCommit'));
        $this->assertFalse(
            (bool) $this->prop($done, 'snapshotLoaded'),
            'the applied prefix re-arms the gate — the next render must show real server state',
        );
    }

    public function testStalledCommitLegIsCutByTheBudgetInsteadOfFreezingForever(): void
    {
        if (!extension_loaded('pcntl') || !function_exists('pcntl_alarm')) {
            $this->markTestSkipped('the alarm seam needs pcntl');
        }

        $db = $this->scriptedDb(2, true);
        $page = $this->stageCommit(
            new PerfSchemaPage(new ServerContext($db), null, null, new StatementTimeout(1)),
            ['UPDATE a', 'UPDATE b', 'UPDATE c'],
        );

        $started = microtime(true);
        [$done,] = $page->update($this->key('y'));
        $elapsed = microtime(true) - $started;
        \assert($done instanceof PerfSchemaPage);

        $this->assertLessThan(3.0, $elapsed, 'the 2 s stall is cut at the 0.5 s budget');
        $error = (string) $this->prop($done, 'errorMessage');
        $this->assertStringContainsString('at statement 2 of 3', $error);
        $this->assertStringContainsString('timed out', $error);
    }

    public function testEasySetupBatchReportsWhereItStopped(): void
    {
        // The '[' action runs the enable-full preset through exec(); the
        // scripted db strikes the SECOND batch statement. The privilege memo
        // is pre-armed true so the WHERE 1=0 probe never consumes the strike.
        $db = $this->scriptedDb(2, false);
        $page = new PerfSchemaPage(new ServerContext($db));

        foreach ([['updatePrivilegeMemo', true], ['snapshotLoaded', true]] as [$name, $value]) {
            $p = new \ReflectionProperty(PerfSchemaPage::class, $name);
            $p->setAccessible(true);
            $p->setValue($page, $value);
        }

        [$after,] = $page->update($this->key('['));
        \assert($after instanceof PerfSchemaPage);

        $error = (string) $this->prop($after, 'errorMessage');
        $this->assertMatchesRegularExpression('/Easy Setup failed at statement \d+ of \d+/', $error);
        $this->assertStringContainsString('boom on strike', $error);
        $this->assertStringContainsString('partial', $error);
        $this->assertCount(1, $db->executions, 'exactly the prefix before the strike applied');
        $this->assertFalse((bool) $this->prop($after, 'snapshotLoaded'), 'failure re-arms the arrival gate');
    }
}
