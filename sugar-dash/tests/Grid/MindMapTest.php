<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\MindMap;
use SugarCraft\Dash\Grid\MindMapNode;
use SugarCraft\Dash\Grid\MindMapDirection;
use SugarCraft\Dash\Grid\ConnectionStyle;
use SugarCraft\Core\Util\Color;

final class MindMapTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $mindMap = MindMap::new();
        $this->assertInstanceOf(MindMap::class, $mindMap);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $mindMap = MindMap::new();
        $result = $mindMap->setSize(60, 15);
        $this->assertInstanceOf(\SugarCraft\Dash\Grid\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $mindMap = MindMap::new()->setSize(60, 15);
        $rendered = $mindMap->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $mindMap = MindMap::new()->setSize(60, 15);
        $rendered = $mindMap->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithRootText(): void
    {
        $mindMap = MindMap::new();
        $result = $mindMap->withRootText('Main Topic');
        $this->assertInstanceOf(MindMap::class, $result);
    }

    public function testWithChild(): void
    {
        $mindMap = MindMap::new();
        $result = $mindMap->withChild('Sub Topic');
        $this->assertInstanceOf(MindMap::class, $result);
    }

    public function testWithCollapsed(): void
    {
        $mindMap = MindMap::new();
        $result = $mindMap->withCollapsed(true);
        $this->assertInstanceOf(MindMap::class, $result);
    }

    public function testWithDirection(): void
    {
        $mindMap = MindMap::new();
        $result = $mindMap->withDirection(MindMapDirection::TopBottom);
        $this->assertInstanceOf(MindMap::class, $result);
    }

    public function testWithConnectionStyle(): void
    {
        $mindMap = MindMap::new();
        $result = $mindMap->withConnectionStyle(ConnectionStyle::Curved);
        $this->assertInstanceOf(MindMap::class, $result);
    }

    public function testWithStyle(): void
    {
        $mindMap = MindMap::new();
        $result = $mindMap->withStyle('bold');
        $this->assertInstanceOf(MindMap::class, $result);
    }

    public function testMindMapNodeWithChild(): void
    {
        $root = new MindMapNode('Root');
        $child = new MindMapNode('Child');
        $result = $root->withChild($child);
        $this->assertInstanceOf(MindMapNode::class, $result);
    }

    public function testMindMapNodeFluentChildAddition(): void
    {
        $root = new MindMapNode('Root');
        $result = $root->addChild('Child 1')->addChild('Child 2');
        $this->assertCount(2, $result->children);
    }

    public function testGetInnerSize(): void
    {
        $mindMap = MindMap::new()->setSize(60, 15);
        $size = $mindMap->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(60, $size[0]);
        $this->assertEquals(15, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $mindMap = MindMap::new()->setSize(5, 5);
        $rendered = $mindMap->render();
        $this->assertSame('', $rendered);
    }
}
