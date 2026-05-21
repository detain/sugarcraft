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
}
