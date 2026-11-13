<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\CancellationToken;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\AsyncConnection;
use SugarCraft\Sql\Admin\CancellableQuery;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Core\Msg\QueryRowsLoadedMsg;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Lang;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-O08 pins: in-app cancel wired to the real per-dispatch tokens.
 *
 * Before this row the whole two-sided cancel API (CancellableQuery + the
 * React connections' KILL/CancelRequest legs) was fully built with ZERO
 * production wiring: an in-flight query simply ran to its deadline with no
 * user escape. These legs pin the wiring — the handle arms on dispatch,
 * Escape/Ctrl+C fire it while a promise is live, the rejection folds back
 * as the named cancelled line with the app alive, quit-on-idle is
 * byte-identical to the old contract, and the open-line owners keep
 * Escape priority over the leg behind them.
 *
 * The fake connection wraps its deferred leg through the PRODUCTION
 * CancellableQuery bridge, so the cancel path exercises the same
 * reject-on-token semantics the React drivers carry (the server-side KILL
 * lifecycle itself is A04's own pinned territory; the live-DSN acceptance
 * leg stays opt-in per the C05 door).
 */
final class InFlightCancelTest extends TestCase
{
    private const SQL = 'SELECT 1';

    /** Key the fetch legs derive for FakeDatabase (dsn '' user '' pass ''). */
    private const CONN_KEY = 'mysql|||e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    protected function setUp(): void
    {
        parent::setUp();
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
        parent::tearDown();
    }

    /**
     * An async leg with a settle handle + the token the production caller
     * passed down (null when the wiring is missing — the can-fail seam).
     */
    private function seedLegConnection(object $capture): void
    {
        $connection = new class ($capture) implements AsyncConnection {
            public function __construct(private object $capture)
            {
            }

            public function query(string $sql, ?CancellationToken $cancellation = null): \React\Promise\PromiseInterface
            {
                $this->capture->tokens[] = $cancellation;
                $this->capture->leg ??= new Deferred();
                if (count($this->capture->tokens) > 1) {
                    // Browse legs issue a second query after the column
                    // probe; reuse the same leg handle so the recorder sees
                    // one settle per cancellation.
                    $this->capture->legs[] = $this->capture->leg;
                }

                return CancellableQuery::wrap($this->capture->leg->promise(), $cancellation);
            }

            public function close(): void
            {
            }
        };
        AdminQueryCache::instance()->connection(self::CONN_KEY, static fn (): AsyncConnection => $connection);
    }

    private function queryApp(): App
    {
        return App::builder()
            ->withDb(new FakeDatabase())
            ->withFlavor(Flavor::MySQL)
            ->withPane(Pane::Query)
            ->withQueryEditor(TextArea::new()->setValue(self::SQL))
            ->build();
    }

    private function browseApp(): App
    {
        return App::builder()
            ->withDb(new FakeDatabase())
            ->withFlavor(Flavor::MySQL)
            ->withTables(['widgets'])
            ->withSelectedTable('widgets')
            ->build();
    }

    private function pressRun(App $app): array
    {
        return $app->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
    }

    public function testTheAsyncRunArmsTheHandleAndSendsTheTokenDown(): void
    {
        $capture = new \stdClass();
        $capture->tokens = [];
        $this->seedLegConnection($capture);

        [$model, $cmd] = $this->pressRun($this->queryApp());

        self::assertNotNull($cmd);
        self::assertNotNull($model->ui->inFlight, 'an in-flight leg must own a cancel handle');

        $async = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $async);
        self::assertCount(1, $capture->tokens);
        self::assertNotNull($capture->tokens[0], 'the connection leg must receive the token, not null');
        self::assertFalse($capture->tokens[0]->isCancelled());
    }

    public function testEscapeCancelsTheInFlightQueryAndFoldsTheNamedLine(): void
    {
        $capture = new \stdClass();
        $capture->tokens = [];
        $this->seedLegConnection($capture);

        [$model, $cmd] = $this->pressRun($this->queryApp());
        $outcome = null;
        $cmd()->promise->then(static function (mixed $msg) use (&$outcome): void {
            $outcome = $msg;
        });

        [$cancelled, $pressCmd] = $model->update(new KeyMsg(KeyType::Escape));

        self::assertNull($pressCmd, 'the cancel press must not quit the app');
        self::assertNull($cancelled->ui->inFlight, 'the handle retires the moment it fires');
        self::assertTrue($capture->tokens[0]->isCancelled(), 'the token rides to the driver leg');
        self::assertInstanceOf(QueryRowsLoadedMsg::class, $outcome, 'the never-reject law still answers a Msg');
        self::assertSame(Lang::t('query.cancelled'), $outcome->error);

        [$folded, $foldCmd] = $cancelled->update($outcome);
        self::assertNull($foldCmd);
        self::assertSame(Lang::t('query.cancelled'), $folded->ui->error, 'the user sees the named cancelled line');
        self::assertFalse($folded->browse->rowsLoading, 'the spinner cannot wedge behind a cancel');
    }

    public function testCtrlCCancelsWhileALegLivesAndQuitsOnIdle(): void
    {
        $capture = new \stdClass();
        $capture->tokens = [];
        $this->seedLegConnection($capture);

        [$model, $cmd] = $this->pressRun($this->queryApp());
        $cmd();

        [$cancelled, $pressCmd] = $model->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        self::assertNull($pressCmd, 'Ctrl+C while a promise lives is a cancel, not a quit');
        self::assertTrue($capture->tokens[0]->isCancelled());
        self::assertNull($cancelled->ui->inFlight);

        // The documented idle contract: second press (nothing in flight) quits.
        [$idle, $quitCmd] = $cancelled->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        self::assertNotNull($quitCmd, 'quit-on-idle is exactly as the README documents');
        self::assertSame($idle, $cancelled, 'the idle Ctrl+C changes no state');
    }

    public function testEscapeWhenIdleIsUnchangedQuitBehaviour(): void
    {
        // Pane-level Escape on Tables/Rows still quits when nothing is in
        // flight — the cancel intercept must not swallow the pane chord.
        [$model, $cmd] = $this->browseApp()->update(new KeyMsg(KeyType::Escape));

        self::assertNull($model->ui->inFlight);
        self::assertNotNull($cmd, 'the documented browser-pane quit stays wired');
    }

    public function testTheOpenFilterLineKeepsEscapePriorityOverTheLeg(): void
    {
        $capture = new \stdClass();
        $capture->tokens = [];
        $this->seedLegConnection($capture);

        [$loaded, $cmd] = $this->browseApp()->update(new KeyMsg(KeyType::Enter));
        self::assertNotNull($loaded->ui->inFlight, 'the browse load leg arms the handle too');
        $cmd();

        [$filtering, ] = $loaded->update(new KeyMsg(KeyType::Char, '/'));
        self::assertTrue($filtering->browse->filterMode);

        [$closedLine, $firstCmd] = $filtering->update(new KeyMsg(KeyType::Escape));
        self::assertFalse($closedLine->browse->filterMode, 'the line owns Escape');
        self::assertNull($firstCmd, 'closing the line must not quit either');
        self::assertNotNull($closedLine->ui->inFlight, 'the leg behind the line survives untouched');
        self::assertFalse($capture->tokens[0]->isCancelled());

        [$cancelled, ] = $closedLine->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cancelled->ui->inFlight, 'the next Escape now reaches the leg');
        self::assertTrue($capture->tokens[0]->isCancelled());
    }

    public function testSupersedingDispatchCancelsTheOutgoingLeg(): void
    {
        $capture = new \stdClass();
        $capture->tokens = [];
        $this->seedLegConnection($capture);

        [$first, $firstCmd] = $this->pressRun($this->queryApp());
        $firstCmd();
        $sourceA = $first->ui->inFlight;
        self::assertInstanceOf(CancellationSource::class, $sourceA);

        [$second, $secondCmd] = $this->pressRun($first);
        $secondCmd();

        self::assertTrue($sourceA->isCancelled(), 'a superseded leg is cancelled at dispatch, not left to the deadline');
        self::assertNotNull($second->ui->inFlight);
        self::assertNotSame($sourceA, $second->ui->inFlight, 'each dispatch mints its own handle');
    }

    public function testAStaleFoldLeavesTheCurrentHandleAlone(): void
    {
        $capture = new \stdClass();
        $capture->tokens = [];
        $this->seedLegConnection($capture);

        [$first, $firstCmd] = $this->pressRun($this->queryApp());
        $firstCmd();
        [$second, $secondCmd] = $this->pressRun($first);
        $secondCmd();

        // The gen-A reply arrives AFTER dispatch B — C12 drops it, and the
        // drop must not retire B's handle with it.
        [$folded, ] = $second->update(new QueryRowsLoadedMsg(self::SQL, [], 'late', $first->query->queryGen));

        self::assertNotNull($folded->ui->inFlight, 'a stale fold cannot disarm the live leg');
    }
}
