<?php

declare(strict_types=1);

namespace CandyCore\Wish\Tests\Middleware;

use CandyCore\Wish\Middleware\Logger;
use CandyCore\Wish\Session;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '203.0.113.7', clientPort: 5555, serverHost: '198.51.100.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: '/dev/pts/3',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testEmitsStartAndEndEvents(): void
    {
        $log = fopen('php://memory', 'w+');
        $this->assertNotFalse($log);
        $l = new Logger($log);
        $reached = false;
        $l->handle($this->session(), function (Session $s) use (&$reached): void {
            $reached = true;
        });
        rewind($log);
        $contents = (string) stream_get_contents($log);
        $lines = array_filter(explode("\n", $contents), fn($l) => $l !== '');
        $this->assertCount(2, $lines, "expected start + end records, got: $contents");
        $start = json_decode((string) $lines[0], true);
        $end   = json_decode((string) $lines[1], true);
        $this->assertSame('session.start', $start['event']);
        $this->assertSame('session.end',   $end['event']);
        $this->assertSame('alice', $start['user']);
        $this->assertSame('alice', $end['user']);
        $this->assertArrayHasKey('elapsed_s', $end);
        $this->assertTrue($reached);
        fclose($log);
    }

    public function testEndEventEmittedEvenOnException(): void
    {
        $log = fopen('php://memory', 'w+');
        $this->assertNotFalse($log);
        $l = new Logger($log);
        try {
            $l->handle($this->session(), function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        rewind($log);
        $contents = (string) stream_get_contents($log);
        $this->assertStringContainsString('session.start', $contents);
        $this->assertStringContainsString('session.end',   $contents);
        fclose($log);
    }
}
