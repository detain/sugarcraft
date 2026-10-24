<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\AsyncConnection;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\Core\Msg\AdminDataLoadedMsg;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * Behaviour pins for the TLS gate (App::asyncTransportRefusedByTls()).
 *
 * A TLS-verifying ssl-mode must never run over the ReactPHP `mysql://`
 * transport, which carries no TLS material. Under require/verify_* the app
 * refuses the async transport: queries and table loads fall back to the
 * synchronous PDO path, the admin live-refresh tick stands down, and
 * createAdminFetchPromise() resolves the empty settled shape without ever
 * touching the connection factory. Under the plaintext-by-agreement modes
 * the async machinery must behave EXACTLY as before — both polarities are
 * pinned here so a mutation in either direction goes red by name.
 *
 * MysqlDatabase is final with a private constructor, so the mode rides the
 * explicit App::start(..., sslMode:) seam (the documented test hook).
 */
final class AdminTlsGateTest extends TestCase
{
    /** @var list<string> */
    private array $seenSql = [];

    protected function setUp(): void
    {
        parent::setUp();
        AdminQueryCache::reset();
        $this->seenSql = [];
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
        parent::tearDown();
    }

    private function appWithMode(string $sslMode, ?FakeDatabase $db = null): App
    {
        return App::start($db ?? new FakeDatabase(), Flavor::MySQL, null, $sslMode);
    }

    /** @return array{0: App, 1: ?\Closure} */
    private function invokePrivate(App $a, string $method, mixed ...$args): array
    {
        return (new \ReflectionMethod($a, $method))->invoke($a, ...$args);
    }

    private function fetchOutcome(App $a, bool $drainOnly = false): mixed
    {
        $outcome = null;
        /** @var PromiseInterface $promise */
        $promise = (new \ReflectionMethod($a, 'createAdminFetchPromise'))->invoke($a, null, $drainOnly);
        $promise->then(static function (mixed $msg) use (&$outcome): void {
            $outcome = $msg;
        });
        return $outcome;
    }

    /** Drive a fresh App into the Admin pane (Tab cycle Tables→Rows→Query→Admin). */
    private function enterAdmin(App $a): App
    {
        for ($i = 0; $i < 3; $i++) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        }
        return $a;
    }

    /** Register a fake async connection under the key the fetch derives for FakeDatabase. */
    private function seedFakeConnection(): void
    {
        $test = $this;
        AdminQueryCache::instance()->connection('mysql||', function () use ($test): AsyncConnection {
            return new class ($test) implements AsyncConnection {
                public function __construct(private readonly AdminTlsGateTest $test) {}

                public function query(string $sql, ?\SugarCraft\Async\CancellationToken $cancellation = null): PromiseInterface
                {
                    $this->test->recordSql($sql);
                    return \React\Promise\resolve([['Id' => '1']]);
                }
            };
        });
    }

    public function recordSql(string $sql): void
    {
        $this->seenSql[] = $sql;
    }

    private function slotContents(): ?AsyncConnection
    {
        $property = new \ReflectionProperty(AdminQueryCache::class, 'connection');

        return $property->getValue(AdminQueryCache::instance());
    }

    public function testVerifiedModeArmsTheStandingStatusLine(): void
    {
        $refused = $this->appWithMode('require');
        $this->assertNotNull($refused->ui->status);
        $this->assertStringContainsString('admin live-refresh disabled', (string) $refused->ui->status);

        foreach (['', 'disable', 'allow', 'prefer'] as $asyncCapable) {
            $this->assertNull(
                $this->appWithMode($asyncCapable)->ui->status,
                "ssl-mode '$asyncCapable' must leave the app exactly as it was",
            );
        }
    }

    public function testAdminTickSubscriptionStandsDownUnderVerifiedTls(): void
    {
        $refused = $this->enterAdmin($this->appWithMode('verify-ca'));
        $allowed = $this->enterAdmin($this->appWithMode('prefer'));

        $this->assertNull(
            $refused->subscriptions(),
            'a verified mode must not even arm the admin-fetch tick — no tick, no dial',
        );
        $this->assertNotNull(
            $allowed->subscriptions(),
            'the opportunistic modes keep the admin tick (async path unchanged)',
        );
    }

    public function testRefusedAdminFetchSettlesEmptyAndNeverBuildsAConnection(): void
    {
        $a = $this->appWithMode('require');

        $outcome = $this->fetchOutcome($a);

        $this->assertInstanceOf(AdminDataLoadedMsg::class, $outcome);
        $this->assertSame([], $outcome->statusVars);
        $this->assertSame([], $outcome->serverVars);
        $this->assertNull(
            $this->slotContents(),
            'the refusal must precede connection() — a ReactMysqlConnection is never even constructed',
        );
    }

    public function testRefusedAdminFetchIgnoresEvenASeededTransport(): void
    {
        $this->seedFakeConnection();
        $a = $this->appWithMode('verify_identity');

        $this->fetchOutcome($a);
        $this->fetchOutcome($a, drainOnly: true);

        $this->assertSame([], $this->seenSql, 'no SQL reaches the async transport under a verified mode');
    }

    public function testAllowedAdminFetchStillDialsTheAsyncTransportUnchanged(): void
    {
        $this->seedFakeConnection();
        $a = $this->appWithMode('prefer');

        $this->fetchOutcome($a);

        $this->assertSame(
            ['SHOW GLOBAL STATUS', 'SHOW GLOBAL VARIABLES'],
            $this->seenSql,
            'unencrypted-by-agreement modes keep the async admin fetch byte-for-byte as before',
        );
    }

    public function testRunQueryFallsBackToTheSyncTransportUnderVerifiedTls(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([['id' => 1]]);
        $a = App::builder()
            ->withDb($db)
            ->withFlavor(Flavor::MySQL)
            ->withSslMode('require')
            ->withQueryEditor(TextArea::new()->setValue('SELECT 1'))
            ->build();

        [$a, $cmd] = $this->invokePrivate($a, 'beginRunQuery');

        $this->assertNull($cmd, 'a verified mode runs the query synchronously — no promise dispatched');
        $this->assertSame(1, $db->queryCount('SELECT 1'), 'the sync PDO connection ran it exactly once');

        // Polarity: async-capable mode still dispatches the promise command.
        $dbAsync = new FakeDatabase();
        $aAsync = App::builder()
            ->withDb($dbAsync)
            ->withFlavor(Flavor::MySQL)
            ->withSslMode('prefer')
            ->withQueryEditor(TextArea::new()->setValue('SELECT 1'))
            ->build();

        [, $cmdAsync] = $this->invokePrivate($aAsync, 'beginRunQuery');

        $this->assertNotNull($cmdAsync, 'the opportunistic modes keep the async query path');
        $this->assertSame(0, $dbAsync->queryCount('SELECT 1'), 'and nothing hits the sync handle');
    }

    public function testLoadTableFallsBackToTheSyncTransportUnderVerifiedTls(): void
    {
        $a = $this->appWithMode('required');

        [$a, $cmd] = $this->invokePrivate($a, 'beginLoadTable', 'users');

        $this->assertNull($cmd, 'browse loads ride the sync PDO path under a verified mode');
        $this->assertFalse($a->browse->rowsLoading);

        $allowed = $this->appWithMode('');
        [, $cmdAsync] = $this->invokePrivate($allowed, 'beginLoadTable', 'users');
        $this->assertNotNull($cmdAsync, 'with TLS not required the browse load stays async');
    }

    public function testConnectionStateCarriesTheSslMode(): void
    {
        $s = ConnectionState::new($this->createStub(DatabaseInterface::class), Flavor::MySQL);
        $this->assertSame('', $s->sslMode, 'unset defaults to the async-capable empty mode');

        $t = $s->withSslMode('verify-ca');
        $this->assertNotSame($s, $t, 'ConnectionState stays immutable');
        $this->assertSame('verify-ca', $t->sslMode);
        $this->assertSame('', $s->sslMode);
    }
}
