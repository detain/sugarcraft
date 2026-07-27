<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests\Render;

use SugarCraft\Shine\Render\BlockContext;
use SugarCraft\Shine\Render\BlockKind;
use SugarCraft\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

final class BlockContextTest extends TestCase
{
    public function testConstruction(): void
    {
        $style = Style::new();
        $ctx = new BlockContext(
            kind: BlockKind::Heading,
            depth: 2,
            accumulatedIndent: 4,
            cascadedStyle: $style,
        );

        $this->assertSame(BlockKind::Heading, $ctx->kind);
        $this->assertSame(2, $ctx->depth);
        $this->assertSame(4, $ctx->accumulatedIndent);
        $this->assertSame($style, $ctx->cascadedStyle);
    }

    public function testAllBlockKindsProduceValidContext(): void
    {
        $style = Style::new();
        foreach (BlockKind::cases() as $kind) {
            $ctx = new BlockContext(
                kind: $kind,
                depth: 0,
                accumulatedIndent: 0,
                cascadedStyle: $style,
            );
            $this->assertSame($kind, $ctx->kind);
        }
    }

    public function testDepthAndIndentAreIndependent(): void
    {
        $style = Style::new();
        $ctx = new BlockContext(
            kind: BlockKind::List,
            depth: 3,
            accumulatedIndent: 12,
            cascadedStyle: $style,
        );

        $this->assertSame(3, $ctx->depth);
        $this->assertSame(12, $ctx->accumulatedIndent);

        // same kind, different depth/indent
        $ctx2 = new BlockContext(
            kind: BlockKind::List,
            depth: 7,
            accumulatedIndent: 28,
            cascadedStyle: $style,
        );
        $this->assertSame(7, $ctx2->depth);
        $this->assertSame(28, $ctx2->accumulatedIndent);
    }

    public function testContextsAreReadonly(): void
    {
        $style = Style::new();
        $ctx = new BlockContext(
            kind: BlockKind::Paragraph,
            depth: 1,
            accumulatedIndent: 0,
            cascadedStyle: $style,
        );

        // Properties are readonly — cannot mutate after construction
        $this->assertSame(1, $ctx->depth);
    }
}
