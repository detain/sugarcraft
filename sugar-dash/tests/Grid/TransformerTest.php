<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Transformer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use PHPUnit\Framework\TestCase;

final class TransformerTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTransformerImplementsSizer(): void
    {
        $transformer = Transformer::new($this->createMockItem('test'));
        $this->assertInstanceOf(Sizer::class, $transformer);
    }

    public function testTransformerImplementsItem(): void
    {
        $transformer = Transformer::new($this->createMockItem('test'));
        $this->assertInstanceOf(Item::class, $transformer);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultIsUpperCase(): void
    {
        $transformer = Transformer::new($this->createMockItem('hello world'));

        $rendered = $transformer->render();
        $this->assertSame('HELLO WORLD', $rendered);
    }

    public function testUpperCase(): void
    {
        $transformer = Transformer::new($this->createMockItem('hello World'))->withUpper();

        $rendered = $transformer->render();
        $this->assertSame('HELLO WORLD', $rendered);
    }

    public function testLowerCase(): void
    {
        $transformer = Transformer::new($this->createMockItem('HELLO World'))->withLower();

        $rendered = $transformer->render();
        $this->assertSame('hello world', $rendered);
    }

    public function testTitleCase(): void
    {
        $transformer = Transformer::new($this->createMockItem('HELLO World'))->withTitle();

        $rendered = $transformer->render();
        $this->assertSame('Hello world', $rendered);
    }

    public function testUpperFirst(): void
    {
        $transformer = Transformer::new($this->createMockItem('hello World'))->withUpperFirst();

        $rendered = $transformer->render();
        $this->assertSame('Hello world', $rendered);
    }

    public function testUpperWords(): void
    {
        $transformer = Transformer::new($this->createMockItem('hello world'))->withUpperWords();

        $rendered = $transformer->render();
        $this->assertSame('Hello World', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multiline content
    // ═══════════════════════════════════════════════════════════════

    public function testMultilineUpperCase(): void
    {
        $transformer = Transformer::new($this->createMockItem("hello\nworld"))->withUpper();

        $rendered = $transformer->render();
        $this->assertSame("HELLO\nWORLD", $rendered);
    }

    public function testMultilineLowerCase(): void
    {
        $transformer = Transformer::new($this->createMockItem("HELLO\nWORLD"))->withLower();

        $rendered = $transformer->render();
        $this->assertSame("hello\nworld", $rendered);
    }

    public function testMultilineTitleCase(): void
    {
        $transformer = Transformer::new($this->createMockItem("HELLO\nWORLD"))->withTitle();

        $rendered = $transformer->render();
        $this->assertSame("Hello\nWorld", $rendered);
    }

    public function testMultilineUpperWords(): void
    {
        $transformer = Transformer::new($this->createMockItem("hello world\nfoo bar"))->withUpperWords();

        $rendered = $transformer->render();
        $this->assertSame("Hello World\nFoo Bar", $rendered);
    }

    public function testEmptyLinesPreserved(): void
    {
        $transformer = Transformer::new($this->createMockItem("hello\n\nworld"))->withUpper();

        $rendered = $transformer->render();
        $this->assertSame("HELLO\n\nWORLD", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Empty content
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContentRendersEmpty(): void
    {
        $transformer = Transformer::new($this->createMockItem(''));

        $rendered = $transformer->render();
        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Unicode content
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodeUpperCase(): void
    {
        $transformer = Transformer::new($this->createMockItem('héllo wörld'))->withUpper();

        $rendered = $transformer->render();
        $this->assertSame('HÉLLO WÖRLD', $rendered);
    }

    public function testUnicodeLowerCase(): void
    {
        $transformer = Transformer::new($this->createMockItem('HÉLLO WÖRLD'))->withLower();

        $rendered = $transformer->render();
        $this->assertSame('héllo wörld', $rendered);
    }

    public function testUnicodeTitleCase(): void
    {
        $transformer = Transformer::new($this->createMockItem('HÉLLO WÖRLD'))->withTitle();

        $rendered = $transformer->render();
        $this->assertSame('Héllo wörld', $rendered);
    }

    public function testUnicodeUpperWords(): void
    {
        $transformer = Transformer::new($this->createMockItem('héllo wörld'))->withUpperWords();

        $rendered = $transformer->render();
        $this->assertSame('Héllo Wörld', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Transformer::new($this->createMockItem('test'));
        $resized = $original->setSize(10, 3);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeWithNoSetSizeReturnsContentSize(): void
    {
        $transformer = Transformer::new($this->createMockItem("short\nmedium length"));

        [$w, $h] = $transformer->getInnerSize();

        // Width should be the longest line (13 chars for "medium length")
        $this->assertSame(13, $w);
        // Height should be number of lines
        $this->assertSame(2, $h);
    }

    public function testGetInnerSizeWithSetSize(): void
    {
        $transformer = Transformer::new($this->createMockItem('test'))->setSize(10, 5);

        [$w, $h] = $transformer->getInnerSize();

        $this->assertSame(10, $w);
        $this->assertSame(5, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Wither chaining
    // ═══════════════════════════════════════════════════════════════

    public function testChainedWithers(): void
    {
        $transformer = Transformer::new($this->createMockItem('hello'))
            ->withLower()
            ->withUpper();

        $this->assertSame('HELLO', $transformer->render());
    }

    public function testTransformConstants(): void
    {
        $this->assertSame('upper', Transformer::Upper);
        $this->assertSame('lower', Transformer::Lower);
        $this->assertSame('title', Transformer::Title);
        $this->assertSame('upper_first', Transformer::UpperFirst);
        $this->assertSame('upper_words', Transformer::UpperWords);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSingleCharacter(): void
    {
        $transformer = Transformer::new($this->createMockItem('a'))->withUpper();

        $this->assertSame('A', $transformer->render());
    }

    public function testWhitespaceOnlyLines(): void
    {
        $transformer = Transformer::new($this->createMockItem("   \nhello\n   "))->withUpper();

        $rendered = $transformer->render();
        // Whitespace-only lines should remain as-is (transformed but still whitespace)
        $this->assertStringContainsString('HELLO', $rendered);
    }

    public function testNumbersAndSymbolsUnaffected(): void
    {
        $transformer = Transformer::new($this->createMockItem('hello123!@#'))->withUpper();

        $this->assertSame('HELLO123!@#', $transformer->render());
    }

    public function testUpperFirstOnlyFirstLetter(): void
    {
        // Only first letter of entire string should be uppercased
        $transformer = Transformer::new($this->createMockItem('HELLO WORLD'))->withUpperFirst();

        $this->assertSame('Hello world', $transformer->render());
    }

    public function testTitleCaseEachLine(): void
    {
        $transformer = Transformer::new($this->createMockItem("FIRST LINE\nsecond line"))->withTitle();

        $this->assertSame("First line\nSecond line", $transformer->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Mock Item helper
    // ═══════════════════════════════════════════════════════════════

    private function createMockItem(string $content): Item
    {
        $mock = $this->createMock(Item::class);
        $mock->method('render')->willReturn($content);

        return $mock;
    }
}
