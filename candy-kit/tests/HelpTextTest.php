<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Kit\HelpText;
use SugarCraft\Kit\Theme;
use PHPUnit\Framework\TestCase;

final class HelpTextTest extends TestCase
{
    public function testRendersUsageAndSections(): void
    {
        $out = HelpText::render(
            usage: 'myapp [flags] <file>',
            sections: [
                'flags' => [
                    '-v, --verbose'   => 'enable verbose logging',
                    '--theme <name>'  => 'pick a colour theme',
                ],
                'commands' => [
                    'build'  => 'compile the project',
                    'serve'  => 'start the dev server',
                ],
            ],
            description: 'A CLI tool.',
            theme: Theme::plain(),
        );
        $this->assertStringContainsString('USAGE',           $out);
        $this->assertStringContainsString('myapp [flags]',   $out);
        $this->assertStringContainsString('A CLI tool.',     $out);
        $this->assertStringContainsString('FLAGS',           $out);
        $this->assertStringContainsString('--verbose',       $out);
        $this->assertStringContainsString('verbose logging', $out);
        $this->assertStringContainsString('COMMANDS',        $out);
        $this->assertStringContainsString('build',           $out);
        $this->assertStringContainsString('serve',           $out);
    }

    public function testEmptySectionsRenderUsageOnly(): void
    {
        $out = HelpText::render('myapp', [], theme: Theme::plain());
        $this->assertStringContainsString('USAGE', $out);
        $this->assertStringContainsString('myapp', $out);
    }

    public function testRenderRowsAlignsKeys(): void
    {
        $out = HelpText::renderRows([
            'a'   => 'short',
            'abc' => 'longer',
        ], Theme::plain());
        $lines = explode("\n", $out);
        // Both rows have the description starting at the same column.
        $aPos   = strpos($lines[0], 'short');
        $abcPos = strpos($lines[1], 'longer');
        $this->assertNotFalse($aPos);
        $this->assertNotFalse($abcPos);
        $this->assertSame($aPos, $abcPos);
    }

    /**
     * Security: usage, description, section titles, and row keys/descriptions
     * are all caller-supplied and interpolated raw into the help page. Under
     * the plain theme (no SGR of its own) any ESC / BEL in the output must have
     * come from that text — assert every field is neutralized. The structural
     * newlines HelpText emits itself are fine; only the injected escape and
     * control bytes must go. (Revert the SafeText routing → leaks → fails.)
     */
    public function testCallerTextEscapeAndControlBytesNeutralized(): void
    {
        $evil = "x\x1b[2Jy\x1b]0;t\x07z";
        $out  = HelpText::render(
            usage: $evil,
            sections: [$evil => [$evil => $evil]],
            description: $evil,
            theme: Theme::plain(),
        );

        $this->assertStringNotContainsString("\x1b", $out, 'ESC injection must be stripped');
        $this->assertStringNotContainsString("\x07", $out, 'BEL must be stripped');
        $this->assertStringContainsString('xyz', $out, 'visible text survives');
    }

    /** renderRows() neutralizes control bytes in caller keys + descriptions. */
    public function testRenderRowsNeutralizesControlBytes(): void
    {
        $out = HelpText::renderRows(["k\x1b[2J" => "d\x07esc"], Theme::plain());
        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertStringContainsString('desc', $out);
    }
}
