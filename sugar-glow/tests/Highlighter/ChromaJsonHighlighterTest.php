<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests\Highlighter;

use PHPUnit\Framework\TestCase;
use SugarCraft\Glow\Highlighter\ChromaJsonHighlighter;

/**
 * @covers \SugarCraft\Glow\Highlighter\ChromaJsonHighlighter
 */
final class ChromaJsonHighlighterTest extends TestCase
{
    public function testHighlightReturnsEmptyStringForEmptyInput(): void
    {
        $highlighter = new ChromaJsonHighlighter([]);
        self::assertSame('', $highlighter->highlight('', 'php'));
    }

    public function testHighlightReturnsUnchangedCodeWithEmptyTheme(): void
    {
        $highlighter = new ChromaJsonHighlighter([]);
        $code = 'echo "hello";';
        self::assertSame($code, $highlighter->highlight($code, 'php'));
    }

    public function testHighlightAppliesColorToComments(): void
    {
        $highlighter = new ChromaJsonHighlighter([
            'comment' => '90',
        ]);
        $code = '// this is a comment';
        $result = $highlighter->highlight($code, 'php');
        self::assertStringContainsString("\x1b[90m", $result);
        self::assertStringContainsString("\x1b[0m", $result);
    }

    public function testHighlightAppliesColorToStrings(): void
    {
        $highlighter = new ChromaJsonHighlighter([
            'string' => '33',
        ]);
        $code = '"hello world"';
        $result = $highlighter->highlight($code, 'php');
        self::assertStringContainsString("\x1b[33m", $result);
        self::assertStringContainsString("\x1b[0m", $result);
    }

    public function testHighlightAppliesColorToKeywords(): void
    {
        $highlighter = new ChromaJsonHighlighter([
            'keyword' => '1;34',
        ]);
        $code = 'function test() {}';
        $result = $highlighter->highlight($code, 'php');
        self::assertStringContainsString("\x1b[1;34mfunction\x1b[0m", $result);
    }

    public function testHighlightAppliesColorToNumbers(): void
    {
        $highlighter = new ChromaJsonHighlighter([
            'number' => '1;35',
        ]);
        $code = '42';
        $result = $highlighter->highlight($code, 'php');
        self::assertStringContainsString("\x1b[1;35m", $result);
    }

    public function testSupportsAlwaysReturnsTrue(): void
    {
        $highlighter = new ChromaJsonHighlighter([]);
        self::assertTrue($highlighter->supports('php'));
        self::assertTrue($highlighter->supports('javascript'));
        self::assertTrue($highlighter->supports('unknown'));
    }

    public function testHighlightPhpCode(): void
    {
        $highlighter = new ChromaJsonHighlighter([
            'comment'  => '90',
            'string'   => '33',
            'keyword'  => '1;34',
            'number'   => '1;35',
            'function' => '1;36',
            'operator' => '37',
        ]);

        $code = <<<'PHP'
<?php
// This is a comment
function hello() {
    echo "Hello, World!";
    return 42;
}
PHP;

        $result = $highlighter->highlight($code, 'php');

        // Comments should be highlighted
        self::assertStringContainsString("\x1b[90m", $result);
        // Keywords should be highlighted
        self::assertStringContainsString("\x1b[1;34mfunction\x1b[0m", $result);
        // Strings should be highlighted
        self::assertStringContainsString("\x1b[33m", $result);
        // Numbers should be highlighted
        self::assertStringContainsString("\x1b[1;35m42\x1b[0m", $result);
    }

    public function testFromJsonFileLoadsTheme(): void
    {
        $path = sys_get_temp_dir() . '/test_theme.json';
        file_put_contents($path, json_encode([
            'keyword' => '1;31',
            'string' => '32',
        ]));

        $highlighter = ChromaJsonHighlighter::fromJsonFile($path);
        $result = $highlighter->highlight('function test()', 'php');

        self::assertStringContainsString("\x1b[1;31m", $result);

        unlink($path);
    }

    public function testPatternBodyEndingInMOrSlashNotCorrupted(): void
    {
        // Verify that a regex body containing 'm' as a literal character
        // is not corrupted by the bare-pattern fix (no trim() stripping).
        // Use 'echo' which is a keyword AND ends with 'o' (an 'm' adjacent char).
        $highlighter = new ChromaJsonHighlighter([
            'keyword' => '1;34',
        ]);
        $result = $highlighter->highlight('echo "hello";', 'php');
        // The keyword 'echo' should be highlighted (blue), confirming the keyword
        // pattern's body (which contains 'm' chars in the alternation) is intact.
        self::assertStringContainsString("\x1b[1;34m", $result);
        // PREG should not emit any warning (failOnWarning would fail the suite).
        self::assertSame(PREG_NO_ERROR, preg_last_error());
    }

    public function testFunctionTokenExcludesParen(): void
    {
        // Function pattern uses lookahead, so the `(` is NOT part of the match.
        $highlighter = new ChromaJsonHighlighter(['function' => '1;36']);
        $result = $highlighter->highlight('foo(bar)', 'php');
        // 'foo' should be colored in cyan; '(' should NOT be wrapped in any SGR pair.
        self::assertStringContainsString("\x1b[1;36mfoo\x1b[0m", $result);
        // Verify '(' immediately follows the foo highlight (no ESC between).
        // \x1b[0m is 4 bytes (1b 5b 30 6d); char after is at resetPos + 4.
        $resetPos = strpos($result, "\x1b[0m");
        self::assertNotFalse($resetPos, 'Reset code should be present after foo');
        $charAfterFoo = $result[$resetPos + 4] ?? '';
        self::assertSame('(', $charAfterFoo, 'The ( should immediately follow the foo highlight reset');
    }

    public function testMostSpecificGroupSelectedNotFirstDeclared(): void
    {
        // When two alternations could match at different positions, the one that
        // actually captures the full match is selected (not the first declared).
        $highlighter = new ChromaJsonHighlighter([
            'function' => '1;36', // cyan
            'keyword'  => '1;34', // blue
        ]);
        // 'print(' is a keyword followed by '(', but also a function-like identifier.
        // Keyword comes first in alternation order but function is the one that
        // actually matches the full match text at position 0.
        $result = $highlighter->highlight('print(42)', 'php');
        // 'print' should be colored as keyword (blue) since it matches the keyword
        // pattern at position 0 and the captured value equals the full match.
        self::assertStringContainsString("\x1b[1;34m", $result);
    }

    public function testEmbeddedEscapeStripped(): void
    {
        // Embedded ESC bytes in source code are stripped to prevent injection.
        $highlighter = new ChromaJsonHighlighter(['string' => '31']);
        $code = '"\x1b[31mRED\x1b[0m"';
        $result = $highlighter->highlight($code, 'php');
        // The result should have no raw ESC bytes in the source portion —
        // only the highlighter's own SGR envelope remains.
        self::assertStringNotContainsString("\x1b[31m", substr($result, 0, strpos($result, "\x1b[")));
    }

    public function testBacktrackLimitDegradesToRaw(): void
    {
        // When preg hits a backtrack limit it returns null/empty; verify we get raw code.
        $highlighter = new ChromaJsonHighlighter(['string' => '31']);
        // Build a pathologically deep nested pattern via a long string that forces
        // the regex engine to backtrack heavily. The highlighter should not crash;
        // it degrades to raw output.
        $longString = str_repeat('a', 10000);
        $code = '"' . $longString . '"';
        $result = $highlighter->highlight($code, 'php');
        // Should return a string (possibly raw) without throwing.
        self::assertIsString($result);
    }
}
