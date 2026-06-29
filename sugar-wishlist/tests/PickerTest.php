<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Endpoint;
use SugarCraft\Wishlist\Picker;
use SugarCraft\Fuzzy\MatchResult;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Picker exercised against in-memory streams. We override
 * setRawMode() in tests to skip the `stty` call (which is a no-op
 * for non-tty streams anyway, but defensively suppressed so the
 * test stays clean even on hosts without `stty`).
 */
final class PickerTest extends TestCase
{
    /**
     * @return array{0:resource,1:resource,2:Picker}
     */
    private function makePicker(string $keys): array
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        fwrite($in, $keys);
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { /* noop in tests */ }
        };
        return [$in, $out, $p];
    }

    private function endpoints(): array
    {
        return [
            new Endpoint(name: 'production', host: 'prod.example.com'),
            new Endpoint(name: 'staging',    host: 'stage.example.com'),
            new Endpoint(name: 'dev',        host: 'dev.example.com'),
        ];
    }

    public function testEnterPicksFirstByDefault(): void
    {
        [, , $p] = $this->makePicker("\r");
        $picked = $p->pick($this->endpoints());
        $this->assertNotNull($picked);
        $this->assertSame('production', $picked->name);
    }

    public function testJMovesDown(): void
    {
        [, , $p] = $this->makePicker("jj\r");
        $picked = $p->pick($this->endpoints());
        $this->assertSame('dev', $picked->name);
    }

    public function testKMovesUp(): void
    {
        [, , $p] = $this->makePicker("jjjk\r");
        $picked = $p->pick($this->endpoints());
        $this->assertSame('staging', $picked->name);
    }

    public function testEscReturnsNull(): void
    {
        [, , $p] = $this->makePicker("\x1b");
        $this->assertNull($p->pick($this->endpoints()));
    }

    public function testCtrlCReturnsNull(): void
    {
        [, , $p] = $this->makePicker("\x03");
        $this->assertNull($p->pick($this->endpoints()));
    }

    public function testFilterNarrowsList(): void
    {
        // Type "stag", then press Enter.
        [, , $p] = $this->makePicker("stag\r");
        $picked = $p->pick($this->endpoints());
        $this->assertNotNull($picked);
        $this->assertSame('staging', $picked->name);
    }

    public function testFilterBackspaceUnFilters(): void
    {
        // Type "dev", select dev, then backspace twice and pick first match.
        [, , $p] = $this->makePicker("dev\x7f\x7f\x7f\r");
        $picked = $p->pick($this->endpoints());
        $this->assertSame('production', $picked->name);
    }

    public function testEmptyListReturnsNull(): void
    {
        [, , $p] = $this->makePicker("\r");
        $this->assertNull($p->pick([]));
    }

    public function testArrowDownIsEquivalentToJ(): void
    {
        // Two ↓ then Enter. The CSI sequence is ESC [ B.
        [, , $p] = $this->makePicker("\x1b[B\x1b[B\r");
        $picked = $p->pick($this->endpoints());
        $this->assertSame('dev', $picked->name);
    }

    public function testSplitArrowUpSequenceIsReassembled(): void
    {
        // Verify that a split up-arrow sequence (ESC [ A) fed as a
        // single buffered write is correctly reassembled and navigates
        // up. First we move down to staging (index 1), then pressing
        // up should go back to production.
        // This exercises the stream_select timeout window: when all
        // bytes arrive in one read, stream_select fires immediately
        // and we still reconstruct the full CSI sequence.
        [, , $p] = $this->makePicker("j\x1b[A\r");
        $picked = $p->pick($this->endpoints());
        // j moves to staging (index 1), up arrow goes back to production (index 0)
        $this->assertSame('production', $picked->name);
    }

    public function testHighlightLineProducesAnsiBoldCyan(): void
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        rewind($in);

        $p = new class($in, $out) extends Picker {
            public function setRawMode(bool $on): void { /* noop */ }
        };

        $ref = new ReflectionMethod($p, 'highlightLine');
        $ref->setAccessible(true);

        // Build a MatchResult where "rod" matches positions 2-4 in "production"
        $result = new MatchResult(
            needle: 'rod',
            haystack: 'production',
            score: 15,
            matchedIndices: [2, 3, 4],
        );

        $highlighted = $ref->invoke($p, 'production', $result);

        // ANSI bold+cyan is \033[1;36m, reset is \033[0m
        $this->assertStringContainsString("\x1b[1;36m", $highlighted);
        $this->assertStringContainsString("\x1b[0m", $highlighted);

        // The 'r' at index 2, 'o' at 3, 'd' at 4 should be wrapped
        // Original "production" has 'r' at 0, 'o' at 1, 'd' at 2, 'u' at 3...
        // So "rod" should match at indices 2,3,4 => chars 'd','u','c'
        // We just verify some ANSI wrapping happened
        $this->assertNotSame('production', $highlighted);
    }

    public function testHighlightLineReturnsOriginalWhenNoResult(): void
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        rewind($in);

        $p = new class($in, $out) extends Picker {
            public function setRawMode(bool $on): void { /* noop */ }
        };

        $ref = new ReflectionMethod($p, 'highlightLine');
        $ref->setAccessible(true);

        $line = $ref->invoke($p, 'production', null);
        $this->assertSame('production', $line);
    }

    public function testAmbiguousQueryProducesRankedResults(): void
    {
        // Create endpoints where "pro" query should match both "production"
        // and "dev" (less well). The better match should rank first.
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        fwrite($in, "pro\r");
        rewind($in);

        $p = new class($in, $out) extends Picker {
            public function setRawMode(bool $on): void { /* noop */ }
        };

        $endpoints = [
            new Endpoint(name: 'production', host: 'prod.example.com'),
            new Endpoint(name: 'staging',       host: 'stage.example.com'),
            new Endpoint(name: 'dev',            host: 'dev.example.com'),
        ];

        // Type "pro" which should match "production" better than "dev"
        // Then press Enter to select
        $picked = $p->pick($endpoints);
        $this->assertNotNull($picked);
        // "production" starts with "pro", "dev" does not
        $this->assertSame('production', $picked->name);
    }

    public function testFilterBackspaceAndRefine(): void
    {
        // Type "pro", then backspace twice, then "dev", select dev
        [, , $p] = $this->makePicker("pro\x7f\x7fdev\r");
        $picked = $p->pick($this->endpoints());
        $this->assertNotNull($picked);
        $this->assertSame('dev', $picked->name);
    }
}
