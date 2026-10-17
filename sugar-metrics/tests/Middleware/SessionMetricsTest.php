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
        $mw = new SessionMetrics($r, fn(Session $_s) => ['client_subnet' => '127.0.0.0/24']);
        $mw->handle(Context::background(), $this->session(), fn() => null);

        $this->assertSame(
            1.0,
            $b->counterValue('wish.session.connect', [
                'user' => 'alice', 'term' => 'xterm-256color', 'client_subnet' => '127.0.0.0/24',
            ]),
        );
    }

    public function testExtraTagsReturningEmptyArrayStillRecords(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r, fn(Session $_s) => []);
        $mw->handle(Context::background(), $this->session(), fn() => null);

        $this->assertSame(
            1.0,
            $b->counterValue('wish.session.connect', [
                'user' => 'alice', 'term' => 'xterm-256color',
            ]),
        );
    }

    public function testNormalizeTagCappedAtExactlyMaxLength(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r);

        // Exactly 64 chars — at the boundary, no truncation.
        $exactUser = str_repeat('a', 64);
        $session = $this->session($exactUser, 'xterm');
        $mw->handle(Context::background(), $session, fn() => null);

        $keys = array_values(array_filter(
            array_keys($b->counters()),
            static fn(string $k): bool => str_starts_with($k, 'wish.session.connect'),
        ));
        $this->assertCount(1, $keys);
        $this->assertStringContainsString('user=' . $exactUser, $keys[0]);
    }

    public function testNormalizeTagTruncatesOverMaxLength(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r);

        // 65 chars — one over the max, should be truncated.
        $overUser = str_repeat('b', 65);
        $session = $this->session($overUser, 'xterm');
        $mw->handle(Context::background(), $session, fn() => null);

        $keys = array_values(array_filter(
            array_keys($b->counters()),
            static fn(string $k): bool => str_starts_with($k, 'wish.session.connect'),
        ));
        $this->assertCount(1, $keys);
        $this->assertStringContainsString('user=' . str_repeat('b', 64), $keys[0]);
        $this->assertStringNotContainsString('user=' . str_repeat('b', 65), $keys[0]);
    }

    public function testDurationTimerRecordsPositiveValue(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r);
        $mw->handle(Context::background(), $this->session(), function (): void {
            usleep(5000); // 5ms
        });

        $samples = $b->histogramValues('wish.session.duration', [
            'user' => 'alice', 'term' => 'xterm-256color',
        ]);
        $this->assertCount(1, $samples);
        $this->assertGreaterThanOrEqual(0.005, $samples[0]);
    }

    public function testCounterFailureIsSwallowedAndSessionContinues(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public function counter(string $name, float $value, array $tags = []): void
            {
                throw new \RuntimeException('counter always fails');
            }
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void {}
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(\SugarCraft\Metrics\Descriptor $descriptor): void {}
            public function flush(): void {}
            public function remove(string $name, array $tags = []): void {}
            public function clear(): void {}
        };

        $r = new Registry($throwing);
        $mw = new SessionMetrics($r);
        $ran = false;
        $mw->handle(Context::background(), $this->session(), function () use (&$ran): void {
            $ran = true;
        });
        // The session next() must have been called despite counter() throwing.
        $this->assertTrue($ran);
    }

    public function testTimerFailureIsSwallowedAndSessionContinues(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public bool $counterCalled = false;
            public function counter(string $name, float $value, array $tags = []): void
            {
                $this->counterCalled = true;
            }
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void
            {
                throw new \RuntimeException('histogram fails');
            }
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(\SugarCraft\Metrics\Descriptor $descriptor): void {}
            public function flush(): void {}
            public function remove(string $name, array $tags = []): void {}
            public function clear(): void {}
        };

        $throwingBackend = new class implements \SugarCraft\Metrics\Backend {
            public bool $counterCalled = false;
            public function counter(string $name, float $value, array $tags = []): void
            {
                $this->counterCalled = true;
            }
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void {}
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(\SugarCraft\Metrics\Descriptor $descriptor): void {}
            public function flush(): void {}
            public function remove(string $name, array $tags = []): void {}
            public function clear(): void {}
        };

        $r = new Registry($throwingBackend);
        $mw = new SessionMetrics($r);
        $ran = false;
        $mw->handle(Context::background(), $this->session(), function () use (&$ran): void {
            $ran = true;
        });
        // Both counter and timer (histogram) failures must be swallowed.
        $this->assertTrue($ran);
        $this->assertTrue($throwingBackend->counterCalled);
    }

    public function testErrorCounterFailureIsSwallowed(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public bool $counterCalled = false;
            public function counter(string $name, float $value, array $tags = []): void
            {
                $this->counterCalled = true;
            }
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void {}
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(\SugarCraft\Metrics\Descriptor $descriptor): void {}
            public function flush(): void {}
            public function remove(string $name, array $tags = []): void {}
            public function clear(): void {}
        };

        $r = new Registry($throwing);
        $mw = new SessionMetrics($r);
        $ran = false;
        try {
            $mw->handle(Context::background(), $this->session(), function () use (&$ran): void {
                $ran = true;
                throw new \RuntimeException('next fails');
            });
            $this->fail('Expected RuntimeException to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('next fails', $e->getMessage());
        }
        // The session ran and the exception propagated.
        $this->assertTrue($ran);
    }
}
