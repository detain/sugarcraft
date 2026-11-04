<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Methods\ServerMethods;
use SugarCraft\Code\Protocol\Methods\TokenMethods;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\RateLimiter;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Auth\ViewerTokens;
use SugarCraft\Code\Server\Http\ApiController;
use SugarCraft\Code\Server\Http\AuthMiddleware;
use SugarCraft\Code\Server\Http\HostAndOriginGuard;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Support\PrivateRetainedDir;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * The viewer-token store (X-DESIGN1 D-1, reviews-1103 G4-G): hashed at rest,
 * raw never persisted nor logged, the cap REFUSES rather than evicts, expiry
 * is absolute, and every revocation path — explicit, sweep, rotation — holds
 * on disk as loudly as it does in memory. The second half is the admission
 * door (D-4): a matching `cv1_` carries the `viewer` principal and its grant
 * attributes before the limiter, and a wrong one is indistinguishable from
 * every other failed credential.
 */
final class TokenScopeTest extends TestCase
{
    private float $now = 1_700_000_000.0;

    private string $dir = '';

    /** @var list<string> extra state dirs tests create outside $this->dir */
    private array $extraDirs = [];

    /** @var list<string> lines the store's log closure was handed */
    private array $notes = [];

    /** @var list<string> lines the AuthMiddleware log closure was handed */
    private array $mwLogLines = [];

    private ?ServerRequestInterface $mwPassed = null;

    private ?ProtocolFixture $wireFixture = null;

    private ?Dispatcher $myDispatcher = null;

    private int $noteCount = 0;

    /**
     * The G4-G-ADDENDUM harness: a store-backed dispatcher for the wired
     * token.* handlers (mirrors how Serve::protocol() threads one shared
     * AuthContext's store onto the protocol workspace).
     */
    private ?ProtocolFixture $tokenFixture = null;

    private ?Dispatcher $tokenDispatcher = null;

    private ?ViewerTokens $tokenStore = null;

    protected function setUp(): void
    {
        $this->now = 1_700_000_000.0;
        $this->dir = (string) \sys_get_temp_dir() . '/crush-g4g-' . \bin2hex(\random_bytes(6));
        $this->notes = [];
        $this->mwLogLines = [];
        $this->mwPassed = null;
    }

    protected function tearDown(): void
    {
        if ($this->myDispatcher !== null) {
            $this->myDispatcher->stop('over');
            $this->myDispatcher = null;
        }

        if ($this->tokenDispatcher !== null) {
            $this->tokenDispatcher->stop('over');
            $this->tokenDispatcher = null;
        }

        if ($this->tokenFixture !== null) {
            $this->tokenFixture->tearDown();
            $this->tokenFixture = null;
        }

        if ($this->wireFixture !== null) {
            $this->wireFixture->tearDown();
            $this->wireFixture = null;
        }

        foreach ([$this->dir, ...$this->extraDirs] as $victim) {
            if (!\is_dir($victim)) {
                continue;
            }
            foreach ((array) \scandir($victim) as $entry) {
                if ($entry !== '.' && $entry !== '..' && \is_file($victim . '/' . $entry)) {
                    @\unlink($victim . '/' . $entry);
                }
            }
            @\rmdir($victim);
        }
    }

    private function tokens(): TokenStore
    {
        return TokenStore::new($this->dir);
    }

    private function store(TokenStore $tokens): ViewerTokens
    {
        $log = function (string $line): void {
            $this->notes[] = $line;
        };

        return ViewerTokens::persisted($tokens, fn (): float => $this->now, $log);
    }

    public function testGrantedTokenResolvesAndTheRawValueNeverLandsOnDisk(): void
    {
        $store = $this->store($this->tokens());
        $grant = $store->grant('phone watch');

        self::assertStringStartsWith(ViewerTokens::PREFIX, $grant['token']);
        self::assertSame(44, \strlen($grant['token']), 'cv1_ plus 40 hex');
        self::assertTrue(\ctype_xdigit(\substr($grant['token'], 4)));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $grant['id']);
        self::assertSame(['read'], $grant['scopes'], 'the v1 grant is read-only, no exceptions');
        self::assertNull($grant['sessionIds'], 'an unpinned grant reaches every session');
        self::assertSame($grant['expiresAt'] - $grant['createdAt'], 30 * 86400.0, 'the default life is 30 days');

        $row = $store->match($grant['token']);
        self::assertNotNull($row);
        self::assertSame($grant['id'], $row['id']);
        self::assertSame(['read'], $row['scopes']);
        self::assertArrayNotHasKey('hash', $row, 'the durable key never rides out to callers');
        self::assertArrayNotHasKey('token', $row);

        $bytes = (string) \file_get_contents($this->dir . '/' . ViewerTokens::FILE);
        self::assertStringContainsString(\hash('sha256', $grant['token']), $bytes, 'what lands is the digest');
        self::assertStringNotContainsString($grant['token'], $bytes, 'the raw token is never persisted');

        foreach ($store->list() as $listed) {
            self::assertArrayNotHasKey('hash', $listed);
            self::assertArrayNotHasKey('token', $listed);
        }
        self::assertStringNotContainsString($grant['token'], (string) \json_encode($store->list()));
    }

    public function testGrantValidationEdges(): void
    {
        $store = $this->store($this->tokens());

        $this->expectException(\InvalidArgumentException::class);
        $store->grant('   ');
    }

    public function testAnOversizeLabelIsRefused(): void
    {
        $store = $this->store($this->tokens());

        try {
            $store->grant(\str_repeat('x', ViewerTokens::LABEL_MAX + 1));
            self::fail('a 65-character label must not mint');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('64', $refusal->getMessage());
        }
        self::assertSame(64, \strlen($store->grant(\str_repeat('y', ViewerTokens::LABEL_MAX))['label']), 'the bound itself grants');
    }

    public function testDayBoundsAreEnforced(): void
    {
        $store = $this->store($this->tokens());

        foreach ([0, -1, ViewerTokens::MAX_DAYS + 1] as $days) {
            try {
                $store->grant('bounds', null, $days);
                self::fail('day count ' . $days . ' must not mint');
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString('days', $refusal->getMessage());
            }
        }
        $grant = $store->grant('bounds', null, ViewerTokens::MAX_DAYS);
        self::assertSame(365 * 86400.0, $grant['expiresAt'] - $grant['createdAt']);
    }

    public function testASessionPinListMustHoldNonEmptyStrings(): void
    {
        $store = $this->store($this->tokens());

        foreach ([[''], ['ok', 5], ['ok', '']] as $listing) {
            try {
                $store->grant('pinned', $listing);
                self::fail('a malformed session list must not mint');
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString('session', $refusal->getMessage());
            }
        }

        $grant = $store->grant('pinned', ['s1', 's2']);
        self::assertSame(['s1', 's2'], $grant['sessionIds']);
        $row = $store->match($grant['token']);
        self::assertNotNull($row);
        self::assertSame(['s1', 's2'], $row['sessionIds'], 'the pin rides every validation');
    }

    public function testTheCapRefusesAndNeverEvicts(): void
    {
        $store = $this->store($this->tokens());
        $first = $store->grant('first');
        for ($n = 1; $n < ViewerTokens::MAX_GRANTS; ++$n) {
            $store->grant('filler ' . $n);
        }
        self::assertSame(ViewerTokens::MAX_GRANTS, $store->count());

        try {
            $store->grant('one too many');
            self::fail('the 65th grant must be refused');
        } catch (\RuntimeException $refusal) {
            self::assertStringContainsString('cap', $refusal->getMessage());
            self::assertStringContainsString('revoke', $refusal->getMessage(), 'the refusal names the way out');
        }

        self::assertSame(ViewerTokens::MAX_GRANTS, $store->count(), 'a refusal changes nothing');
        self::assertNotNull($store->match($first['token']), 'cap pressure never silently revokes');
    }

    public function testExpiryIsAbsoluteAndSweptOnSight(): void
    {
        $store = $this->store($this->tokens());
        $grant = $store->grant('short', null, 1);

        $this->now += 2 * 86400.0;
        self::assertNull($store->match($grant['token']));
        self::assertSame([], $store->list());
        self::assertSame(0, $store->count());

        // Activity does not slide the deadline: just before expiry it still
        // authenticates, and usage never bought it a longer life.
        $other = $store->grant('deadline', null, 1);
        $this->now += 86399.0;
        self::assertNotNull($store->match($other['token']));
        $this->now += 2.0;
        self::assertNull($store->match($other['token']));
    }

    public function testUnknownExpiredAndRevokedAllAnswerTheSameNull(): void
    {
        $store = $this->store($this->tokens());
        $live = $store->grant('still here');
        $doomed = $store->grant('doomed', null, 1);
        $killed = $store->grant('killed', null, 1);
        self::assertTrue($store->revoke($killed['id']));

        $this->now += 2 * 86400.0;

        $forged = ViewerTokens::PREFIX . \str_repeat('0', 40);
        self::assertNull($store->match($forged));
        self::assertNull($store->match($doomed['token']));
        self::assertNull($store->match($killed['token']));
        self::assertSame($store->match($forged), $store->match($doomed['token']), 'no oracle forges from');
        self::assertSame($store->match($forged), $store->match($killed['token']), 'no oracle for revocation either');
        self::assertNull($store->match('not-even-the-prefix'), 'a foreign credential is not even looked up');
        self::assertNotNull($store->match($live['token']), 'expiry of others never touches a live grant');
    }

    public function testGrantsSurviveARestartAndMalformedRowsDoNot(): void
    {
        $tokens = $this->tokens();
        $grant = $this->store($tokens)->grant('from before the restart');

        $rebuilt = $this->store($tokens);
        $row = $rebuilt->match($grant['token']);
        self::assertNotNull($row, 'the file is the truth across a restart');
        self::assertSame($grant['id'], $row['id']);

        // A second boot on a corrupted durable copy imports nothing and says
        // so — fail closed row by row, never half-parse a grant.
        PrivateRetainedDir::write($tokens->dir(), ViewerTokens::FILE, 'not json at all', 'server state');
        $third = $this->store($tokens);
        self::assertSame(0, $third->count());
        self::assertNotEmpty($this->notes, 'the unreadable copy is announced');

        $grafted = \json_encode(['grants' => [
            ['id' => 'deadbeef', 'label' => 'wide scopes', 'scopes' => ['read', 'write'], 'sessionIds' => null,
                'hash' => \hash('sha256', ViewerTokens::PREFIX . \str_repeat('1', 40)),
                'createdAt' => 1.0, 'expiresAt' => 4_000_000_000.0, 'lastUsedAt' => null],
            ['id' => 'cafebabe', 'label' => 'well formed', 'scopes' => ['read'], 'sessionIds' => ['s9'],
                'hash' => \hash('sha256', ViewerTokens::PREFIX . \str_repeat('2', 40)),
                'createdAt' => 1.0, 'expiresAt' => 4_000_000_000.0, 'lastUsedAt' => null],
        ]]);
        PrivateRetainedDir::write($tokens->dir(), ViewerTokens::FILE, (string) $grafted, 'server state');
        $fourth = $this->store($tokens);
        self::assertSame(1, $fourth->count(), 'the widened-scope row was dropped, the honest one imported');
        $imported = $fourth->list();
        self::assertSame('cafebabe', $imported[0]['id']);
        self::assertSame(['s9'], $imported[0]['sessionIds']);
        self::assertNotNull($fourth->match(ViewerTokens::PREFIX . \str_repeat('2', 40)));
        self::assertNull($fourth->match(ViewerTokens::PREFIX . \str_repeat('1', 40)));
    }

    public function testRevocationWritesTheDurableCopyAndAnswersTheTruth(): void
    {
        $tokens = $this->tokens();
        $store = $this->store($tokens);
        $keep = $store->grant('keep');
        $drop = $store->grant('drop');

        self::assertTrue($store->revoke($drop['id']));
        self::assertFalse($store->revoke($drop['id']), 'an id that names nothing answers false, once or twice');
        self::assertFalse($store->revoke('ffffffff'), 'and no never-existed-versus-already-gone oracle');

        $restarted = $this->store($tokens);
        self::assertNull($restarted->match($drop['token']), 'a revocation that survives restart or is none');
        self::assertNotNull($restarted->match($keep['token']));

        $restarted->revokeAll();
        self::assertSame(0, $this->store($tokens)->count(), 'wipe-all is durable too');
        self::assertSame([], $restarted->list());
    }

    public function testRevokeAllOnAnUntouchedStoreWritesNoFileAndNeverFails(): void
    {
        $dir = (string) \sys_get_temp_dir() . '/crush-g4g-cold-' . \bin2hex(\random_bytes(6));
        $this->extraDirs[] = $dir;
        $store = $this->store(TokenStore::new($dir));

        $store->revokeAll();
        self::assertNull($store->match(ViewerTokens::PREFIX . \str_repeat('0', 40)));
        self::assertSame([], $store->list());
        // The durable law: an untouched store writes no grants file at all —
        // an empty wipe of nothing is not an event. (Parent-dir creation on
        // first resolve is the house norm, exactly as SessionFileStore's own
        // read resolves through TokenStore::dir().)
        self::assertFileDoesNotExist($dir . '/' . ViewerTokens::FILE);
    }

    public function testLastUsedIsStampedLiveAndFlushedOnTheThrottle(): void
    {
        $tokens = $this->tokens();
        $store = $this->store($tokens);
        $grant = $store->grant('recency');

        $this->now += 10.0;
        $row = $store->match($grant['token']);
        self::assertNotNull($row);
        self::assertSame($this->now, $row['lastUsedAt'], 'the live answer carries the stamp immediately');
        $untouched = $this->store($tokens)->list();
        self::assertNull($untouched[0]['lastUsedAt'], 'inside the throttle window the disk copy lags by design');

        $this->now += ViewerTokens::SLIDE_FLUSH_SECONDS + 1;
        self::assertNotNull($store->match($grant['token']));
        $flushed = $this->store($tokens)->list();
        self::assertSame(1_700_000_000.0 + 10.0 + ViewerTokens::SLIDE_FLUSH_SECONDS + 1.0, $flushed[0]['lastUsedAt']);
    }

    public function testRotationRevokesEveryViewerGrant(): void
    {
        $tokens = $this->tokens();
        $tokens->token(); // establish the reload baseline the store needs
        $auth = AuthContext::new($tokens, fn (): float => $this->now);
        $watcher = $auth->viewerTokens->grant('rotation victim');
        $second = $auth->viewerTokens->grant('also a victim');
        self::assertNotNull($auth->viewerTokens->match($watcher['token']));

        TokenStore::new($this->dir)->rotate(); // the operator swapped the token on disk

        self::assertTrue($auth->reloadToken(), 'the changed token is noticed');
        self::assertNull($auth->viewerTokens->match($watcher['token']), 'rotation nukes every viewer grant');
        self::assertNull($auth->viewerTokens->match($second['token']));
        self::assertSame([], $auth->viewerTokens->list());

        $restarted = $this->store($tokens);
        self::assertSame(0, $restarted->count(), 'and the wipe is durable, not just in-memory');
        self::assertStringNotContainsString($watcher['token'], (string) \file_get_contents($this->dir . '/' . ViewerTokens::FILE));
    }

    public function testAuthContextCarriesTheStoreOnTheSameTokenDir(): void
    {
        $tokens = $this->tokens();
        $auth = AuthContext::new($tokens, fn (): float => $this->now);

        self::assertInstanceOf(ViewerTokens::class, $auth->viewerTokens);
        $grant = $auth->viewerTokens->grant('via context');
        self::assertFileExists($this->dir . '/' . ViewerTokens::FILE);
        self::assertNotNull($auth->viewerTokens->match($grant['token']));
    }

    private function middleware(AuthContext $auth): AuthMiddleware
    {
        $log = function (string $line): void {
            $this->mwLogLines[] = $line;
        };

        return new AuthMiddleware($auth, ServerConfig::new('/nonexistent'), $log);
    }

    /**
     * Drive one request through the door, mirroring the base harness: same
     * host, same peer knob, recorded pass-through.
     *
     * @param array<string, string> $headers
     */
    private function dial(AuthMiddleware $middleware, string $method, string $target, array $headers = [], string $peer = '127.0.0.1'): ResponseInterface
    {
        $request = new ServerRequest(
            $method,
            'http://127.0.0.1:7420' . $target,
            $headers + ['Host' => '127.0.0.1:7420'],
            '',
            '1.1',
            ['REMOTE_ADDR' => $peer],
        );
        $request = $request->withAttribute(HostAndOriginGuard::ATTR_ORIGIN, HostAndOriginGuard::ORIGIN_SAME);

        $response = $middleware($request, function (ServerRequestInterface $passed): Response {
            $this->mwPassed = $passed;

            return new Response(204);
        });
        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    /** @return array<string, string> handshake headers the upgrade door demands */
    private function upgradeHeaders(): array
    {
        return [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ];
    }

    public function testViewerBearerCarriesTheViewerPrincipalAndItsAttributes(): void
    {
        $auth = AuthContext::new($this->tokens(), fn (): float => $this->now);
        $grant = $auth->viewerTokens->grant('phone watch');

        $response = $this->dial($this->middleware($auth), 'GET', '/api/sessions', ['Authorization' => 'Bearer ' . $grant['token']]);

        self::assertSame(204, $response->getStatusCode());
        self::assertInstanceOf(ServerRequestInterface::class, $this->mwPassed);
        self::assertSame('viewer', $this->mwPassed->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
        self::assertSame(['read'], $this->mwPassed->getAttribute(AuthMiddleware::ATTR_SCOPES));
        self::assertSame($grant['id'], $this->mwPassed->getAttribute(AuthMiddleware::ATTR_TOKEN_ID));
        self::assertNull($this->mwPassed->getAttribute(AuthMiddleware::ATTR_VIEWER_SESSIONS));
        self::assertNull($this->mwPassed->getAttribute(AuthMiddleware::ATTR_SESSION));

        $admits = \array_values(\array_filter($this->mwLogLines, static fn (string $line): bool => \str_contains($line, 'viewer grant')));
        self::assertCount(1, $admits, 'the admit is exactly one audit line');
        self::assertStringContainsString($grant['id'], $admits[0]);
        foreach ($this->mwLogLines as $line) {
            self::assertStringNotContainsString($grant['token'], $line, 'the share token itself never reaches a log line');
        }
    }

    public function testPinnedGrantRidesItsSessionListOntoTheRequest(): void
    {
        $auth = AuthContext::new($this->tokens(), fn (): float => $this->now);
        $grant = $auth->viewerTokens->grant('desk', ['s-one', 's-two']);

        $response = $this->dial($this->middleware($auth), 'GET', '/api/sessions', ['Authorization' => 'Bearer ' . $grant['token']]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['s-one', 's-two'], $this->mwPassed?->getAttribute(AuthMiddleware::ATTR_VIEWER_SESSIONS));
    }

    public function testAWrongViewerTokenIsRefusedInTheOwnersOwnFortyOneShape(): void
    {
        $auth = AuthContext::new($this->tokens(), fn (): float => $this->now);
        $middleware = $this->middleware($auth);

        $wrongBearer = $this->dial($middleware, 'GET', '/api/sessions', ['Authorization' => 'Bearer ' . \str_repeat('0', 64)]);
        $wrongViewer = $this->dial($middleware, 'GET', '/api/sessions', ['Authorization' => 'Bearer ' . ViewerTokens::PREFIX . \str_repeat('a', 40)]);

        self::assertSame(401, $wrongBearer->getStatusCode());
        self::assertSame(401, $wrongViewer->getStatusCode());
        // Same body, same kind: the door answers an unknown share exactly like
        // an unknown owner token, so probing cannot tell the families apart.
        self::assertSame((string) $wrongBearer->getBody(), (string) $wrongViewer->getBody());
        self::assertStringContainsString('"kind":"unauthorized"', (string) $wrongViewer->getBody());
        self::assertNull($this->mwPassed);
    }

    public function testWrongViewerTokensAccrueStrikesAndMatchingOnesStillPassDuringLockout(): void
    {
        $auth = AuthContext::new($this->tokens(), fn (): float => $this->now);
        $middleware = $this->middleware($auth);
        $grant = $auth->viewerTokens->grant('phone watch');

        for ($i = 0; $i < RateLimiter::MAX_FAILURES; $i++) {
            $refused = $this->dial($middleware, 'GET', '/api/sessions', ['Authorization' => 'Bearer ' . ViewerTokens::PREFIX . \sprintf('dead%02d', $i) . \str_repeat('0', 32)]);
            self::assertSame(401, $refused->getStatusCode());
        }

        $locked = $this->dial($middleware, 'GET', '/api/sessions');
        self::assertSame(429, $locked->getStatusCode());
        self::assertStringContainsString('"kind":"rate_limited"', (string) $locked->getBody());

        // Pre-limiter law: a credential the owner minted on purpose is not
        // the limiter's business — viewer AND owner still pass; so does health.
        $this->mwPassed = null;
        $viewerPass = $this->dial($middleware, 'GET', '/api/sessions', ['Authorization' => 'Bearer ' . $grant['token']]);
        self::assertSame(204, $viewerPass->getStatusCode());
        self::assertSame('viewer', $this->mwPassed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));

        $this->mwPassed = null;
        $ownerPass = $this->dial($middleware, 'GET', '/api/sessions', ['Authorization' => 'Bearer ' . $auth->tokens->token()]);
        self::assertSame(204, $ownerPass->getStatusCode());
        self::assertSame('bearer', $this->mwPassed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));

        $health = $this->dial($middleware, 'GET', '/api/health');
        self::assertSame(204, $health->getStatusCode());

        foreach ($this->mwLogLines as $line) {
            self::assertStringNotContainsString($grant['token'], $line);
        }
    }

    public function testTheUpgradeDoorAcceptsAViewerSubprotocolTokenWhileTheAddressIsLockedOut(): void
    {
        $auth = AuthContext::new($this->tokens(), fn (): float => $this->now);
        $middleware = $this->middleware($auth);
        $grant = $auth->viewerTokens->grant('watch socket');

        for ($i = 0; $i < RateLimiter::MAX_FAILURES; $i++) {
            $this->dial($middleware, 'GET', '/api/sessions', ['Authorization' => 'Bearer ' . \str_repeat('f', 64)]);
        }

        $response = $this->dial($middleware, 'GET', '/ws', $this->upgradeHeaders() + [
            'Sec-WebSocket-Protocol' => ServerConfig::AUTH_SUBPROTOCOL_PREFIX . $grant['token'],
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('viewer', $this->mwPassed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
        self::assertSame($grant['id'], $this->mwPassed?->getAttribute(AuthMiddleware::ATTR_TOKEN_ID));
        foreach ($this->mwLogLines as $line) {
            self::assertStringNotContainsString($grant['token'], $line);
        }
    }

    public function testViewerBearerToThePublicLoginDoorStillPassesThrough(): void
    {
        $auth = AuthContext::new($this->tokens(), fn (): float => $this->now);
        $grant = $auth->viewerTokens->grant('phone watch');

        // The door's job on a public path is only to ride the attrs through;
        // /api/login then refuses the cv1_ exchange itself, body-side, because
        // its credential check is the owner tokens->matches() (D-3 — pinned
        // end-to-end by testTheLoginDoorRefusesAViewerTokenEndToEnd below).
        $response = $this->dial($this->middleware($auth), 'POST', '/api/login', ['Authorization' => 'Bearer ' . $grant['token']]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('viewer', $this->mwPassed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
    }

    /**
     * D-7: server.hello echoes the REAL principal kind, not a hardcoded
     * 'owner'. At base every client was told it was the owner, so a web
     * session holding a viewer token could not tell viewer from owner before
     * the first refused call. The owner leg keeps the constant-answer
     * regression (a hardcoded 'viewer' now) visible too.
     */
    public function testHelloAnswersTheRealPrincipalKind(): void
    {
        $dispatcher = $this->notesDispatcher();

        $viewer = WireClient::open($dispatcher, 'c-kind', 'viewer', ['read'], 'tok-kind');
        $hello = $viewer->hello();

        self::assertSame('viewer', $hello['principal']['kind']);
        self::assertSame('viewer', $hello['principal']['via']);

        $owner = WireClient::open($dispatcher, 'c-kind-owner');
        $hello = $owner->hello();

        self::assertSame('owner', $hello['principal']['kind']);
        self::assertSame('bearer', $hello['principal']['via']);
    }

    /** m1 (D-3 keystone): a viewer cv1_ token traded in at /api/login is refused — it is neither the owner hash nor a consumable code. */
    public function testTheLoginDoorRefusesAViewerTokenEndToEnd(): void
    {
        $auth = AuthContext::new($this->tokens(), fn (): float => $this->now);
        $grant = $auth->viewerTokens->grant('phone watch');
        $request = new ServerRequest(
            'POST',
            'http://127.0.0.1:7420/api/login',
            ['Authorization' => 'Bearer ' . $grant['token'], 'Content-Type' => 'application/json', 'Host' => '127.0.0.1:7420'],
            \json_encode(['token' => $grant['token']]),
            '1.1',
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
        $request = $request->withAttribute(HostAndOriginGuard::ATTR_ORIGIN, HostAndOriginGuard::ORIGIN_SAME);
        $controller = new ApiController($auth, ServerConfig::new('/nonexistent'), $this->middleware($auth));

        $response = $controller($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('"kind":"unauthorized"', (string) $response->getBody());
        self::assertStringNotContainsString($grant['token'], (string) $response->getBody());

        // Control: the same door accepts the owner token in the same place,
        // so the refusal above is specific to the viewer credential.
        $control = $controller(new ServerRequest(
            'POST',
            'http://127.0.0.1:7420/api/login',
            ['Content-Type' => 'application/json', 'Host' => '127.0.0.1:7420'],
            \json_encode(['token' => $this->tokens()->token()]),
            '1.1',
            ['REMOTE_ADDR' => '127.0.0.1'],
        ));
        self::assertSame(200, $control->getStatusCode());
    }

    /**
     * D-9 keystone: an idempotency answer belongs to the principal that minted
     * it. At base the dispatcher cached every answer under one shared owner
     * bucket, so a second credential replaying the same key was served the
     * FIRST principal's cached answer - the cross-principal replay leak the
     * X-DESIGN1 D-9 review caught (base-red evidence:
     * /tmp/opencode/reviews-1103/fixes/d9-base-red.txt).
     */
    public function testIdempotencyCannotReplayAcrossPrincipals(): void
    {
        $dispatcher = $this->notesDispatcher();

        $owner = WireClient::open($dispatcher, 'c-owner');
        $owner->hello();
        $minted = $owner->request('test.note', ['idempotencyKey' => 'shared-key']);
        self::assertSame('bearer', $minted['result']['served'] ?? null);
        self::assertSame(1, $minted['result']['n'] ?? null);

        $viewer = WireClient::open($dispatcher, 'c-view', 'viewer', ['read'], 'tok-v1');
        $viewer->hello();
        $replayed = $viewer->request('test.note', ['idempotencyKey' => 'shared-key']);

        // The viewer must run the method fresh, never see the owner's answer.
        self::assertSame('viewer', $replayed['result']['served'] ?? null);
        self::assertSame(2, $replayed['result']['n'] ?? null);
        self::assertSame(2, $this->noteCount, 'the handler ran once per principal, no more');

        // The owner replaying its OWN key still gets the cached first answer.
        $again = $owner->request('test.note', ['idempotencyKey' => 'shared-key']);
        self::assertSame(1, $again['result']['n'] ?? null);
        self::assertSame(2, $this->noteCount, 'the owner replay was served from its own bucket');
    }

    /** Distinct viewer grants are distinct principals: no shared cache slot. */
    public function testTwoViewerTokensNeverShareAnIdempotencyAnswer(): void
    {
        $dispatcher = $this->notesDispatcher();

        $first = WireClient::open($dispatcher, 'c-v1', 'viewer', ['read'], 'tok-v1');
        $first->hello();
        $second = WireClient::open($dispatcher, 'c-v2', 'viewer', ['read'], 'tok-v2');
        $second->hello();

        $one = $first->request('test.note', ['idempotencyKey' => 'twin-key']);
        $two = $second->request('test.note', ['idempotencyKey' => 'twin-key']);

        self::assertSame(1, $one['result']['n'] ?? null);
        self::assertSame(2, $two['result']['n'] ?? null, 'the second grant ran fresh');
    }

    /** Isolation runs both ways: the viewer mints first, the owner replays. */
    public function testTheOwnerCannotReplayAViewersAnswerEither(): void
    {
        $dispatcher = $this->notesDispatcher();

        $viewer = WireClient::open($dispatcher, 'c-first', 'viewer', ['read'], 'tok-first');
        $viewer->hello();
        $minted = $viewer->request('test.note', ['idempotencyKey' => 'flipped-key']);
        self::assertSame(1, $minted['result']['n'] ?? null);

        $owner = WireClient::open($dispatcher, 'c-after');
        $owner->hello();
        $after = $owner->request('test.note', ['idempotencyKey' => 'flipped-key']);
        self::assertSame(2, $after['result']['n'] ?? null);
        self::assertSame('bearer', $after['result']['served'] ?? null);
    }

    /**
     * The wire harness: a fixture session plus a registry carrying server.hello
     * and two extra side-effecting methods — a Read counter whose handler
     * counts real executions, and a Write twin for the scope wall. The counting
     * is the discriminator: a served-from-cache replay does not increment it,
     * so a cross-principal collision is visible as a STALE n rather than a
     * shape difference.
     */
    private function notesDispatcher(): Dispatcher
    {
        $this->wireFixture ??= ProtocolFixture::new();

        $registry = MethodRegistry::new();
        ServerMethods::register($registry);
        $registry->add(MethodSpec::new(
            'test.note',
            Scope::Read,
            'counts how often it actually ran (D-9 harness)',
            function (CallContext $call, Params $params): array {
                $this->noteCount++;

                return ['served' => $call->client->principal(), 'n' => $this->noteCount];
            },
            sideEffects: true,
        ));
        $registry->add(MethodSpec::new(
            'test.shout',
            Scope::Write,
            'a write-scope twin proving the scope wall (D-6 harness)',
            static fn (CallContext $call, Params $params): array => ['shouted' => true],
            sideEffects: true,
        ));

        return $this->myDispatcher ??= Dispatcher::new($this->wireFixture->context, $registry);
    }

    /**
     * Send a request and unwrap its result. Failures surface the wire error
     * inline so a cross-principal leak reads as a stale counter, not a crash.
     * Named unwrapResult(): TestCase::result() is final (PHPUnit 10) and a
     * `result()` helper would collide with it.
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    private function unwrapResult(WireClient $client, string $method, array $params): array
    {
        $reply = $client->request($method, $params);
        self::assertArrayHasKey('result', $reply, 'expected a result, got: ' . \json_encode($reply['error'] ?? null));

        return (array) $reply['result'];
    }

    public function testPinnedViewerSeesOnlyItsGrantedSessions(): void
    {
        $dispatcher = $this->notesDispatcher();
        $pinned = WireClient::open($dispatcher, 'c-pin', 'viewer', ['read'], 'tok-pin', ['s-one']);
        $pinned->hello();

        self::assertSame(1, $this->unwrapResult($pinned, 'test.note', [
            'sessionId' => 's-one',
            'idempotencyKey' => 'gate-key',
        ])['n'] ?? 0, 'the granted session must execute normally');

        // The very same key for a session OUTSIDE the pin is refused, not
        // answered from the s-one cache entry — the gate precedes the store,
        // so a refusal can never launder a pinned grant across sessions.
        $refused = $pinned->request('test.note', [
            'sessionId' => 's-two',
            'idempotencyKey' => 'gate-key',
        ]);
        self::assertArrayNotHasKey('result', $refused, 'a foreign session must not be served the cached answer');
        self::assertSame(ErrorCode::Forbidden->value, $refused['error']['code'] ?? null);
        self::assertStringContainsString('does not cover', $refused['error']['message'] ?? '');
        self::assertSame(1, $this->noteCount, 'the refusal never touched the handler');
    }

    public function testAnUnpinnedViewerReachesEverySessionNamed(): void
    {
        $dispatcher = $this->notesDispatcher();
        $free = WireClient::open($dispatcher, 'c-free', 'viewer', ['read'], 'tok-free');
        $free->hello();

        self::assertSame(1, $this->unwrapResult($free, 'test.note', ['sessionId' => 's-one'])['n'] ?? 0);
        self::assertSame(2, $this->unwrapResult($free, 'test.note', ['sessionId' => 's-two'])['n'] ?? 0);
        // The absent-argument law: naming no session is a plain read, fine
        // for a pinned OR unpinned grant alike.
        self::assertSame(3, $this->unwrapResult($free, 'test.note', [])['n'] ?? 0);

        $pinned = WireClient::open($dispatcher, 'c-free-pin', 'viewer', ['read'], 'tok-free-pin', ['s-one']);
        $pinned->hello();
        self::assertSame(4, $this->unwrapResult($pinned, 'test.note', [])['n'] ?? 0, 'a pinned grant without sessionId is not refused');
    }

    public function testViewerReadGrantsRefuseWriteAndAdminMethods(): void
    {
        // D-6: the scope wall predates this lane and already discriminates;
        // pinned here so a future grant widening cannot silently let a
        // viewer reach side-effecting or administrative methods.
        $dispatcher = $this->notesDispatcher();
        $viewer = WireClient::open($dispatcher, 'c-scope', 'viewer', ['read'], 'tok-scope');
        $viewer->hello();

        $send = $viewer->request('test.shout', ['sessionId' => 's-one']);
        self::assertArrayNotHasKey('result', $send, 'a read-only grant must never reach a write-scope method');
        self::assertSame(ErrorCode::Forbidden->value, $send['error']['code'] ?? null);

        // Against the notes registry — which does not mount token.* at all —
        // an unknown method is refused before any scope work.
        $unknown = $viewer->request('token.list', []);
        self::assertArrayNotHasKey('result', $unknown);

        // On a dispatcher that DOES mount the addendum handlers, token.list is
        // a registered Admin surface: the read-only grant is now refused by
        // the scope wall itself, before any handler or store work happens.
        $wall = WireClient::open($this->tokenDispatcher(), 'c-scope-wall', 'viewer', ['read'], 'tok-scope-wall');
        $wall->hello();
        $refused = $wall->request('token.list', []);
        self::assertArrayNotHasKey('result', $refused, 'the scope wall refuses the registered Admin surface');
        self::assertSame(ErrorCode::Forbidden->value, $refused['error']['code'] ?? null);
        self::assertSame(0, $this->tokenStore?->count(), 'the refused probe minted nothing');
    }

    /**
     * A store-backed dispatcher carrying the real token.* handlers, mirroring
     * how Serve::protocol() threads one AuthContext's ViewerTokens onto the
     * workspace the handlers look it up from. The store shares $this->dir,
     * which tearDown() already sweeps.
     */
    private function tokenDispatcher(): Dispatcher
    {
        if ($this->tokenDispatcher !== null) {
            return $this->tokenDispatcher;
        }

        $this->tokenStore = $this->store($this->tokens());
        $this->tokenFixture = ProtocolFixture::new(viewerTokens: $this->tokenStore);

        $registry = MethodRegistry::new();
        ServerMethods::register($registry);
        TokenMethods::register($registry);

        return $this->tokenDispatcher = Dispatcher::new($this->tokenFixture->context, $registry);
    }

    public function testTheOwnerMintsAViewerTokenExactlyOnceOverTheWire(): void
    {
        $owner = WireClient::open($this->tokenDispatcher(), 'c-mint');
        $owner->hello();

        $mint = $this->unwrapResult($owner, 'token.grant', ['label' => 'phone watch', 'scopes' => ['read']]);

        self::assertStringStartsWith(ViewerTokens::PREFIX, (string) $mint['token']);
        self::assertSame(44, strlen((string) $mint['token']), 'cv1_ plus forty hex digits, presented once');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string) $mint['id']);
        self::assertSame(['read'], $mint['scopes']);
        self::assertSame('phone watch', $mint['label']);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}[+-]\\d{2}:\\d{2}$/', (string) $mint['createdAt']);
        self::assertSame(
            30 * 86400,
            strtotime((string) $mint['expiresAt']) - strtotime((string) $mint['createdAt']),
            'the default lifetime is thirty days, spelled in ISO-8601 on the wire'
        );
        self::assertArrayNotHasKey('sessionIds', $mint, 'the mint answer is exactly the X-DESIGN1 surface');
        self::assertArrayNotHasKey('lastUsedAt', $mint);
        self::assertNotNull($this->tokenStore?->match((string) $mint['token']), 'the wire mint landed in the shared store');
    }

    public function testTheListSurfaceNeverSpeaksTheTokenOrHash(): void
    {
        $owner = WireClient::open($this->tokenDispatcher(), 'c-list');
        $owner->hello();
        $mint = $this->unwrapResult($owner, 'token.grant', ['label' => 'quiet', 'scopes' => ['read']]);

        $listed = $this->unwrapResult($owner, 'token.list', []);
        $encoded = (string) json_encode($listed);

        self::assertStringNotContainsString((string) $mint['token'], $encoded, 'the raw token never rides the list surface');
        self::assertStringNotContainsString(ViewerTokens::PREFIX, $encoded);
        self::assertStringNotContainsString('hash', $encoded);
        self::assertStringNotContainsString('lastUsedAt', $encoded);

        $row = (array) $listed['tokens'][0];
        self::assertSame($mint['id'], $row['id']);
        self::assertSame('quiet', $row['label']);
        self::assertSame(['read'], $row['scopes']);
        self::assertArrayNotHasKey('sessionIds', $row, 'an unpinned grant omits the key entirely');
    }

    public function testTheSchemaRejectsScopesOffTheReadAllowlistBeforeTheHandler(): void
    {
        $owner = WireClient::open($this->tokenDispatcher(), 'c-schema');
        $owner->hello();

        $reply = $owner->request('token.grant', ['label' => 'wider', 'scopes' => ['write']]);

        self::assertArrayNotHasKey('result', $reply);
        self::assertSame(ErrorCode::InvalidParams->value, $reply['error']['code'] ?? null, 'the generated schema enum is the first wall');
        self::assertSame(0, $this->tokenStore?->count());
    }

    public function testTheHandlerRefusesAnEmptyScopeListWithItsOwnKind(): void
    {
        $owner = WireClient::open($this->tokenDispatcher(), 'c-empty');
        $owner->hello();

        $reply = $owner->request('token.grant', ['label' => 'bare', 'scopes' => []]);

        self::assertArrayNotHasKey('result', $reply);
        self::assertSame(ErrorCode::InvalidParams->value, $reply['error']['code'] ?? null);
        self::assertSame('invalid_scopes', $reply['error']['data']['kind'] ?? null);
        self::assertSame('at least one scope is required', $reply['error']['message'] ?? null);
        self::assertSame(0, $this->tokenStore?->count());
    }

    public function testTheGrantCapRefusesTheNextMintAsAConflict(): void
    {
        $owner = WireClient::open($this->tokenDispatcher(), 'c-cap');
        $owner->hello();

        for ($i = 0; $i < ViewerTokens::MAX_GRANTS; $i++) {
            $this->unwrapResult($owner, 'token.grant', ['label' => 'cap-' . $i, 'scopes' => ['read']]);
        }

        $over = $owner->request('token.grant', ['label' => 'one too many', 'scopes' => ['read']]);

        self::assertArrayNotHasKey('result', $over);
        self::assertSame(ErrorCode::Conflict->value, $over['error']['code'] ?? null);
        self::assertSame('grant_cap_reached', $over['error']['data']['kind'] ?? null);
        self::assertStringContainsString('cap', (string) ($over['error']['message'] ?? ''), 'the store message rides through verbatim');
        self::assertSame(ViewerTokens::MAX_GRANTS, $this->tokenStore?->count());
    }

    public function testTheListAnswersNewestFirstAndEchoesPinsOnlyWhenSet(): void
    {
        $owner = WireClient::open($this->tokenDispatcher(), 'c-order');
        $owner->hello();

        $older = $this->unwrapResult($owner, 'token.grant', ['label' => 'older', 'scopes' => ['read']]);
        $this->now += 1.0;
        $newer = $this->unwrapResult($owner, 'token.grant', ['label' => 'newer', 'scopes' => ['read'], 'sessionIds' => ['s-one']]);

        $listed = $this->unwrapResult($owner, 'token.list', []);
        $rows = (array) $listed['tokens'];

        self::assertCount(2, $rows);
        self::assertSame($newer['id'], $rows[0]['id'], 'newest first');
        self::assertSame(['s-one'], $rows[0]['sessionIds']);
        self::assertSame($older['id'], $rows[1]['id']);
        self::assertArrayNotHasKey('sessionIds', $rows[1]);
    }

    public function testTheWireRevokeCutsTheGateInTheSharedStore(): void
    {
        $owner = WireClient::open($this->tokenDispatcher(), 'c-cut');
        $owner->hello();
        $mint = $this->unwrapResult($owner, 'token.grant', ['label' => 'temporary', 'scopes' => ['read']]);
        self::assertNotNull($this->tokenStore?->match((string) $mint['token']));

        $answer = $this->unwrapResult($owner, 'token.revoke', ['id' => $mint['id']]);
        self::assertSame(['revoked' => true], $answer);

        self::assertSame(0, $this->tokenStore?->count());
        self::assertNull($this->tokenStore?->match((string) $mint['token']), 'the wire revoke cuts the same rows the HTTP door reads');
        self::assertSame([], $this->unwrapResult($owner, 'token.list', [])['tokens']);
    }

    public function testTheUnknownIdAndTheAlreadyCutIdAnswerIdentically(): void
    {
        $owner = WireClient::open($this->tokenDispatcher(), 'c-oracle');
        $owner->hello();
        $mint = $this->unwrapResult($owner, 'token.grant', ['label' => 'once', 'scopes' => ['read']]);
        $this->unwrapResult($owner, 'token.revoke', ['id' => $mint['id']]);

        $again = $owner->request('token.revoke', ['id' => $mint['id']]);
        $never = $owner->request('token.revoke', ['id' => 'ffffffff']);

        self::assertSame(ErrorCode::NotFound->value, $again['error']['code'] ?? null);
        self::assertSame('token_not_found', $again['error']['data']['kind'] ?? null);
        self::assertSame(
            (string) json_encode($again['error'] ?? null),
            (string) json_encode($never['error'] ?? null),
            'a second cut and a miss are indistinguishable — no existence oracle'
        );
    }

    public function testTheViewerGrantRefusesAllThreeTokenMoves(): void
    {
        $viewer = WireClient::open($this->tokenDispatcher(), 'c-governor', 'viewer', ['read'], 'tok-governor');
        $viewer->hello();

        foreach ([
            'token.grant' => ['label' => 'self-mint', 'scopes' => ['read']],
            'token.list' => [],
            'token.revoke' => ['id' => 'deadbeef'],
        ] as $method => $params) {
            $reply = $viewer->request($method, $params);
            self::assertArrayNotHasKey('result', $reply, $method . ' is Admin-only');
            self::assertSame(ErrorCode::Forbidden->value, $reply['error']['code'] ?? null, $method);
            self::assertSame('this needs the admin scope', (string) ($reply['error']['message'] ?? ''), $method);
        }

        self::assertSame(0, $this->tokenStore?->count(), 'a viewer cannot mint, enumerate, or cut its own gate');
    }

    public function testTheHandlersRefuseAWorkspaceThatCarriesNoStore(): void
    {
        $barren = ProtocolFixture::new();
        $registry = MethodRegistry::new();
        ServerMethods::register($registry);
        TokenMethods::register($registry);
        $dispatcher = Dispatcher::new($barren->context, $registry);

        try {
            $owner = WireClient::open($dispatcher, 'c-barren');
            $owner->hello();

            $reply = $owner->request('token.grant', ['label' => 'nowhere', 'scopes' => ['read']]);

            self::assertArrayNotHasKey('result', $reply);
            self::assertSame(ErrorCode::UnsupportedInServer->value, $reply['error']['code'] ?? null);
            self::assertSame('tokens_unavailable', $reply['error']['data']['kind'] ?? null);
            self::assertSame('this server supervises no viewer token store', $reply['error']['message'] ?? null);
        } finally {
            $dispatcher->stop('over');
            $barren->tearDown();
        }
    }
}
