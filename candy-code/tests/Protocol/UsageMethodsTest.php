<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\ScriptedTurnBackend;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Usage;

/**
 * C-SES-8.a: `usage.rollup` over the wire.
 *
 * The method is the ledger's READ side on the RPC surface, so the pins are
 * the three things the design says a client must never get wrong about:
 * the scope split (session narrows, all spans), the `unpriced` polarity
 * (a folded unpriced row turns every dollar figure into a LOWER BOUND and
 * says so in the answer), and the no-store case (empty folds WITH a stated
 * reason — "nothing was spent" and "nothing can be seen" are different
 * answers). Totals ride as a derivation of the per-model fold, which is
 * what keeps grid and totals from straddling a settle; retention is the
 * launch's effective setting read-only, this method opens no write path.
 *
 * Harness shape is the {@see ShellsMethodsTest} inline-construction
 * precedent: a real {@see EnhancedSessionStore} on a temp path, seeded
 * through its one append site, and a {@see WireClient} on a real
 * {@see Dispatcher} — the same read path `/usage` takes, one method away.
 */
final class UsageMethodsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir = '';

    private ?EnhancedSessionStore $store = null;

    private SessionHub $hub;

    private ServerContext $context;

    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = \sys_get_temp_dir() . '/crush-usage-methods-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir . '/project', 0o700, true);
        $this->useHomeSandbox($this->dir . '/home');

        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->serve($this->store);
    }

    protected function tearDown(): void
    {
        $this->dispatcher->stop('test over');
        $this->hub->closeAll();
        \putenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        $this->restoreHomeSandbox();
        ProtocolFixture::removeTree($this->dir);

        parent::tearDown();
    }

    public function testTheMethodIsAdvertisedWithTheScopeTheDesignNames(): void
    {
        $spec = Dispatcher::methods()->get('usage.rollup');
        self::assertNotNull($spec, 'usage.rollup is on the roster');
        self::assertSame(Scope::Read, $spec->scope, 'a fold of the ledger is a read');
        self::assertFalse($spec->sideEffects, 'the method opens no write path');
    }

    public function testSessionScopeNarrowsToTheNamedLedger(): void
    {
        $this->seed('s1', 120, 0.5, 'claude-sonnet-4-5');
        $this->seed('s2', 800, 2.25, 'gpt-5');

        $answer = $this->client()->call('usage.rollup', ['sessionId' => 's1', 'scope' => 'session']);

        self::assertSame([['model' => 'claude-sonnet-4-5', 'calls' => 1, 'totalTokens' => 120, 'costUsd' => 0.5, 'unpriced' => false]], $answer['models'], 'the fold names only this session\'s model');
        self::assertSame(['calls' => 1, 'totalTokens' => 120, 'costUsd' => 0.5], $answer['totals'], 'totals are the model fold summed, not a second read');
        self::assertNotEmpty($answer['days'], 'the day grid rides the same snapshot');
        foreach ($answer['days'] as $row) {
            self::assertSame(['day', 'model', 'calls', 'totalTokens', 'costUsd', 'unpriced'], \array_keys($row), 'the published day row is exactly the closed schema shape');
        }
        self::assertSame('', $answer['reason'], 'a real answer carries no reason');
        self::assertFalse($answer['unpriced'], 'priced rows leave the figures exact');
    }

    public function testAllScopeSpansEverySessionInTheLedger(): void
    {
        $this->seed('s1', 120, 0.5, 'claude-sonnet-4-5');
        $this->seed('s2', 800, 2.25, 'gpt-5');

        $answer = $this->client()->call('usage.rollup', ['scope' => 'all']);

        $models = \array_column($answer['models'], 'model');
        \sort($models);
        self::assertSame(['claude-sonnet-4-5', 'gpt-5'], $models, 'scope "all" folds both sessions');
        self::assertSame(['calls' => 2, 'totalTokens' => 920, 'costUsd' => 2.75], $answer['totals']);
    }

    public function testAFoldedUnpricedRowTurnsTheDollarsIntoALowerBound(): void
    {
        $this->seed('s1', 120, 0.5, 'claude-sonnet-4-5');
        $store = $this->store;
        self::assertInstanceOf(EnhancedSessionStore::class, $store);
        self::assertTrue($store->appendUsageRecord('s1', Usage::new(totalTokens: 400, costUsd: 0.0, unpricedModel: 'brand-new-model'), 'brand-new-model'));

        $answer = $this->client()->call('usage.rollup', ['sessionId' => 's1', 'scope' => 'all']);

        self::assertTrue($answer['unpriced'], 'any unpriced row in either fold sets the bound bit');
        self::assertSame(['calls' => 2, 'totalTokens' => 520, 'costUsd' => 0.5], $answer['totals'], 'the dollars stay 0 for the unpriced call — the flag, not an invented rate, carries the gap');
        $byModel = [];
        foreach ($answer['models'] as $row) {
            $byModel[(string) $row['model']] = (bool) $row['unpriced'];
        }
        self::assertEquals(['claude-sonnet-4-5' => false, 'brand-new-model' => true], $byModel, 'each fold row keeps its own bit');
    }

    public function testNoStoreAnswersEmptyFoldsWithAStatedReason(): void
    {
        $this->serve(null);

        $answer = $this->client()->call('usage.rollup', ['scope' => 'all']);

        self::assertSame([], $answer['days']);
        self::assertSame([], $answer['models']);
        self::assertSame('no durable ledger store is open here', $answer['reason'], '"nothing can be seen" is not the same answer as "nothing was spent"');
        self::assertFalse($answer['unpriced']);
    }

    public function testTheWindowIsSevenOrThirtyAndAnythingElseIsRefused(): void
    {
        $this->seed('s1', 120, 0.5, 'claude-sonnet-4-5');
        $client = $this->client();

        foreach ([7, 30] as $window) {
            $answer = $client->call('usage.rollup', ['sessionId' => 's1', 'scope' => 'session', 'days' => $window]);
            self::assertSame(1, $answer['totals']['calls'], 'a whole-UTC-day window still counts today');
        }

        $offWindow = $client->request('usage.rollup', ['sessionId' => 's1', 'scope' => 'session', 'days' => 15]);
        self::assertSame('invalid_params', $offWindow['error']['data']['kind']);
        self::assertStringContainsString('days', $offWindow['error']['message'], 'the refusal names the field and never folds a nonsense window into the whole ledger');

        $asString = $client->request('usage.rollup', ['sessionId' => 's1', 'scope' => 'session', 'days' => '7']);
        self::assertSame('invalid_params', $asString['error']['data']['kind'], 'the string "7" is not the integer 7');
    }

    public function testSessionScopeWithoutAnOwnerKeyIsRefused(): void
    {
        $refused = $this->client()->request('usage.rollup', ['scope' => 'session']);

        self::assertSame('invalid_params', $refused['error']['data']['kind']);
        self::assertStringContainsString('sessionId', $refused['error']['message'], 'scope "session" demands the id it narrows to');

        $noScope = $this->client()->request('usage.rollup', []);
        self::assertSame('invalid_params', $noScope['error']['data']['kind'], 'scope itself is required, not defaulted');
        self::assertStringContainsString('scope', $noScope['error']['message']);
    }

    public function testRetentionRidesTheAnswerReadOnly(): void
    {
        \putenv('CANDY_CODE_SESSION_RETENTION_DAYS=45');
        try {
            $answer = $this->client()->call('usage.rollup', ['scope' => 'all']);
            self::assertSame(45, $answer['retentionDays'], 'the launch\'s effective setting travels with every answer');
            self::assertSame(
                ['days', 'models', 'totals', 'unpriced', 'retentionDays', 'reason'],
                \array_keys($answer),
                'the published shape is exactly the six keys the schema closes on',
            );
        } finally {
            \putenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        }
    }

    // --- harness -----------------------------------------------------------

    private function seed(string $sessionId, int $tokens, float $cost, string $model): void
    {
        $store = $this->store;
        self::assertInstanceOf(EnhancedSessionStore::class, $store);
        self::assertTrue($store->appendUsageRecord($sessionId, Usage::new(totalTokens: $tokens, costUsd: $cost), $model));
    }

    /** @param EnhancedSessionStore|null $store null is the "host without a durable ledger" shape */
    private function serve(?EnhancedSessionStore $store): void
    {
        if (isset($this->dispatcher)) {
            $this->dispatcher->stop('serving a different workspace');
            $this->hub->closeAll();
        }

        $workspace = WorkspaceContext::new(
            root: $this->dir . '/project',
            sessionStore: $store,
            backend: new ScriptedTurnBackend(),
            backendFactory: static fn (string $provider): Backend => EngineBackend::new(new ScriptedProvider([]), 'stale-' . $provider),
        );

        $config = ServerConfig::new($this->dir);
        $this->hub = SessionHub::new($workspace, $config->maxOpenSessions);
        $this->context = ServerContext::new($this->hub, $config->withRoot($this->dir . '/project'), 'test', Loop::get());
        $this->dispatcher = Dispatcher::new($this->context);
    }

    private function client(): WireClient
    {
        $client = WireClient::open($this->dispatcher, 'c1');
        $client->hello();

        return $client;
    }
}
