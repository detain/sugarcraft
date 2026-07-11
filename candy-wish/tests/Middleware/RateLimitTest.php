<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\RateLimit;
use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    private string $statePath = '';

    protected function setUp(): void
    {
        $this->statePath = sys_get_temp_dir() . '/wish-ratelimit-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if ($this->statePath !== '' && is_file($this->statePath)) {
            unlink($this->statePath);
        }
    }

    private function session(string $ip): Session
    {
        return new Session(
            user: 'alice', clientHost: $ip, clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testBucketRefillsOverTime(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // burst=2, refill 0.5/s
        $rl = new RateLimit($this->statePath, 2, 0.5, $err);

        $ok = 0;
        $rl->handle(Context::background(), $this->session('1.2.3.4'), function () use (&$ok): void { $ok++; });
        $rl->handle(Context::background(), $this->session('1.2.3.4'), function () use (&$ok): void { $ok++; });
        // Third connect within tens of microseconds — should be rejected.
        $rl->handle(Context::background(), $this->session('1.2.3.4'), function () use (&$ok): void { $ok++; });

        $this->assertSame(2, $ok);
        rewind($err);
        $this->assertStringContainsString('Rate limit', (string) stream_get_contents($err));
        fclose($err);
    }

    public function testIndependentBucketsPerIp(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $rl = new RateLimit($this->statePath, 1, 0.5, $err);
        $ok = 0;
        $rl->handle(Context::background(), $this->session('1.1.1.1'), function () use (&$ok): void { $ok++; });
        $rl->handle(Context::background(), $this->session('2.2.2.2'), function () use (&$ok): void { $ok++; });
        // Each IP got its single token.
        $this->assertSame(2, $ok);
        // Now both buckets are empty.
        $rl->handle(Context::background(), $this->session('1.1.1.1'), function () use (&$ok): void { $ok++; });
        $rl->handle(Context::background(), $this->session('2.2.2.2'), function () use (&$ok): void { $ok++; });
        $this->assertSame(2, $ok);
        fclose($err);
    }

    public function testStateFilePersistedAcrossInstances(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // Drain the bucket
        (new RateLimit($this->statePath, 1, 0.0, $err))
            ->handle(Context::background(), $this->session('5.6.7.8'), function () {});
        // New instance reads back empty bucket — should reject.
        $ok = 0;
        (new RateLimit($this->statePath, 1, 0.0, $err))
            ->handle(Context::background(), $this->session('5.6.7.8'), function () use (&$ok): void { $ok++; });
        $this->assertSame(0, $ok);
        fclose($err);
    }

    private function session2(string $ip, string $user): Session
    {
        return new Session(
            user: $user, clientHost: $ip, clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testFailClosedDeniesWhenStateFileUnreadable(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // A path inside a non-existent directory: fopen('c+') cannot
        // create it, forcing the state-error branch.
        $bad = sys_get_temp_dir() . '/wish-ratelimit-nodir-' . uniqid() . '/state.json';
        $rl  = new RateLimit($bad, 5, 0.5, $err, failClosed: true);
        $ok  = 0;
        $rl->handle(Context::background(), $this->session('9.9.9.9'), function () use (&$ok): void { $ok++; });
        $this->assertSame(0, $ok, 'failClosed=true must DENY when the state file cannot be opened');
        rewind($err);
        $out = (string) stream_get_contents($err);
        $this->assertStringContainsString('denying connection', $out);
        fclose($err);
    }

    public function testDefaultFailsOpenButLogsWhenStateFileUnreadable(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $bad = sys_get_temp_dir() . '/wish-ratelimit-nodir-' . uniqid() . '/state.json';
        // Default: failClosed omitted → fail-open, but now logged (no
        // longer silent).
        $rl = new RateLimit($bad, 5, 0.5, $err);
        $ok = 0;
        $rl->handle(Context::background(), $this->session('9.9.9.9'), function () use (&$ok): void { $ok++; });
        $this->assertSame(1, $ok, 'default must ALLOW when the state file cannot be opened (fail-open)');
        rewind($err);
        $out = (string) stream_get_contents($err);
        $this->assertStringContainsString('fail-open', $out);
        fclose($err);
    }

    public function testPerUsernameThrottleTriggersIndependentlyOfIp(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // Per-IP is generous (never the limiter here), per-username burst=1.
        // Same account 'alice' from two DIFFERENT source IPs: the second
        // connect must be throttled by the username bucket even though its
        // IP bucket is fresh.
        $rl = new RateLimit($this->statePath, 100, 0.0, $err, userBurst: 1, userRatePerSec: 0.0);
        $ok = 0;
        $rl->handle(Context::background(), $this->session2('10.0.0.1', 'alice'), function () use (&$ok): void { $ok++; });
        $rl->handle(Context::background(), $this->session2('10.0.0.2', 'alice'), function () use (&$ok): void { $ok++; });
        $this->assertSame(1, $ok, 'username bucket must throttle alice across IPs');

        // A different account from the second IP is unaffected.
        $rl->handle(Context::background(), $this->session2('10.0.0.2', 'bob'), function () use (&$ok): void { $ok++; });
        $this->assertSame(2, $ok, 'bob has his own username bucket');
        fclose($err);
    }

    public function testPenaltyDrainsUsernameBucket(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // burst 3 on the username bucket, no refill.
        $rl = new RateLimit($this->statePath, 100, 0.0, $err, userBurst: 3, userRatePerSec: 0.0);
        // Simulate three auth failures for 'carol' — each drains a token.
        $rl->penalize($this->session2('10.0.0.9', 'carol'), 1.0);
        $rl->penalize($this->session2('10.0.0.9', 'carol'), 1.0);
        $rl->penalize($this->session2('10.0.0.9', 'carol'), 1.0);
        // Now the username bucket is empty → next connect is rejected.
        $ok = 0;
        $rl->handle(Context::background(), $this->session2('10.0.0.10', 'carol'), function () use (&$ok): void { $ok++; });
        $this->assertSame(0, $ok, 'auth-failure penalties must exhaust the username bucket');
        fclose($err);
    }

    public function testStateFileIsOwnerReadableOnly(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX file mode bits are not meaningful on Windows.');
        }
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        (new RateLimit($this->statePath, 2, 0.5, $err))
            ->handle(Context::background(), $this->session('7.7.7.7'), function () {});
        clearstatcache();
        $this->assertFileExists($this->statePath);
        $mode = fileperms($this->statePath) & 0777;
        $this->assertSame(0600, $mode, sprintf('state file must be 0600, got %04o', $mode));
        fclose($err);
    }
}
