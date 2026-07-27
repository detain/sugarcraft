<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests\Render;

use SugarCraft\Shine\Render\BlockKind;
use PHPUnit\Framework\TestCase;

final class BlockKindTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $cases = BlockKind::cases();
        $this->assertCount(8, $cases);
    }

    public function testDocumentCase(): void
    {
        $this->assertSame('Document', BlockKind::Document->name);
    }

    public function testHeadingCase(): void
    {
        $this->assertSame('Heading', BlockKind::Heading->name);
    }

    public function testParagraphCase(): void
    {
        $this->assertSame('Paragraph', BlockKind::Paragraph->name);
    }

    public function testBlockQuoteCase(): void
    {
        $this->assertSame('BlockQuote', BlockKind::BlockQuote->name);
    }

    public function testListCase(): void
    {
        $this->assertSame('List', BlockKind::List->name);
    }

    public function testListItemCase(): void
    {
        $this->assertSame('ListItem', BlockKind::ListItem->name);
    }

    public function testCodeBlockCase(): void
    {
        $this->assertSame('CodeBlock', BlockKind::CodeBlock->name);
    }

    public function testTableCase(): void
    {
        $this->assertSame('Table', BlockKind::Table->name);
    }

    public function testAllCasesAreDistinct(): void
    {
        $cases = BlockKind::cases();
        $names = array_map(fn($c) => $c->name, $cases);
        $this->assertSame(count($cases), count(array_unique($names)));
    }

    public function testCaseNameMatchesExpected(): void
    {
        $expected = ['Document', 'Heading', 'Paragraph', 'BlockQuote', 'List', 'ListItem', 'CodeBlock', 'Table'];
        $actual = array_map(fn($c) => $c->name, BlockKind::cases());
        $this->assertSame($expected, $actual);
    }
}
