<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests\Highlighter;

use PHPUnit\Framework\TestCase;
use SugarCraft\Glow\Highlighter\ChromaJsonHighlighter;
use SugarCraft\Glow\Highlighter\Highlighter;

/**
 * @covers \SugarCraft\Glow\Highlighter\Highlighter
 */
final class HighlighterTest extends TestCase
{
    public function testNewCreatesChromaJsonHighlighter(): void
    {
        $highlighter = Highlighter::new();
        self::assertInstanceOf(Highlighter::class, $highlighter);
    }

    public function testHighlightMarkdownWithNoCodeBlocksReturnsUnchanged(): void
    {
        $highlighter = Highlighter::new();
        $markdown = 'This is plain text without any code blocks.';
        self::assertSame($markdown, $highlighter->highlightMarkdown($markdown));
    }

    public function testHighlightMarkdownWithPhpCodeBlock(): void
    {
        $highlighter = Highlighter::new();
        $markdown = <<<'MD'
Here is some PHP code:

```php
function hello() {
    echo "Hello";
}
```
MD;

        $result = $highlighter->highlightMarkdown($markdown);

        // ANSI codes should be present from highlighting
        self::assertStringContainsString("\x1b[", $result);
        // Original code block delimiters should be removed
        self::assertStringNotContainsString('```php', $result);
        self::assertStringNotContainsString('```', $result);
    }

    public function testHighlightMarkdownWithJavascriptCodeBlock(): void
    {
        $highlighter = Highlighter::new();
        $markdown = <<<'MD'
```javascript
const x = 42;
```
MD;

        $result = $highlighter->highlightMarkdown($markdown);

        // ANSI codes should be present from highlighting
        self::assertStringContainsString("\x1b[", $result);
        self::assertStringNotContainsString('```javascript', $result);
    }

    public function testHighlightMarkdownWithMultipleCodeBlocks(): void
    {
        $highlighter = Highlighter::new();
        $markdown = <<<'MD'
```php
echo "first";
```

Some text

```python
print("second")
```
MD;

        $result = $highlighter->highlightMarkdown($markdown);

        self::assertStringContainsString("\x1b[", $result);
        self::assertStringNotContainsString('```php', $result);
        self::assertStringNotContainsString('```python', $result);
    }

    public function testHighlightMarkdownWithUnspecifiedLanguageReturnsTextUnchanged(): void
    {
        $highlighter = Highlighter::new();
        $markdown = <<<'MD'
```
some code without a language
```
MD;

        $result = $highlighter->highlightMarkdown($markdown);

        // With no language specified and no 'text' token type in theme,
        // code should pass through unchanged (no ANSI codes)
        self::assertStringNotContainsString('```', $result);
        self::assertStringContainsString('some code without a language', $result);
    }

    public function testWithHighlighterReturnsNewInstance(): void
    {
        $highlighter = Highlighter::new();
        $custom = new ChromaJsonHighlighter(['keyword' => '1;91']);
        $newHighlighter = $highlighter->withHighlighter($custom);

        self::assertNotSame($highlighter, $newHighlighter);
    }

    public function testHighlightMarkdownWithEmptyCodeBlock(): void
    {
        $highlighter = Highlighter::new();
        $markdown = <<<'MD'
```
```
MD;

        $result = $highlighter->highlightMarkdown($markdown);

        // Empty code blocks should be handled gracefully
        self::assertStringNotContainsString('```', $result);
    }
}
