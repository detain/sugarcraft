<?php

declare(strict_types=1);

namespace CandyCore\Shine\Tests;

use CandyCore\Shine\Theme;
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
}
