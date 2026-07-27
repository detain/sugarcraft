<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Paginator;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Paginator\Paginator;
use SugarCraft\Bits\Paginator\Type;

/**
 * Tests for {@see Type} — enum controlling Paginator rendering mode.
 */
final class TypeTest extends TestCase
{
    public function testEnumCases(): void
    {
        $this->assertSame('dots', Type::Dots->value);
        $this->assertSame('arabic', Type::Arabic->value);
    }

    public function testDotsCaseName(): void
    {
        $this->assertSame('Dots', Type::Dots->name);
        $this->assertSame('Arabic', Type::Arabic->name);
    }

    public function testDotsCaseRendersDotStyle(): void
    {
        $p = Paginator::new()
            ->withPerPage(10)
            ->withTotalItems(30)
            ->withType(Type::Dots);

        $view = $p->view();
        // Dots mode: filled/inactive dots
        $this->assertStringContainsString('●', $view);
        $this->assertStringContainsString('○', $view);
        $this->assertStringNotContainsString('/', $view);
    }

    public function testArabicCaseRendersFractionStyle(): void
    {
        $p = Paginator::new()
            ->withPerPage(10)
            ->withTotalItems(30)
            ->withType(Type::Arabic);

        $view = $p->view();
        // Arabic mode: "1/3" style
        $this->assertStringContainsString('1/3', $view);
        $this->assertStringNotContainsString('●', $view);
        $this->assertStringNotContainsString('○', $view);
    }

    public function testArabicPageNumbersAreOneIndexed(): void
    {
        $p = Paginator::new()
            ->withPerPage(10)
            ->withTotalItems(30)
            ->withType(Type::Arabic);

        // Page 0 → "1/3"
        $this->assertSame('1/3', $p->view());

        // Page 1 → "2/3"
        $p2 = $p->withPage(1);
        $this->assertSame('2/3', $p2->view());

        // Page 2 → "3/3"
        $p3 = $p->withPage(2);
        $this->assertSame('3/3', $p3->view());
    }

    public function testTypeRoundTrips(): void
    {
        $p1 = Paginator::new()->withType(Type::Arabic);
        $this->assertSame(Type::Arabic, $p1->type);

        $p2 = $p1->withType(Type::Dots);
        $this->assertSame(Type::Dots, $p2->type);
    }

    public function testImmutability(): void
    {
        $p1 = Paginator::new()->withType(Type::Dots);
        $p2 = $p1->withType(Type::Arabic);

        $this->assertSame(Type::Dots, $p1->type);
        $this->assertSame(Type::Arabic, $p2->type);
    }
}
