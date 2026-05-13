<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Video;
use SugarCraft\Dash\Grid\ControlsStyle;

final class VideoTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $video = Video::new();
        $this->assertInstanceOf(Video::class, $video);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $video = Video::new();
        $result = $video->setSize(80, 20);
        $this->assertInstanceOf(\SugarCraft\Dash\Grid\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $video = Video::new()->setSize(60, 10);
        $rendered = $video->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $video = Video::new()->setSize(60, 10);
        $rendered = $video->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithShowControls(): void
    {
        $video = Video::new();
        $result = $video->withShowControls(false);
        $this->assertInstanceOf(Video::class, $result);
    }

    public function testWithControlsStyle(): void
    {
        $video = Video::new();
        $result = $video->withControlsStyle(ControlsStyle::Always);
        $this->assertInstanceOf(Video::class, $result);
    }

    public function testWithSource(): void
    {
        $video = Video::new();
        $result = $video->withSource('test.mp4');
        $this->assertInstanceOf(Video::class, $result);
    }

    public function testWithStyle(): void
    {
        $video = Video::new();
        $result = $video->withStyle('bold');
        $this->assertInstanceOf(Video::class, $result);
    }

    public function testGetInnerSize(): void
    {
        $video = Video::new()->setSize(80, 20);
        $size = $video->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(80, $size[0]);
        $this->assertEquals(20, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $video = Video::new()->setSize(5, 5);
        $rendered = $video->render();
        $this->assertSame('', $rendered);
    }
}
