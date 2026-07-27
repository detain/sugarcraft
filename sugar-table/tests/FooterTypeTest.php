<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\FooterType;
use PHPUnit\Framework\TestCase;

final class FooterTypeTest extends TestCase
{
    public function testCasesReturnsThreeCases(): void
    {
        $this->assertCount(3, FooterType::cases());
    }

    public function testPageCaseValue(): void
    {
        $this->assertSame('page', FooterType::Page->value);
    }

    public function testRowsCaseValue(): void
    {
        $this->assertSame('rows', FooterType::Rows->value);
    }

    public function testBothCaseValue(): void
    {
        $this->assertSame('both', FooterType::Both->value);
    }

    public function testFromValidValuePage(): void
    {
        $this->assertSame(FooterType::Page, FooterType::from('page'));
    }

    public function testFromValidValueRows(): void
    {
        $this->assertSame(FooterType::Rows, FooterType::from('rows'));
    }

    public function testFromValidValueBoth(): void
    {
        $this->assertSame(FooterType::Both, FooterType::from('both'));
    }

    public function testTryFromValidValue(): void
    {
        $this->assertSame(FooterType::Page, FooterType::tryFrom('page'));
        $this->assertSame(FooterType::Rows, FooterType::tryFrom('rows'));
        $this->assertSame(FooterType::Both, FooterType::tryFrom('both'));
    }

    public function testTryFromInvalidValueReturnsNull(): void
    {
        $this->assertNull(FooterType::tryFrom('invalid'));
        $this->assertNull(FooterType::tryFrom(''));
        $this->assertNull(FooterType::tryFrom('Page'));
    }

    public function testFromThrowsOnInvalidValue(): void
    {
        $this->expectException(\ValueError::class);
        FooterType::from('bad');
    }
}
