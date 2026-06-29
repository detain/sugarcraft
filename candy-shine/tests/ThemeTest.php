<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function testAnsiThemeAppliesColourToHeadings(): void
    {
        $rendered = Theme::ansi()->heading1->render('Hello');
        $this->assertStringContainsString("\x1b[", $rendered);
        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testPlainThemeAppliesNoStyling(): void
    {
        foreach (['heading1','heading2','bold','italic','code','codeBlock','link','blockquote','listMarker','rule'] as $field) {
            $this->assertSame('text', Theme::plain()->{$field}->render('text'));
        }
    }

    public function testFromJsonStringParsesHexAndFlags(): void
    {
        $json = json_encode([
            'heading1'  => ['bold' => true, 'foreground' => '#ff5f87'],
            'paragraph' => ['italic' => true],
            'code'      => ['foreground' => 'ansi256:202'],
            'codeBlock' => ['background' => 'ansi:8', 'faint' => true],
        ]);
        $t = Theme::fromJsonString($json);
        $rendered = $t->heading1->render('h');
        $this->assertStringContainsString("\x1b[1m",            $rendered); // bold
        $this->assertStringContainsString('38;2;255;95;135',    $rendered); // hex truecolor

        $italic = $t->paragraph->render('p');
        $this->assertStringContainsString("\x1b[3m", $italic);

        // ansi256:202 → Color::ansi256(202) → RGB(255,95,0). Default
        // profile is TrueColor so it renders as 38;2;...
        $code = $t->code->render('c');
        $this->assertStringContainsString('38;2;255;95;0', $code);

        $cb = $t->codeBlock->render('cb');
        $this->assertStringContainsString("\x1b[2m",  $cb); // faint
        $this->assertStringContainsString("48",       $cb); // bg slot
    }

    public function testFromJsonStringMissingElementsDefaultToPlain(): void
    {
        $json = json_encode(['heading1' => ['bold' => true]]);
        $t = Theme::fromJsonString($json);
        // h2 not in JSON → plain.
        $this->assertSame('p', $t->heading2->render('p'));
        // h1 styled.
        $this->assertStringContainsString("\x1b[1m", $t->heading1->render('h'));
    }

    public function testFromJsonStringRejectsNonObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromJsonString('"just a string"');
    }

    public function testFromJsonReadsFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shine');
        file_put_contents($tmp, json_encode(['bold' => ['bold' => true]]));
        try {
            $t = Theme::fromJson($tmp);
            $this->assertStringContainsString("\x1b[1m", $t->bold->render('b'));
        } finally {
            unlink($tmp);
        }
    }

    public function testFromJsonRaisesOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        Theme::fromJson('/nonexistent/path/' . uniqid());
    }

    public function testFromJsonRejectsDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        Theme::fromJson(__DIR__);
    }

    public function testAnsiThemeHasSyntaxTokenStyles(): void
    {
        $t = Theme::ansi();
        $this->assertStringContainsString("\x1b[", $t->keyword?->render('if')   ?? '');
        $this->assertStringContainsString("\x1b[", $t->string?->render('"abc"') ?? '');
        $this->assertStringContainsString("\x1b[", $t->number?->render('42')    ?? '');
        $this->assertStringContainsString("\x1b[", $t->comment?->render('// x') ?? '');
    }

    public function testFromJsonStringParsesTokenStyles(): void
    {
        $json = json_encode([
            'keyword' => ['bold' => true],
            'string'  => ['foreground' => '#00ff00'],
        ]);
        $t = Theme::fromJsonString($json);
        $this->assertStringContainsString("\x1b[1m",            $t->keyword?->render('if') ?? '');
        $this->assertStringContainsString('38;2;0;255;0',       $t->string?->render('"x"') ?? '');
        // Unspecified token styles parse to plain Style::new().
        $this->assertSame('42', $t->number?->render('42') ?? '');
    }

    public function testAsciiThemeIsMonochromeButPreservesEmphasis(): void
    {
        $t = Theme::ascii();
        // Bold / italic / underline still emit, but no SGR colours.
        $this->assertStringContainsString("\x1b[1m",  $t->bold->render('hi'));
        $this->assertStringContainsString("\x1b[3m",  $t->italic->render('hi'));
        $this->assertStringContainsString("\x1b[4m",  $t->link->render('hi'));
        // No 38;2; truecolor or 38;5; 256-colour.
        $rendered = $t->heading1->render('Hello');
        $this->assertStringNotContainsString('38;2;', $rendered);
        $this->assertStringNotContainsString('38;5;', $rendered);
        // Code blocks don't add colour — just pass-through.
        $this->assertSame('return 42;', $t->codeBlock->render('return 42;'));
    }

    public function testByNameDispatchesAllPresets(): void
    {
        $expected = ['ansi', 'plain', 'notty', 'ascii', 'dark', 'light', 'dracula', 'tokyo-night', 'pink'];
        foreach ($expected as $name) {
            $this->assertInstanceOf(Theme::class, Theme::byName($name), $name);
        }
        // Hyphen / underscore / case insensitivity.
        $this->assertInstanceOf(Theme::class, Theme::byName('TOKYO_NIGHT'));
        $this->assertInstanceOf(Theme::class, Theme::byName('TokyoNight'));
        $this->assertNull(Theme::byName('does-not-exist'));
    }

    public function testFromEnvironmentReadsGlamourStyleEnv(): void
    {
        putenv('GLAMOUR_STYLE=dracula');
        try {
            $t = Theme::fromEnvironment();
            $this->assertEquals(Theme::dracula(), $t);
        } finally {
            putenv('GLAMOUR_STYLE');
        }
    }

    public function testFromEnvironmentFallsBackOnUnknown(): void
    {
        putenv('GLAMOUR_STYLE=not-a-theme');
        try {
            $this->assertEquals(Theme::ansi(), Theme::fromEnvironment());
        } finally {
            putenv('GLAMOUR_STYLE');
        }
    }

    public function testFromEnvironmentHonoursDefault(): void
    {
        putenv('GLAMOUR_STYLE');
        $this->assertEquals(Theme::plain(), Theme::fromEnvironment(Theme::plain()));
    }

    public function testParseColorRejectsMalformedAnsiSpec(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromJsonString(json_encode(['code' => ['foreground' => 'ansi:300']]));
    }

    public function testParseColorRejectsMalformedAnsi256Spec(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromJsonString(json_encode(['code' => ['foreground' => 'ansi256:abc']]));
    }

    public function testParseColorRejectsMalformedAnsiSpecLetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromJsonString(json_encode(['code' => ['foreground' => 'ansi:xyz']]));
    }

    /**
     * Strikethrough with a null strike slot should fall back to
     * Style::new()->strikethrough() (the default from Renderer::renderStrike).
     * Theme::ansi() has null strike by not specifying it.
     */
    public function testStrikethroughNullFallbackRenders(): void
    {
        $r = new Renderer(Theme::ansi());
        $out = $r->render('~~strike~~');
        // Fallback is Style::new()->strikethrough() which emits the strikethrough SGR code.
        $this->assertStringContainsString("\x1b[9m", $out);
        $this->assertStringContainsString('strike', $out);
    }
}
