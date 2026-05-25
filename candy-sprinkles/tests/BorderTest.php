<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use SugarCraft\Sprinkles\Border;
use PHPUnit\Framework\TestCase;

final class BorderTest extends TestCase
{
    public function testNormalRunes(): void
    {
        $b = Border::normal();
        $this->assertSame('─', $b->top);
        $this->assertSame('│', $b->left);
        $this->assertSame('┌', $b->topLeft);
        $this->assertSame('┘', $b->bottomRight);
    }

    public function testRoundedHasArcCorners(): void
    {
        $b = Border::rounded();
        $this->assertSame('╭', $b->topLeft);
        $this->assertSame('╮', $b->topRight);
        $this->assertSame('╰', $b->bottomLeft);
        $this->assertSame('╯', $b->bottomRight);
    }

    public function testThickAndDoubleAreDistinct(): void
    {
        $this->assertSame('━', Border::thick()->top);
        $this->assertSame('═', Border::double()->top);
    }

    public function testHiddenBorderIsAllSpaces(): void
    {
        $b = Border::hidden();
        $this->assertSame(' ', $b->top);
        $this->assertSame(' ', $b->topLeft);
        $this->assertSame(' ', $b->bottomRight);
    }

    public function testAsciiBorder(): void
    {
        $b = Border::ascii();
        $this->assertSame('-', $b->top);
        $this->assertSame('|', $b->left);
        $this->assertSame('+', $b->topLeft);
    }

    public function testCustomBorder(): void
    {
        $b = new Border('1', '2', '3', '4', '5', '6', '7', '8');
        $this->assertSame('1', $b->top);
        $this->assertSame('8', $b->bottomRight);
    }

    public function testCatalogIsNonEmptyListOfStrings(): void
    {
        $catalog = Border::catalog();
        $this->assertNotEmpty($catalog);
        $this->assertSame(array_values($catalog), $catalog, 'catalog must be a list');
        foreach ($catalog as $name) {
            $this->assertIsString($name);
        }
    }

    public function testEveryCatalogEntryIsARealBorderFactory(): void
    {
        foreach (Border::catalog() as $name) {
            $this->assertTrue(
                method_exists(Border::class, $name),
                "catalog entry '{$name}' must be a static factory on Border",
            );
            $border = Border::{$name}();
            $this->assertInstanceOf(Border::class, $border, "Border::{$name}() must return a Border");
        }
    }
}
