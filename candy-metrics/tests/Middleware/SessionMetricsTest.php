<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Middleware;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Middleware\SessionMetrics;
use SugarCraft\Metrics\Registry;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class SessionMetricsTest extends TestCase
{
    private function session(string $user = 'alice', string $term = 'xterm-256color'): Session
    {
        return new Session(
            user: $user, clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: $term, cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testRecordsConnectAndDuration(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r);
        $mw->handle(Context::background(), $this->session(), function (): void { usleep(1000); });

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
            $mw->handle(Context::background(), $this->session(), function (): void {
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

    public function testHostileSessionTagsAreCappedAndSanitized(): void
    {
        // user/term come straight from attacker-controlled SSH env (USER/TERM).
        // A hostile, oversized value must not be recorded verbatim (cardinality
        // explosion) nor carry the '='/'|' key separators or newlines that would
        // corrupt the cardinality tracker / inject into line-oriented backends.
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r);

        $hostileUser = "alice|evil=1\n; rm -rf /" . str_repeat('x', 200);
        $hostileTerm = "xterm\r\n\t injected=1";
        $mw->handle(Context::background(), $this->session($hostileUser, $hostileTerm), fn() => null);

        // The raw hostile values are never recorded verbatim.
        $this->assertSame(0.0, $b->counterValue('wish.session.connect', [
            'user' => $hostileUser, 'term' => $hostileTerm,
        ]));

        // Exactly one connect series was recorded; its stored key must be
        // bounded and free of separator/injection bytes.
        $connectKeys = array_values(array_filter(
            array_keys($b->counters()),
            static fn(string $k): bool => str_starts_with($k, 'wish.session.connect'),
        ));
        $this->assertCount(1, $connectKeys);
        $key = $connectKeys[0];
        $this->assertDoesNotMatchRegularExpression('/[\r\n\t ]/', $key, 'no control/whitespace bytes may survive');

        // Pull the user=<value> and term=<value> segments out of the storage key
        // (format: wish.session.connect|term=<...>|user=<...>) and assert each
        // value is charset-clamped and length-capped to 64.
        foreach (['user', 'term'] as $tag) {
            $matched = preg_match('/(?:^|\|)' . $tag . '=([^|]*)/', $key, $m);
            $this->assertSame(1, $matched, "expected a {$tag}= segment in the key");
            $this->assertLessThanOrEqual(64, strlen($m[1]), "{$tag} tag must be capped at 64 chars");
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:@\/-]*$/', $m[1], "{$tag} tag must be charset-clamped");
        }
    }

    public function testExtraTagsCallableMergesIntoEveryEmit(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r, fn(Session $s) => ['client_subnet' => '127.0.0.0/24']);
        $mw->handle(Context::background(), $this->session(), fn() => null);

        $this->assertSame(
            1.0,
            $b->counterValue('wish.session.connect', [
                'user' => 'alice', 'term' => 'xterm-256color', 'client_subnet' => '127.0.0.0/24',
            ]),
        );
    }
}
