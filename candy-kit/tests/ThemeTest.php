<?php

declare(strict_types=1);

namespace CandyCore\Kit\Tests;

use CandyCore\Kit\Theme;
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
}
