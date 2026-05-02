<?php

declare(strict_types=1);

namespace CandyCore\Wish\Tests\Middleware;

use CandyCore\Wish\Middleware\RateLimit;
use CandyCore\Wish\Session;
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
        $rl->handle($this->session('1.2.3.4'), function () use (&$ok): void { $ok++; });
        $rl->handle($this->session('1.2.3.4'), function () use (&$ok): void { $ok++; });
        // Third connect within tens of microseconds — should be rejected.
        $rl->handle($this->session('1.2.3.4'), function () use (&$ok): void { $ok++; });

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
        $rl->handle($this->session('1.1.1.1'), function () use (&$ok): void { $ok++; });
        $rl->handle($this->session('2.2.2.2'), function () use (&$ok): void { $ok++; });
        // Each IP got its single token.
        $this->assertSame(2, $ok);
        // Now both buckets are empty.
        $rl->handle($this->session('1.1.1.1'), function () use (&$ok): void { $ok++; });
        $rl->handle($this->session('2.2.2.2'), function () use (&$ok): void { $ok++; });
        $this->assertSame(2, $ok);
        fclose($err);
    }

    public function testStateFilePersistedAcrossInstances(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // Drain the bucket
        (new RateLimit($this->statePath, 1, 0.0, $err))
            ->handle($this->session('5.6.7.8'), function () {});
        // New instance reads back empty bucket — should reject.
        $ok = 0;
        (new RateLimit($this->statePath, 1, 0.0, $err))
            ->handle($this->session('5.6.7.8'), function () use (&$ok): void { $ok++; });
        $this->assertSame(0, $ok);
        fclose($err);
    }
}
