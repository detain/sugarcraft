<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\StatsdBackend;
use PHPUnit\Framework\TestCase;

final class StatsdBackendTest extends TestCase
{
    public function testCounterFormat(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('hits', 1.0, ['route' => '/x', 'env' => 'prod']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        $this->assertStringStartsWith('hits:1|c', $payload);
        $this->assertStringContainsString('|#', $payload);
        $this->assertStringContainsString('route:/x', $payload);
        $this->assertStringContainsString('env:prod', $payload);
        fclose($sock);
    }

    public function testHistogramFormat(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->histogram('lat', 0.005);
        rewind($sock);
        $this->assertSame('lat:0.005|h', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testGaugeFormat(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->gauge('q', 7);
        rewind($sock);
        $this->assertSame('q:7|g', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testLegacyStatsdDropsTags(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(dogstatsd: false, existingSocket: $sock);
        $b->counter('hits', 1, ['route' => '/x']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        $this->assertSame('hits:1|c', $payload);
        $this->assertStringNotContainsString('|#', $payload);
        fclose($sock);
    }

    public function testIntFormatStripsTrailingZeros(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('a', 5.0);
        rewind($sock);
        $this->assertSame('a:5|c', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testControlBytesAndPipesCannotForgeWireLines(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter("ev\nil:x|steal", 1.0, ['route' => "/a\nb", 'k|2' => 'v|3']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        fclose($sock);

        // One line, no smuggled separators: control bytes, inner `|`, commas
        // and spaces are all flattened in the name, and pair tokens carry no
        // comma/space/pipe/control either — a tag value can never forge a
        // second `key:value` pair inside the first. Colons inside values
        // survive (Datadog allows them once pair structure is guaranteed).
        $this->assertStringNotContainsString("\n", $payload);
        $this->assertSame(2, substr_count($payload, '|'), 'only the :value|kind and |# tags separators may survive');
        $this->assertSame('ev_il_x_steal:1|c|#route:/a_b,k_2:v_3', $payload);
    }

    public function testColonInTagKeyCannotForgeSecondPair(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('hits', 1.0, ['a:b' => 'v']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        fclose($sock);

        // `a:b:v` would let a first-colon split re-key the tag to `a`; the
        // key's colon is flattened at the wire choke, leaving exactly one
        // colon inside the tag segment (the structural key/value one).
        $this->assertSame('hits:1|c|#a_b:v', $payload);
        $tags = explode('|#', $payload, 2)[1];
        $this->assertSame(1, substr_count($tags, ':'), 'only the pair separator colon may survive in a tag');

        // Values keep their colons — the pair's first colon already owns
        // the separator, so a later one cannot forge a new pair.
        $sock2 = fopen('php://memory', 'w+');
        $b2 = new StatsdBackend(existingSocket: $sock2);
        $b2->counter('hits', 1.0, ['url' => 'https://example/x']);
        rewind($sock2);
        $payload2 = (string) stream_get_contents($sock2);
        fclose($sock2);
        $this->assertSame('hits:1|c|#url:https://example/x', $payload2);
    }

    public function testUpDownCounterEmitsSignedDelta(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->upDownCounter('conns', 1.0);
        rewind($sock);
        $this->assertSame('conns:+1|g', (string) stream_get_contents($sock));
        fclose($sock);

        $sock2 = fopen('php://memory', 'w+');
        $b2 = new StatsdBackend(existingSocket: $sock2);
        $b2->upDownCounter('conns', -1.0);
        rewind($sock2);
        $this->assertSame('conns:-1|g', (string) stream_get_contents($sock2));
        fclose($sock2);
    }

    public function testUpDownCounterEmitsZeroWithPlusSign(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->upDownCounter('delta', 0.0);
        rewind($sock);
        // StatsD treats +0 as a no-op delta, which is correct for a zero increment.
        $this->assertSame('delta:+0|g', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testAsyncCounterEmitsGaugeWithAbsoluteValue(): void
    {
        // A5: the absolute cumulative total rides `|g`, not `|c` — replaying
        // an asyncCounter sample as a |c delta quadratically inflates the
        // server-side aggregate. Absolute means no forced +/- sign either.
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->asyncCounter('jvm_gc_count', 1.0, ['gen' => 'young']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        $this->assertStringStartsWith('jvm_gc_count:1|g', $payload);
        $this->assertStringNotContainsString('+', $payload);
        $this->assertStringContainsString('|#', $payload);
        fclose($sock);
    }

    public function testAsyncGaugeEmitsGauge(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->asyncGauge('heap_used', 7.5, ['area' => 'old']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        $this->assertStringStartsWith('heap_used:7.5|g', $payload);
        $this->assertStringContainsString('|#', $payload);
        fclose($sock);
    }

    public function testRejectsNonResourceSocket(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StatsdBackend(existingSocket: 'not-a-resource');
    }

    public function testDescribeIsNoOp(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->describe(new \SugarCraft\Metrics\Descriptor('test', 'help', 'counter'));
        rewind($sock);
        $this->assertSame('', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testRemoveIsNoOp(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->remove('any', ['a' => 'b']); // Must not throw.
        $this->assertTrue(true);
        fclose($sock);
    }

    public function testClearIsNoOp(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->clear(); // Must not throw.
        $this->assertTrue(true);
        fclose($sock);
    }

    public function testFlushDrainsSocketBuffer(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('x', 1);
        $b->flush(); // Must not throw.
        $this->assertTrue(true);
        fclose($sock);
    }

    public function testFmtWithFloatDecimal(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('x', 1.234567);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        // fmt() uses %.6f then strips trailing zeros and trailing dot.
        $this->assertSame('x:1.234567|c', $payload);
        fclose($sock);
    }

    public function testFmtWithVeryLargeInteger(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        // abs($v) >= 1e15 → always rendered as float
        $b->counter('big', 1e15);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        // finding 018: the old assertion only looked for '|c' — vacuous
        // (a %.12g garbage regression stayed green). Byte-exact instead:
        // abs >= 1e15 routes through %.6e even when the value is whole.
        $this->assertSame('big:1.000000e+15|c', trim($payload));
        fclose($sock);
    }

    public function testFmtRoutesNonFiniteAndSubMicrosecondValuesThroughSharedTable(): void
    {
        // finding 018: docblocks promise every StatsD value passes
        // Util::nonFiniteToken/needsExponentForm before %.6f ever sees it.
        // Pin the wire bytes each way the render can rot.
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('neg', -\INF);
        rewind($sock);
        $this->assertSame('neg:-Inf|c', trim((string) stream_get_contents($sock)));
        fclose($sock);

        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('nn', NAN);
        rewind($sock);
        $this->assertSame('nn:NaN|c', trim((string) stream_get_contents($sock)));
        fclose($sock);

        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('tiny', 5e-7);
        rewind($sock);
        $this->assertSame('tiny:5.000000e-7|c', trim((string) stream_get_contents($sock)));
        fclose($sock);
    }

    public function testWireFoldsUnicodeSeparatorsAndKeepsValidMultibyteRaw(): void
    {
        // finding 063: the sweep named only byte-level framing bytes, so
        // U+2028 (LINE SEPARATOR) / U+2029 (PARAGRAPH SEPARATOR) rode raw —
        // a collector that decodes UTF-8 and then splits lines (Python
        // str.splitlines(), several JVM/JS stacks) saw two forged packets
        // from one datagram. They now fold like the rest of the alphabet,
        // and the losslessness guard runs alongside: valid multi-byte text
        // the grammar has no use for keeps its bytes.
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter("ok\u{2028}na\u{2029}me", 1.0, ['k' => "a\u{2028}b"]);
        rewind($sock);
        $wire = trim((string) stream_get_contents($sock));
        $this->assertStringNotContainsString("\xE2\x80\xA8", $wire);
        $this->assertStringNotContainsString("\xE2\x80\xA9", $wire);
        $this->assertSame('ok_na_me:1|c|#k:a_b', $wire);
        fclose($sock);

        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('café😀', 2.0, ['who' => 'naïve']);
        rewind($sock);
        $this->assertSame("café😀:2|c|#who:naïve", trim((string) stream_get_contents($sock)));
        fclose($sock);
    }

    public function testBracesFoldInNamesSoDispatchPrefixesCannotBeForged(): void
    {
        // finding 052: DogStatsD agents demultiplex the port BY LINE PREFIX,
        // and the service-check grammar `_sc{<check>}:<status>|...` needs no
        // byte the old sweep banned — gauge('_sc{dead.mans.switch}', 0.0)
        // emitted the exact bytes of a parseable OK-service-check with a
        // fully attacker-influenced name. Braces now fold at the name
        // position: the prefix never forms, while dots (idiomatic names)
        // and the tag grammar stay byte-identical.
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->gauge('_sc{dead.mans.switch}', 0.0);
        rewind($sock);
        $wire = trim((string) stream_get_contents($sock));
        $this->assertSame('_sc_dead.mans.switch_:0|g', $wire);
        $this->assertStringStartsWith('_sc_', $wire); // never the raw prefix
        fclose($sock);

        // The event grammar `_e{<l1>,<l2>}:...` was defeated only by the
        // incidental comma fold; braces close it directly.
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('_e{99,1}', 1.0);
        rewind($sock);
        $this->assertSame('_e_99_1_:1|c', trim((string) stream_get_contents($sock)));
        fclose($sock);

        // Byte-neutrality for well-formed traffic: no legal Prometheus name
        // contains braces, so ordinary names and tag positions are untouched.
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('http.requests_total', 1.0, ['route' => '/a{b}c']);
        rewind($sock);
        $this->assertSame('http.requests_total:1|c|#route:/a{b}c', trim((string) stream_get_contents($sock)));
        fclose($sock);
    }

    public function testWireFoldMemoStaysBoundedAndByteStableAcrossTheCap(): void
    {
        // sugar-metrics-015: the name/tag-key fold caches are StatsD's only
        // retained state and reset wholesale at WIRE_MEMO_LIMIT. A flood of
        // distinct names past the cap must (a) keep the cache bounded and
        // (b) still emit byte-exact packets for a name seen only after the
        // reset (re-derived cold).
        $limit = (new \ReflectionClass(StatsdBackend::class))->getConstant('WIRE_MEMO_LIMIT');
        $this->assertIsInt($limit);

        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        for ($i = 0; $i < $limit + 5; $i++) {
            $b->counter('flood_' . $i, 1.0);
        }
        $nameMemo = (new \ReflectionProperty(StatsdBackend::class, 'wireNames'))->getValue($b);
        $this->assertLessThanOrEqual($limit, count($nameMemo), 'fold cache never grows past the ceiling');

        // Byte-exactness once the caches have been reset past the ceiling:
        // every packet rode the wire correctly (the wire is not memo state,
        // it is what the sweep emitted), while the NAME cache was dropped
        // wholesale — early names are gone from it, recent names refilled.
        rewind($sock);
        $bytes = (string) stream_get_contents($sock);
        $this->assertStringContainsString('flood_0:1|c', $bytes);
        $this->assertStringContainsString('flood_1028:1|c', $bytes);
        $nameMemoAfter = (new \ReflectionProperty(StatsdBackend::class, 'wireNames'))->getValue($b);
        $this->assertArrayNotHasKey('flood_0', $nameMemoAfter, 'cache was dropped wholesale at the cap');
        $this->assertArrayHasKey('flood_1028', $nameMemoAfter);
        fclose($sock);

        // A fresh backend re-derives the fold cold and emits byte-identical
        // (the wire sweep folds `:` — dots/dashes ride raw by design).
        $sock2 = fopen('php://memory', 'w+');
        $b2 = new StatsdBackend(existingSocket: $sock2);
        $b2->counter('a:b', 2.0);
        rewind($sock2);
        $this->assertSame('a_b:2|c', trim((string) stream_get_contents($sock2)));
        $memo2 = (new \ReflectionProperty(StatsdBackend::class, 'wireNames'))->getValue($b2);
        $this->assertSame(['a:b' => 'a_b'], $memo2);
        fclose($sock2);
    }

    public function testDestructorClosesOwnedSocket(): void
    {
        // Hermetic: claim a free ephemeral UDP port on loopback and release
        // it before connecting, so the test never pokes a real statsd daemon
        // that may listen on the well-known 8125 of the machine running it.
        // (UDP endpoints need the explicit BIND flag; connect semantics are
        // meaningless for datagrams.)
        $probe = @stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $this->assertIsResource($probe, "ephemeral UDP port discovery failed: $errno $errstr");
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port, 'ephemeral UDP port discovery failed');

        $b = new StatsdBackend('127.0.0.1', $port);
        unset($b); // Destructor must not throw.
        $this->assertTrue(true);
    }

    public function testGaugeWithFloatValue(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->gauge('temp', 98.6);
        rewind($sock);
        $this->assertSame('temp:98.6|g', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testHistogramWithIntegerValue(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->histogram('count', 10);
        rewind($sock);
        $this->assertSame('count:10|h', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testCounterWithNoTags(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('hits', 1);
        rewind($sock);
        $this->assertSame('hits:1|c', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testTagValuesWithSpecialCharacters(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('hits', 1, ['path' => '/a/b', 'ver' => '1.0-stable']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        $this->assertStringStartsWith('hits:1|c', $payload);
        $this->assertStringContainsString('path:/a/b', $payload);
        $this->assertStringContainsString('ver:1.0-stable', $payload);
        fclose($sock);
    }

    /**
     * sugar-metrics-030 doctrine at the StatsD chokepoint: the wire renders
     * a true decimal point regardless of LC_NUMERIC — and here the trailing
     * rtrim of a comma render ('1,5') would hand wireToken a comma to fold
     * into '_', mutating the VALUE itself. Pure seam + structural pins;
     * the live comma arm rides the PrometheusFileBackend locale test.
     */
    public function testWireDecimalRenderingIsLocaleFree(): void
    {
        $norm = new \ReflectionMethod(StatsdBackend::class, 'normalizeDecimalPoint');
        $norm->setAccessible(true);
        self::assertSame('1.500000', $norm->invoke(null, '1,500000', ','));
        self::assertSame('1.500000', $norm->invoke(null, '1.500000', '.'), 'the dot identity path passes bytes through');

        $fmt = new \ReflectionMethod(StatsdBackend::class, 'formatDecimal');
        $fmt->setAccessible(true);
        self::assertSame('1.500000', $fmt->invoke(null, 1.5, '%.6f'));

        $method = new \ReflectionMethod(StatsdBackend::class, 'fmt');
        $lines  = file((string) $method->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringContainsString("self::formatDecimal(\$v, '%.6e')", $body);
        self::assertStringContainsString("rtrim(rtrim(self::formatDecimal(\$v, '%.6f'), '0'), '.')", $body, 'the rtrim shape (1.500000 -> 1.5) must ride the seam, not raw sprintf');
        $code = implode('', array_filter(
            explode("\n", $body),
            static fn (string $line): bool => !\str_starts_with(ltrim($line), '//')
        ));
        self::assertStringNotContainsString("sprintf('%.6", $code, 'bare locale-aware sprintf must not return (comment lines exempt)');
    }

    public function testFlushOnAClosedSocketIsQuietInBothPosturesAndTheLoudThrowIsADocumentedSafetyNet(): void
    {
        // sugar-metrics-041 + 064: injection validates at the door, so the
        // socket is closed AFTER construction — flush then sees a dead
        // handle. is_resource() short-circuits in BOTH postures: the loud
        // branch never fires on a non-resource handle (it is unreachable-
        // but-loud by design; fwrite owns real send failures on live ones).
        $sock = fopen('php://memory', 'w');
        $silent = new StatsdBackend(existingSocket: $sock, failSilently: true);
        $loud = new StatsdBackend(existingSocket: $sock, failSilently: false);
        fclose($sock);
        $silent->flush();
        $loud->flush();
        // A live socket flushes clean in loud mode (the no-op really is a
        // no-op on a healthy UDP-equivalent stream).
        $live = fopen('php://memory', 'w');
        $b = new StatsdBackend(existingSocket: $live, failSilently: false);
        $b->counter('x', 1);
        $b->flush();
        $this->assertStringContainsString('x:1|c', trim((string) stream_get_contents($live, 1024, 0)));
        fclose($live);
        // Wording-drift guards for the pre-made honesty decisions.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Backend/StatsdBackend.php');
        $this->assertStringContainsString('unreachable-but-loud', $src);
        $this->assertStringContainsString('deliberate no-op', $src);
        $this->assertStringContainsString('gethostbyname()', $src);
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $this->assertStringContainsString('gethostbyname()', $readme);
    }
}
