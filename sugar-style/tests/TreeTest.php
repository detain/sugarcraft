<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;
use SugarCraft\Style\Tree\Enumerator as TreeEnumerator;
use SugarCraft\Style\Tree\Tree;
use PHPUnit\Framework\TestCase;

final class TreeTest extends TestCase
{
    public function testEmptyTree(): void
    {
        $this->assertSame('', Tree::new()->render());
    }

    public function testFlatChildren(): void
    {
        $out = Tree::new()
            ->root('root')
            ->child('a')
            ->child('b')
            ->child('c')
            ->render();
        $this->assertSame(
            "root\n├── a\n├── b\n└── c",
            $out,
        );
    }

    public function testNestedTree(): void
    {
        $out = Tree::new()
            ->root('Documents')
            ->child(
                Tree::new()
                    ->root('Travel')
                    ->child('Italy.md')
                    ->child('Japan.md'),
            )
            ->child('Resume.pdf')
            ->render();

        $expected =
            "Documents\n"
          . "├── Travel\n"
          . "│   ├── Italy.md\n"
          . "│   └── Japan.md\n"
          . "└── Resume.pdf";
        $this->assertSame($expected, $out);
    }

    public function testDeeplyNestedLastBranchUsesSpacePrefix(): void
    {
        $out = Tree::new()
            ->root('a')
            ->child(
                Tree::new()
                    ->root('b')
                    ->child(
                        Tree::new()
                            ->root('c')
                            ->child('d'),
                    ),
            )
            ->render();

        $expected =
            "a\n"
          . "└── b\n"
          . "    └── c\n"
          . "        └── d";
        $this->assertSame($expected, $out);
    }

    public function testMultiLineLeafIndents(): void
    {
        $out = Tree::new()
            ->root('r')
            ->child("multi\nline")
            ->render();
        $expected =
            "r\n"
          . "└── multi\n"
          . "    line";
        $this->assertSame($expected, $out);
    }

    public function testRootlessTree(): void
    {
        // No root → just the children at top level.
        $out = Tree::new()
            ->child('a')
            ->child('b')
            ->render();
        $this->assertSame("├── a\n└── b", $out);
    }

    public function testChildrenVariadic(): void
    {
        $out = Tree::new()
            ->root('r')
            ->children('a', 'b')
            ->render();
        $this->assertSame("r\n├── a\n└── b", $out);
    }

    public function testRoundedEnumerator(): void
    {
        $out = Tree::new()
            ->root('r')
            ->child('a')
            ->child('b')
            ->enumerator(TreeEnumerator::rounded())
            ->render();
        $this->assertSame("r\n├── a\n╰── b", $out);
    }

    public function testAsciiEnumerator(): void
    {
        $out = Tree::new()
            ->root('r')
            ->child('a')
            ->child('b')
            ->enumerator(TreeEnumerator::ascii())
            ->render();
        $this->assertSame("r\n|-- a\n`-- b", $out);
    }

    public function testHideRoot(): void
    {
        $out = Tree::new()
            ->root('r')
            ->child('a')
            ->child('b')
            ->hide()
            ->render();
        $this->assertSame("├── a\n└── b", $out);
    }

    public function testRootStyleApplies(): void
    {
        $out = Tree::new()
            ->root('r')
            ->child('a')
            ->rootStyle(Style::new()->bold()->colorProfile(ColorProfile::Ansi))
            ->render();
        $this->assertStringContainsString("\x1b[1mr\x1b[0m", $out);
    }

    public function testItemStyleApplies(): void
    {
        $out = Tree::new()
            ->root('r')
            ->child('a')
            ->child('b')
            ->itemStyle(Style::new()->italic()->colorProfile(ColorProfile::Ansi))
            ->render();
        // Two items -> two italic SGRs.
        $this->assertSame(2, substr_count($out, "\x1b[3m"));
    }

    public function testEnumeratorStyleApplies(): void
    {
        $out = Tree::new()
            ->root('r')
            ->child('a')
            ->child('b')
            ->enumeratorStyle(Style::new()->bold()->colorProfile(ColorProfile::Ansi))
            ->render();
        $this->assertSame(2, substr_count($out, "\x1b[1m"));
    }

    public function testCustomIndenter(): void
    {
        $out = Tree::new()
            ->root('r')
            ->child(Tree::new()->root('p')->child('x'))
            ->indenter(static fn(bool $isLast): string => '....')
            ->render();
        // Inner tree's continuation lines use '....' regardless of isLast (because
        // the closure here ignores it).
        $expected = "r\n└── p\n....└── x";
        $this->assertSame($expected, $out);
    }

    public function testRootOfNamedConstructor(): void
    {
        $a = Tree::rootOf('Root')->child('a');
        $b = Tree::new()->root('Root')->child('a');
        $this->assertSame($a->render(), $b->render());
    }

    public function testValueGetter(): void
    {
        $this->assertSame('',     Tree::new()->value());
        $this->assertSame('Docs', Tree::new()->root('Docs')->value());
        $this->assertSame('Docs', Tree::rootOf('Docs')->value());
    }

    public function testOffsetSlicesChildren(): void
    {
        $t = Tree::rootOf('r')->children('a', 'b', 'c', 'd', 'e');
        // [start=1, end=4) → keep b, c, d.
        $out = $t->offset(1, 4)->render();
        $this->assertStringNotContainsString(' a', $out);
        $this->assertStringContainsString('b', $out);
        $this->assertStringContainsString('c', $out);
        $this->assertStringContainsString('d', $out);
        $this->assertStringNotContainsString(' e', $out);
    }

    public function testOffsetStartDropsLeading(): void
    {
        $t = Tree::rootOf('r')->children('a', 'b', 'c');
        $out = $t->offsetStart(2)->render();
        $this->assertStringNotContainsString(' a', $out);
        $this->assertStringNotContainsString(' b', $out);
        $this->assertStringContainsString('c', $out);
    }

    public function testOffsetEndKeepsLeading(): void
    {
        $t = Tree::rootOf('r')->children('a', 'b', 'c');
        $out = $t->offsetEnd(2)->render();
        $this->assertStringContainsString('a', $out);
        $this->assertStringContainsString('b', $out);
        $this->assertStringNotContainsString(' c', $out);
    }

    public function testOffsetRejectsNegativeBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Tree::new()->offset(-1, 0);
    }
}
