<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Tests\Middleware;

use CandyCore\Metrics\Backend\InMemoryBackend;
use CandyCore\Metrics\Middleware\SessionMetrics;
use CandyCore\Metrics\Registry;
use CandyCore\Wish\Session;
use PHPUnit\Framework\TestCase;

final class SessionMetricsTest extends TestCase
{
    private function session(string $user = 'alice'): Session
    {
        return new Session(
            user: $user, clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm-256color', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testRecordsConnectAndDuration(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r);
        $mw->handle($this->session(), function (): void { usleep(1000); });

        $this->assertSame(
            1.0,
            $b->counterValue('wish.session.connect', ['user' => 'alice', 'term' => 'xterm-256color']),
        );
        $samples = $b->histogramValues('wish.session.duration', ['user' => 'alice', 'term' => 'xterm-256color']);
        $this->assertCount(1, $samples);
        $this->assertGreaterThan(0.0, $samples[0]);
    }

    public function testRecordsErrorOnException(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r);
        try {
            $mw->handle($this->session(), function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception to propagate');
        } catch (\RuntimeException) {
            // expected
        }
        $errs = $b->counterValue('wish.session.error', [
            'user' => 'alice', 'term' => 'xterm-256color', 'exception' => \RuntimeException::class,
        ]);
        $this->assertSame(1.0, $errs);
        // Connect counter still incremented before the throw.
        $this->assertSame(1.0, $b->counterValue('wish.session.connect', ['user' => 'alice', 'term' => 'xterm-256color']));
    }

    public function testExtraTagsCallableMergesIntoEveryEmit(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r, fn(Session $s) => ['client_subnet' => '127.0.0.0/24']);
        $mw->handle($this->session(), fn() => null);

        $this->assertSame(
            1.0,
            $b->counterValue('wish.session.connect', [
                'user' => 'alice', 'term' => 'xterm-256color', 'client_subnet' => '127.0.0.0/24',
            ]),
        );
    }
}
