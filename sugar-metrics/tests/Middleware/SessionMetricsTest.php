<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Middleware;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Middleware\SessionMetrics;
use SugarCraft\Metrics\Registry;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Session;
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
            $b->counterValue('ssh.session.connect', ['user' => 'alice', 'term' => 'xterm-256color']),
        );
        $samples = $b->histogramValues('ssh.session.duration', ['user' => 'alice', 'term' => 'xterm-256color']);
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
        $errs = $b->counterValue('ssh.session.error', [
            'user' => 'alice', 'term' => 'xterm-256color', 'exception' => \RuntimeException::class,
        ]);
        $this->assertSame(1.0, $errs);
        // Connect counter still incremented before the throw.
        $this->assertSame(1.0, $b->counterValue('ssh.session.connect', ['user' => 'alice', 'term' => 'xterm-256color']));
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
        $this->assertSame(0.0, $b->counterValue('ssh.session.connect', [
            'user' => $hostileUser, 'term' => $hostileTerm,
        ]));

        // Exactly one connect series was recorded; its stored key must be
        // bounded and free of separator/injection bytes.
        $connectKeys = array_values(array_filter(
            array_keys($b->counters()),
            static fn(string $k): bool => str_starts_with($k, 'ssh.session.connect'),
        ));
        $this->assertCount(1, $connectKeys);
        $key = $connectKeys[0];
        $this->assertDoesNotMatchRegularExpression('/[\r\n\t ]/', $key, 'no control/whitespace bytes may survive');

        // Pull the user=<value> and term=<value> segments out of the storage key
        // (format: ssh.session.connect|term=<...>|user=<...>) and assert each
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
            $b->counterValue('ssh.session.connect', [
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
            $b->counterValue('ssh.session.connect', [
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
            static fn(string $k): bool => str_starts_with($k, 'ssh.session.connect'),
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
            static fn(string $k): bool => str_starts_with($k, 'ssh.session.connect'),
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

        $samples = $b->histogramValues('ssh.session.duration', [
            'user' => 'alice', 'term' => 'xterm-256color',
        ]);
        $this->assertCount(1, $samples);
        $this->assertGreaterThanOrEqual(0.005, $samples[0]);
    }

    public function testExceptionTagCannotBeShadowedByExtraTags(): void
    {
        // n3: the error counter's `exception` tag must report the REAL
        // throwable class even when the extraTags callable supplies its own
        // `exception` key — annotation, not impersonation.
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r, fn(Session $_s): array => ['exception' => 'forged']);
        try {
            $mw->handle(Context::background(), $this->session(), function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception to propagate');
        } catch (\RuntimeException) {
            // expected
        }
        $this->assertSame(1.0, $b->counterValue('ssh.session.error', [
            'user' => 'alice', 'term' => 'xterm-256color', 'exception' => 'RuntimeException',
        ]));
        $this->assertSame(0.0, $b->counterValue('ssh.session.error', [
            'user' => 'alice', 'term' => 'xterm-256color', 'exception' => 'forged',
        ]));
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

    public function testExtraTagsCannotRewriteTheBaseSessionIdentity(): void
    {
        // sugar-metrics-005: a hostile (or buggy) extraTags closure used to
        // ride array_merge AFTER the base tags, so ['user' => 'mallory']
        // replaced the normalized session identity on connect/duration —
        // cardinality and identity spoofing on the scraped plane, while the
        // error arm protected 'exception' by doctrine. Base tags are now
        // authoritative: the closure's own keys survive, its user/term do not.
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r, fn (Session $s): array => ['user' => 'mallory', 'region' => 'eu-1']);
        $mw->handle(Context::background(), $this->session(), fn () => null);

        $this->assertSame(1.0, $b->counterValue('ssh.session.connect', [
            'region' => 'eu-1', 'user' => 'alice', 'term' => 'xterm-256color',
        ]));
        $this->assertSame(0.0, $b->counterValue('ssh.session.connect', [
            'region' => 'eu-1', 'user' => 'mallory', 'term' => 'xterm-256color',
        ]));
    }

    public function testExtraTagValuesRideTheSameClampAsBaseTags(): void
    {
        // sugar-metrics-005 (SEC-06/NET-04 legs): the docblock invited
        // "read a field off $session" closures, but Session fields such as
        // clientVersion are raw and unlength-bounded — the values bypassed
        // normalizeTag, re-opening the label-space budget MAX_TAG_LENGTH
        // exists to cap and risking UDP fragmentation drops on statsd.
        // Closure values now ride the same clamp; author-sanitized values
        // are fixed points.
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r, fn (Session $s): array => [
            'build' => str_repeat('a', 190) . '!!!',
            'subnet' => "10.0.0|1 evil=2\n;rm",
            'clean' => 'v1.2.3-rc_1',
        ]);
        $mw->handle(Context::background(), $this->session(), fn () => null);

        $expected = [
            'build' => str_repeat('a', 64),
            'subnet' => '10.0.0_1_evil_2__rm',
            'clean' => 'v1.2.3-rc_1',
        ];
        $this->assertSame(1.0, $b->counterValue('ssh.session.connect',
            $expected + ['user' => 'alice', 'term' => 'xterm-256color']));
        foreach ($expected as $key => $value) {
            $this->assertLessThanOrEqual(64, strlen($value), $key);
        }
    }

    public function testExceptionLabelCarriesBasenameByDefaultAndFullClassOnOptIn(): void
    {
        // sugar-metrics-049: the thrown class's FULL FQCN was exported as a
        // scraped label — a free fingerprint of application and vendor
        // layout. Default is now the basename; $fullExceptionClass restores
        // the old spelling (backslashes folded to '_') for private sinks.
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $mw = new SessionMetrics($r);
        $throw = static fn (): never => throw new ProbeException('namespaced boom');
        try {
            $mw->handle(Context::background(), $this->session(), $throw);
            $this->fail('expected exception to propagate');
        } catch (ProbeException) {
            // expected
        }
        $base = ['user' => 'alice', 'term' => 'xterm-256color'];
        $this->assertSame(1.0, $b->counterValue('ssh.session.error', $base + ['exception' => 'ProbeException']));
        $this->assertSame(0.0, $b->counterValue('ssh.session.error', $base + [
            'exception' => 'SugarCraft_Metrics_Tests_Middleware_ProbeException',
        ]));

        $bf = new InMemoryBackend();
        $full = new SessionMetrics(new Registry($bf), fullExceptionClass: true);
        try {
            $full->handle(Context::background(), $this->session(), $throw);
            $this->fail('expected exception to propagate');
        } catch (ProbeException) {
            // expected
        }
        $this->assertSame(1.0, $bf->counterValue('ssh.session.error', $base + [
            'exception' => 'SugarCraft_Metrics_Tests_Middleware_ProbeException',
        ]));
    }
}

/**
 * The basename-vs-FQCN exception-label arm (sugar-metrics-049) needs a
 * thrown class that actually carries a namespace — root-namespace classes
 * like RuntimeException are identical spellings under both knobs.
 */
final class ProbeException extends \RuntimeException
{
}
