<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Media;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Media\Audio;
use SugarCraft\Dash\Components\Media\WaveformStyle;

final class AudioTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $audio = Audio::new();
        $this->assertInstanceOf(Audio::class, $audio);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $audio = Audio::new();
        $result = $audio->setSize(60, 6);
        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $audio = Audio::new()->setSize(60, 6);
        $rendered = $audio->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $audio = Audio::new()->setSize(60, 6);
        $rendered = $audio->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithWaveformData(): void
    {
        $audio = Audio::new();
        $result = $audio->withWaveformData([0.5, 0.8, 0.3, 1.0, 0.6]);
        $this->assertInstanceOf(Audio::class, $result);
    }

    public function testWithPosition(): void
    {
        $audio = Audio::new();
        $result = $audio->withPosition(60);
        $this->assertInstanceOf(Audio::class, $result);
    }

    public function testWithDuration(): void
    {
        $audio = Audio::new();
        $result = $audio->withDuration(300);
        $this->assertInstanceOf(Audio::class, $result);
    }

    public function testWithPlaying(): void
    {
        $audio = Audio::new();
        $result = $audio->withPlaying(true);
        $this->assertInstanceOf(Audio::class, $result);
    }

    public function testWithVolume(): void
    {
        $audio = Audio::new();
        $result = $audio->withVolume(50);
        $this->assertInstanceOf(Audio::class, $result);
    }

    public function testWithTitleAndArtist(): void
    {
        $audio = Audio::new();
        $result = $audio->withTitle('Test Song')->withArtist('Test Artist');
        $this->assertInstanceOf(Audio::class, $result);
    }

    public function testWithWaveformStyle(): void
    {
        $audio = Audio::new();
        $result = $audio->withWaveformStyle(WaveformStyle::Line);
        $this->assertInstanceOf(Audio::class, $result);
    }

    public function testGetInnerSize(): void
    {
        $audio = Audio::new()->setSize(60, 6);
        $size = $audio->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(60, $size[0]);
        $this->assertEquals(6, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $audio = Audio::new()->setSize(5, 5);
        $rendered = $audio->render();
        $this->assertSame('', $rendered);
    }
}
