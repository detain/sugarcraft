<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use SugarCraft\Fuzzy\Highlighter;
use SugarCraft\Fuzzy\MatchResult;
use PHPUnit\Framework\TestCase;

final class HighlighterTest extends TestCase
{
    private Highlighter $highlighter;

    protected function setUp(): void
    {
        $this->highlighter = new Highlighter();
    }

    public function testHighlightSingleMatch(): void
    {
        $result = new MatchResult('foo', 'foobar', 10, [0, 1, 2]);

        $output = $this->highlighter->highlight($result, fn($m) => "<b>$m</b>");

        $this->assertSame('<b>foo</b>bar', $output);
    }

    public function testHighlightPartialMatch(): void
    {
        // Indices [1, 3, 4] with consecutive 3,4 means two runs: [1,1]='o' and [3,4]='ba'
        $result = new MatchResult('oba', 'foobar', 10, [1, 3, 4]);

        $output = $this->highlighter->highlight($result, fn($m) => "[$m]");

        $this->assertSame('f[o]o[ba]r', $output);
    }

    public function testHighlightEmptyIndices(): void
    {
        $result = new MatchResult('xyz', 'foobar', 0, []);

        $output = $this->highlighter->highlight($result, fn($m) => "<b>$m</b>");

        $this->assertSame('foobar', $output);
    }

    public function testHighlightConsecutiveRun(): void
    {
        $result = new MatchResult('ello', 'hello', 15, [1, 2, 3, 4]);

        $output = $this->highlighter->highlight($result, fn($m) => "*$m*");

        $this->assertSame('h*ello*', $output);
    }

    public function testHighlightMultipleRuns(): void
    {
        // Indices [0, 4] are not consecutive, so two separate runs
        $result = new MatchResult('ab', 'aXXbYY', 10, [0, 4]);

        $output = $this->highlighter->highlight($result, fn($m) => "[$m]");

        $this->assertSame('[a]XXb[Y]Y', $output);
    }

    public function testHighlightPreservesUnmatchedContent(): void
    {
        $result = new MatchResult('bc', 'abcdef', 10, [1, 2]);

        $output = $this->highlighter->highlight($result, fn($m) => "<mark>$m</mark>");

        $this->assertSame('a<mark>bc</mark>def', $output);
    }

    public function testHighlightWithEmptyStyler(): void
    {
        $result = new MatchResult('foo', 'foobar', 10, [0, 1, 2]);

        $output = $this->highlighter->highlight($result, fn($m) => $m);

        $this->assertSame('foobar', $output);
    }

    public function testHighlightUtf8(): void
    {
        $result = new MatchResult('中文', '中文字符', 10, [0]);

        $output = $this->highlighter->highlight($result, fn($m) => "{$m}");

        $this->assertSame('中文字符', $output);
    }

    public function testHighlightUtf8Partial(): void
    {
        $result = new MatchResult('文字', '中文字符', 10, [1, 2]);

        $output = $this->highlighter->highlight($result, fn($m) => "[$m]");

        $this->assertSame('中[文字]符', $output);
    }
}
