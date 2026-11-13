<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Middleware;

use React\Promise;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Middleware\Auth;
use SugarCraft\SshServer\Middleware\AuthRateLimitBridge;
use SugarCraft\SshServer\Middleware\RateLimit;
use SugarCraft\SshServer\Session;
use PHPUnit\Framework\TestCase;

final class AuthRateLimitBridgeTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    private string $statePath = '';

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['SSH_USER_AUTH'], $_SERVER['KEY_FINGERPRINT']);
        $this->statePath = (string) tempnam(sys_get_temp_dir(), 'sshl-bridge-');
        $this->assertNotSame('', $this->statePath);
        // The file is only a name here: AtomicJsonFile reads a present-but
        // empty payload as CORRUPT (loud, fail-open), so the state starts
        // genuinely absent.
        $this->assertTrue(@unlink($this->statePath));
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['SSH_USER_AUTH'], $_SERVER['KEY_FINGERPRINT']);
        @unlink($this->statePath);
        @unlink($this->statePath . '.lock');
    }

    private function session(string $user): Session
    {
        return new Session(
            user: $user, clientHost: '203.0.113.7', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    /**
     * The published bucket map, or null when nothing has been persisted.
     *
     * @return array<string, array{tokens: float, last: float, burst: int, rate: float}>|null
     */
    private function buckets(): ?array
    {
        $raw = @file_get_contents($this->statePath);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'state file must be a JSON map');
        /** @var array<string, array{tokens: float, last: float, burst: int, rate: float}> $decoded */
        return $decoded;
    }

    public function testOneFailedAuthVisiblyDrainsTheBucket(): void
    {
        // The annotation's integration pin: real Auth + real RateLimit over
        // a real temp state file — the docblock promise becomes testable.
        // burst 5, penalty default 3.0, no connect charge (the bridge only
        // pays the penalty), so a refused session leaves exactly 2.0.
        $rateLimit = new RateLimit($this->statePath, burst: 5, ratePerSec: 0.0);
        $bridge    = new AuthRateLimitBridge(Auth::allowingUsers(['alice']), $rateLimit);

        $reached = false;
        $bridge->handle(Context::background(), $this->session('eve'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached, 'a refused gate still stops the chain');

        $buckets = $this->buckets();
        $this->assertNotNull($buckets, 'the penalty must be persisted');
        $this->assertArrayHasKey('ip:203.0.113.7', $buckets);
        $this->assertSame(2.0, (float) $buckets['ip:203.0.113.7']['tokens']);
    }

    public function testAnAdmittedSessionPaysNoPenalty(): void
    {
        $rateLimit = new RateLimit($this->statePath, burst: 5, ratePerSec: 0.0);
        $bridge    = new AuthRateLimitBridge(Auth::allowingUsers(['alice']), $rateLimit);

        $reached = false;
        $bridge->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
        $this->assertNull($this->buckets(), 'an admitted session must not charge the bucket');
    }

    public function testTheChainDownstreamSeesTheSameContextAndSession(): void
    {
        // Behaviour-identical for everything below the bridge: one call,
        // same identity — composition must not fork or wrap the hop.
        $rateLimit = new RateLimit($this->statePath, burst: 5, ratePerSec: 0.0);
        $bridge    = new AuthRateLimitBridge(Auth::allowingUsers(['alice']), $rateLimit);

        $ctx     = Context::background();
        $session = $this->session('alice');
        $calls   = 0;
        $bridge->handle($ctx, $session, function (Context $inner, Session $innerSession) use (&$calls, $ctx, $session): void {
            $calls++;
            $this->assertSame($ctx, $inner);
            $this->assertSame($session, $innerSession);
        });
        $this->assertSame(1, $calls);
    }

    public function testAnAsyncGateIsRefusedAtHandleTime(): void
    {
        // A promise-returning gate has not decided yet; charging on
        // non-continuation would penalise in-flight auth. Fail loud.
        $gate = new class implements Middleware {
            public function handle(Context $ctx, Session $session, callable $next): Promise\PromiseInterface
            {
                return Promise\resolve(null);
            }
        };
        $rateLimit = new RateLimit($this->statePath, burst: 5, ratePerSec: 0.0);
        $bridge    = new AuthRateLimitBridge($gate, $rateLimit);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('synchronous gates only');
        $bridge->handle(Context::background(), $this->session('alice'), function (): void {
            $this->fail('the async gate must not have continued the chain in this fixture');
        });
    }

    public function testANonPositivePenaltyIsRefusedAtConstruction(): void
    {
        $rateLimit = new RateLimit($this->statePath, burst: 5, ratePerSec: 0.0);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('penalty must be > 0');
        new AuthRateLimitBridge(Auth::allowingUsers(['alice']), $rateLimit, 0.0);
    }

    public function testACustomPenaltyIsChargedExactly(): void
    {
        $rateLimit = new RateLimit($this->statePath, burst: 10, ratePerSec: 0.0);
        $bridge    = new AuthRateLimitBridge(Auth::allowingUsers(['alice']), $rateLimit, 4.5);

        $bridge->handle(Context::background(), $this->session('eve'), function (): void {
            $this->fail('eve is not on the allowlist');
        });

        $buckets = $this->buckets();
        $this->assertNotNull($buckets);
        $this->assertSame(5.5, (float) $buckets['ip:203.0.113.7']['tokens']);
    }
}
