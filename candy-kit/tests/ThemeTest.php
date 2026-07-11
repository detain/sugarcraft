<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Kit\Theme;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Palettes;
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

    public function testDraculaAccentIsPinkFromPalettes(): void
    {
        // W5 fix: candy-kit's dracula accent had diverged to purple #bd93f9.
        // It is now re-sourced from candy-core's Palettes SSOT and bound to
        // Dracula's `pink` (#ff79c6 → SGR 38;2;255;121;198), matching the
        // canonical candy-sprinkles accent slot.
        $this->assertSame('#ff79c6', Palettes::DRACULA['pink']);
        $this->assertStringContainsString(
            '38;2;255;121;198',
            Theme::dracula()->accent->render('x'),
            'dracula accent must be Palettes::DRACULA[pink] (#ff79c6), not purple #bd93f9',
        );
        // After the fix accent == prompt (both pink); the old purple #bd93f9
        // no longer appears anywhere in candy-kit's dracula palette.
        $this->assertSame(
            Theme::dracula()->prompt->render('x'),
            Theme::dracula()->accent->render('x'),
        );
        $this->assertStringNotContainsString(
            '38;2;189;147;249', // purple #bd93f9 — the pre-W5 diverged accent
            Theme::dracula()->accent->render('x'),
        );
    }

    public function testDraculaSlotsSourcedFromPalettes(): void
    {
        // Parity: every migrated slot renders the truecolor SGR derived from
        // its candy-core Palettes hex, so the two stay locked together.
        $p = Palettes::DRACULA;
        $map = [
            'success' => $p['green'],
            'error'   => $p['red'],
            'warn'    => $p['yellow'],
            'info'    => $p['cyan'],
            'prompt'  => $p['pink'],
            'accent'  => $p['pink'],
            'muted'   => $p['comment'],
        ];
        $theme = Theme::dracula();
        foreach ($map as $slot => $hex) {
            [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
            $this->assertStringContainsString(
                "38;2;{$r};{$g};{$b}",
                $theme->{$slot}->render('x'),
                "dracula {$slot} must render Palettes hex {$hex}",
            );
        }
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

    public function testPlainThemeAllFieldsUseSameStyleInstance(): void
    {
        // Theme::plain() re-uses one Style::$s instance for all 7 fields.
        // All accessors must return the identical object reference.
        $t = Theme::plain();
        $this->assertSame($t->success, $t->error);
        $this->assertSame($t->error, $t->warn);
        $this->assertSame($t->warn, $t->info);
        $this->assertSame($t->info, $t->prompt);
        $this->assertSame($t->prompt, $t->accent);
        $this->assertSame($t->accent, $t->muted);
    }

    public function testAutoReturnsNordForDarkBackground(): void
    {
        $dark = new \SugarCraft\Core\Msg\BackgroundColorMsg(30, 30, 30);
        $this->assertEquals(Theme::nord(), Theme::auto($dark));
    }

    public function testAutoReturnsCatppuccinForLightBackground(): void
    {
        $light = new \SugarCraft\Core\Msg\BackgroundColorMsg(250, 250, 250);
        $this->assertEquals(Theme::catppuccin(), Theme::auto($light));
    }

    public function testAutoFallsBackToAnsiWhenNoBackgroundProvided(): void
    {
        $this->assertEquals(Theme::ansi(), Theme::auto());
    }

    public function testByNameAutoResolves(): void
    {
        $this->assertEquals(Theme::auto(), Theme::byName('auto'));
    }

    public function testBuildFluentBuilder(): void
    {
        $custom = Theme::build()
            ->success(Style::new()->foreground(Color::hex('#ff0000')))
            ->error(Style::new()->foreground(Color::hex('#00ff00')))
            ->warn(Style::new()->foreground(Color::hex('#0000ff')))
            ->info(Style::new()->foreground(Color::hex('#ffff00')))
            ->prompt(Style::new()->foreground(Color::hex('#ff00ff')))
            ->accent(Style::new()->foreground(Color::hex('#00ffff')))
            ->muted(Style::new()->foreground(Color::hex('#888888')))
            ->build();

        // The success style should have ANSI color for #ff0000 (38;2;255;0;0).
        $this->assertStringContainsString('38;2;255;0;0', $custom->success->render('x'));
        $this->assertNotSame(Theme::ansi()->success, $custom->success);
    }

    public function testBuildThrowsWhenFieldMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::build()->success(Style::new())->build();
    }

    public function testConstructorRejectsNullStyle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Theme(
            Style::new(), Style::new(), Style::new(),
            Style::new(), Style::new(), Style::new(), null,
        );
    }
}
