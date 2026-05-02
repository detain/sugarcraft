<?php

declare(strict_types=1);

namespace CandyCore\Gloss\Tests;

use CandyCore\Gloss\Tree\Tree;
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
}
