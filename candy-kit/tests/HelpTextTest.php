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

    public function testRenderWithAnsiThemeEmitsSgr(): void
    {
        // The no-theme fallback is Theme::detect() (covered by
        // DefaultThemeTest); the explicit ansi palette always styles.
        $out = HelpText::render(
            usage: 'myapp',
            sections: ['flags' => ['-v' => 'verbose']],
            description: 'A tool.',
            theme: Theme::ansi(),
        );
        // The accent/prompt styles in ansi theme emit SGR.
        $this->assertStringContainsString("\x1b[", $out);
    }

    /**
     * A long description wraps inside its column: every line fits $width and
     * every continuation line starts at the description column (it used to
     * render as one 500-cell line the terminal hard-wrapped to column 0).
     */
    public function testLongDescriptionWrapsAndKeepsColumnAlignment(): void
    {
        $long = trim(str_repeat('lorem ipsum dolor ', 30));
        $out = HelpText::renderRows(['--config' => $long, '-v' => 'verbose'], Theme::plain(), 40);
        $lines = explode("\n", $out);

        $this->assertGreaterThan(2, count($lines), 'the long description wrapped');
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(40, Width::string($line), "line fits: '{$line}'");
        }
        // 2-cell margin + '--config' (8) + 2-cell gutter = column 12.
        $this->assertStringStartsWith('  --config  lorem', $lines[0]);
        $continuations = array_slice($lines, 1, -1);
        foreach ($continuations as $line) {
            $this->assertMatchesRegularExpression('/^ {12}\S/', $line);
        }
        $this->assertSame('  -v        verbose', end($lines));
        // No text was lost in the wrap.
        $joined = implode(' ', array_map(static fn (string $l): string => trim($l), array_slice($lines, 0, -1)));
        $this->assertSame('--config  ' . $long, $joined);
    }

    /** Descriptions that already fit are byte-identical to the unwrapped render. */
    public function testFittingRowsAreUnchangedByWidth(): void
    {
        $rows = ['-h' => 'show help', '--verbose' => 'more output'];
        $this->assertSame(
            HelpText::renderRows($rows, Theme::plain(), null),
            HelpText::renderRows($rows, Theme::plain(), 80),
        );
    }

    /** width: null disables wrapping entirely. */
    public function testNullWidthNeverWraps(): void
    {
        $long = trim(str_repeat('word ', 100));
        $out = HelpText::renderRows(['-x' => $long], Theme::plain(), null);
        $this->assertSame('  -x  ' . $long, $out);
    }

    /** Default width is 80 cells. */
    public function testDefaultWidthIsEighty(): void
    {
        $out = HelpText::renderRows(['-x' => trim(str_repeat('word ', 100))], Theme::plain());
        foreach (explode("\n", $out) as $line) {
            $this->assertLessThanOrEqual(80, Width::string($line));
        }
    }

    /** Too little room right of the key column: stack the description under the key. */
    public function testNarrowColumnFallsBackToStackedLayout(): void
    {
        $out = HelpText::renderRows(
            ['--a-really-long-option-name' => 'does a thing to the other thing'],
            Theme::plain(),
            34,
        );
        $this->assertSame(
            "  --a-really-long-option-name\n"
            . "      does a thing to the other\n"
            . "      thing",
            $out,
        );
    }

    /** Usage and description paragraphs wrap too. */
    public function testUsageAndDescriptionWrap(): void
    {
        $out = HelpText::render(
            usage: 'myapp ' . trim(str_repeat('[--flag] ', 10)),
            sections: [],
            description: trim(str_repeat('prose ', 20)),
            theme: Theme::plain(),
            width: 30,
        );
        foreach (explode("\n", $out) as $line) {
            $this->assertLessThanOrEqual(30, Width::string($line), "line fits: '{$line}'");
        }
        [$usage] = explode("\n\n", $out);
        foreach (array_slice(explode("\n", $usage), 1) as $line) {
            $this->assertStringStartsWith('  ', $line, 'usage lines keep their indent');
        }
    }

    public function testWidthBelowOneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HelpText::renderRows(['-x' => 'y'], Theme::plain(), 0);
    }

    public function testRenderWidthBelowOneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HelpText::render('app', [], theme: Theme::plain(), width: -1);
    }

    /** Section titles uppercase multibyte-safely (strtoupper left `café` as `CAFé`). */
    public function testSectionTitleUppercasesMultibyte(): void
    {
        $out = HelpText::render('', ['café' => ['-x' => 'y']], theme: Theme::plain());
        $this->assertStringStartsWith("CAFÉ\n", $out);
    }
}
