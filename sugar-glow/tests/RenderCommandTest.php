<?php

declare(strict_types=1);

namespace CandyCore\Glow\Tests;

use CandyCore\Glow\RenderCommand;
use CandyCore\Shine\Theme;
use PHPUnit\Framework\TestCase;

final class RenderCommandTest extends TestCase
{
    public function testPickThemeAnsi(): void
    {
        $theme = RenderCommand::pickTheme('ansi');
        $this->assertInstanceOf(Theme::class, $theme);
    }

    public function testPickThemePlain(): void
    {
        $theme = RenderCommand::pickTheme('plain');
        $this->assertSame('plain', $theme->paragraph->render('plain'));
    }

    public function testPickThemeCaseInsensitive(): void
    {
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('ANSI'));
    }

    public function testPickThemeRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RenderCommand::pickTheme('mystery');
    }

    public function testLoadInputReadsFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'glow-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "# Hello");
        try {
            $this->assertSame("# Hello", RenderCommand::loadInput($tmp));
        } finally {
            unlink($tmp);
        }
    }

    public function testLoadInputMissingFileReturnsNull(): void
    {
        $this->assertNull(RenderCommand::loadInput('/no/such/path/sugar-glow-test.md'));
    }
}
