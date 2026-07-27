<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Keys;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Keys\Category;

final class CategoryTest extends TestCase
{
    public function testAllCategoryCases(): void
    {
        $cases = Category::cases();

        $this->assertCount(5, $cases);
    }

    public function testNavigationCase(): void
    {
        $cat = Category::Navigation;

        $this->assertSame('navigation', $cat->value);
        $this->assertSame('Navigation', $cat->label());
    }

    public function testEditingCase(): void
    {
        $cat = Category::Editing;

        $this->assertSame('editing', $cat->value);
        $this->assertSame('Editing', $cat->label());
    }

    public function testViewCase(): void
    {
        $cat = Category::View;

        $this->assertSame('view', $cat->value);
        $this->assertSame('View', $cat->label());
    }

    public function testGeneralCase(): void
    {
        $cat = Category::General;

        $this->assertSame('general', $cat->value);
        $this->assertSame('General', $cat->label());
    }

    public function testDebugCase(): void
    {
        $cat = Category::Debug;

        $this->assertSame('debug', $cat->value);
        $this->assertSame('Debug', $cat->label());
    }
}
