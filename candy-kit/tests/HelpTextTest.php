<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Kit\HelpText;
use SugarCraft\Kit\Theme;
use SugarCraft\Core\Util\Width;
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
     * Display-CELL alignment (fix wave): the old mb_strlen math counted
     * codepoints, so a 2-glyph CJK key occupying 4 cells pulled its
     * description one column left of the ASCII rows. Under Theme::plain()
     * there is no SGR, so byte offsets precede the description exactly;
     * measure the cell width of that prefix per row.
     */
    public function testCjkKeyDescriptionsStartAtSameDisplayColumn(): void
    {
        $out = HelpText::renderRows([
            '-h'          => 'ascii row',
            '帮助'         => 'cjk row',
            '--long-name' => 'long row',
        ], Theme::plain());

        $columns = [];
        foreach (['ascii row', 'cjk row', 'long row'] as $desc) {
            $line = $this->plainLineContaining($out, $desc);
            $columns[] = Width::string(substr($line, 0, strpos($line, $desc)));
        }
        // 2-cell left margin + widest key '--long-name' (11 cells) + 2-cell gutter.
        $this->assertSame([15, 15, 15], $columns);
    }

    private function plainLineContaining(string $out, string $needle): string
    {
        foreach (explode("\n", $out) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }
        $this->fail("no rendered line contains '{$needle}'");
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

    public function testRenderWithEmptyDescriptionOmitsBlock(): void
    {
        $out = HelpText::render(
            usage: 'myapp [flags]',
            sections: ['flags' => ['--help' => 'show help']],
            description: '',
            theme: Theme::plain(),
        );
        // Description block must be absent; USAGE and FLAGS sections still present.
        $this->assertStringContainsString('USAGE', $out);
        $this->assertStringContainsString('FLAGS', $out);
        $this->assertStringNotContainsString('A CLI tool.', $out);
    }

    public function testRenderRowsWithEmptyArrayReturnsEmpty(): void
    {
        $out = HelpText::renderRows([], Theme::plain());
        $this->assertSame('', $out);
    }

    public function testRenderWithNoSectionsRendersUsageOnly(): void
    {
        $out = HelpText::render('myapp', [], theme: Theme::plain());
        $this->assertStringContainsString('USAGE', $out);
        $this->assertStringContainsString('myapp', $out);
    }

    public function testRenderWithThemeDefaultFallsBackToAnsi(): void
    {
        // Passing null theme should use Theme::ansi() internally.
        // Indirectly verify by checking SGR sequences appear (ansi theme adds styles).
        $out = HelpText::render(
            usage: 'myapp',
            sections: ['flags' => ['-v' => 'verbose']],
            description: 'A tool.',
        );
        // The accent/prompt styles in ansi theme emit SGR.
        $this->assertStringContainsString("\x1b[", $out);
    }
}
