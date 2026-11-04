<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\RateLimiter;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Auth\ViewerTokens;
use SugarCraft\Code\Server\Http\AuthMiddleware;
use SugarCraft\Code\Server\Http\HostAndOriginGuard;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Support\PrivateRetainedDir;

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
        // its credential check is the owner tokens->matches() (D-3 — the
        // controller is outside this lane's file-set; that leg is pinned by
        // its own hash-equality law, not here).
        $response = $this->dial($this->middleware($auth), 'POST', '/api/login', ['Authorization' => 'Bearer ' . $grant['token']]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('viewer', $this->mwPassed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
    }
}
