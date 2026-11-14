<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\TimelineNode;

/**
 * D-143: withMultiLineDescription hides one real behavior — the newline
 * join — and TimelineTest renders nodes through the widget only. Pin the
 * transport form the renderer splits/segments on.
 */
final class TimelineNodeTest extends TestCase
{
    public function testNewCarriesOptionalDescriptionDefault(): void
    {
        self::assertNull(TimelineNode::new('step')->description);
        self::assertSame('step', TimelineNode::new('step')->label);
        self::assertSame('why', TimelineNode::new('step', 'why')->description);
    }

    public function testWithMultiLineDescriptionJoinsWithBareNewlines(): void
    {
        $node = TimelineNode::withMultiLineDescription('release', ['a', 'b', 'c']);

        self::assertSame('release', $node->label);
        self::assertSame("a\nb\nc", $node->description, 'the join separator is the split contract');

        // Single line degrades to the plain form; empty list to ''.
        self::assertSame('only', TimelineNode::withMultiLineDescription('n', ['only'])->description);
        self::assertSame('', TimelineNode::withMultiLineDescription('n', [])->description);
    }
}
