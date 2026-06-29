<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Kit\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function testAnsiThemeStylesEachLevel(): void
    {
        $t = Theme::ansi();
        foreach (['success', 'error', 'warn', 'info', 'prompt', 'accent'] as $field) {
            $rendered = $t->{$field}->render('x');
            $this->assertStringContainsString("\x1b[", $rendered, "$field should emit SGR");
        }
    }

    public function testAnsiMutedIsFaint(): void
    {
        $rendered = Theme::ansi()->muted->render('x');
        $this->assertStringContainsString('2m', $rendered); // SGR 2 = faint
    }

    public function testPlainThemePassthrough(): void
    {
        $t = Theme::plain();
        foreach (['success', 'error', 'warn', 'info', 'prompt', 'accent', 'muted'] as $field) {
            $this->assertSame('text', $t->{$field}->render('text'));
        }
    }

    public function testCharmThemeEmitsColour(): void
    {
        $this->assertStringContainsString("\x1b[", Theme::charm()->prompt->render('x'));
    }

    public function testDraculaThemeEmitsColour(): void
    {
        $this->assertStringContainsString("\x1b[", Theme::dracula()->success->render('x'));
    }

    public function testNordThemeEmitsColour(): void
    {
        $this->assertStringContainsString("\x1b[", Theme::nord()->info->render('x'));
    }

    public function testCatppuccinThemeEmitsColour(): void
    {
        $this->assertStringContainsString("\x1b[", Theme::catppuccin()->accent->render('x'));
    }

    public function testByNameResolvesPresets(): void
    {
        // Dracula's success color is #50fa7b → truecolor SGR 38;2;80;250;123
        $this->assertStringContainsString(
            '38;2;80;250;123',
            Theme::byName('dracula')->success->render('x'),
        );
        // byName is case-insensitive (strtolower normalises the lookup key)
        $this->assertEquals(
            Theme::byName('ANSI')->success->render('x'),
            Theme::byName('ansi')->success->render('x'),
        );
    }

    public function testByNameRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::byName('nonexistent');
    }
}
