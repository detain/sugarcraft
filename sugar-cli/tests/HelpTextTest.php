<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use SugarCraft\Cli\AutoWidth;
use SugarCraft\Cli\HelpText;
use SugarCraft\Cli\Theme;
use SugarCraft\Core\Util\Width;
use SugarCraft\Cli\Tests\Support\SandboxTerminalColumns;
use PHPUnit\Framework\TestCase;

final class HelpTextTest extends TestCase
{
    use SandboxTerminalColumns;

    protected function setUp(): void
    {
        // Default-width renders below pin the 80-cell fallback; hide any
        // terminal width the runner advertises so they stay honest.
        $this->sandboxTerminalColumns();
    }

    protected function tearDown(): void
    {
        $this->restoreTerminalColumns();
    }

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

    /** A section with no entries must not leave an orphan heading behind. */
    public function testSectionWithNoEntriesIsSkippedEntirely(): void
    {
        $out = HelpText::render('myapp', ['flags' => [], 'commands' => ['build' => 'compile']], theme: Theme::plain());
        $this->assertStringNotContainsString('FLAGS', $out, 'empty section rendered no heading');
        $this->assertStringContainsString('COMMANDS', $out, 'non-empty sibling still renders');
        $this->assertStringNotContainsString("\n\n\n", $out, 'skipping leaves no dangling separator');
    }

    /** M5: the heading above the synopsis is caller-settable. */
    public function testUsageLabelIsCustomizable(): void
    {
        $out = HelpText::render('myapp', [], theme: Theme::plain(), usageLabel: 'AUFRUF');
        $this->assertStringStartsWith("AUFRUF\n  myapp", $out);
        $this->assertStringNotContainsString('USAGE', $out);
    }

    /** The default label stays byte-identical for every existing caller. */
    public function testUsageLabelDefaultsToUsage(): void
    {
        $this->assertSame(
            HelpText::render('myapp', [], theme: Theme::plain()),
            HelpText::render('myapp', [], theme: Theme::plain(), usageLabel: 'USAGE'),
        );
    }

    /** An empty label drops the heading row: synopsis rides the first line. */
    public function testEmptyUsageLabelDropsHeadingLine(): void
    {
        $this->assertSame('  myapp', HelpText::render('myapp', [], theme: Theme::plain(), usageLabel: ''));
    }

    /** The label is ingress-sanitized and uppercased like a section title. */
    public function testUsageLabelNeutralizesInjectionAndUppercases(): void
    {
        $out = HelpText::render('myapp', [], theme: Theme::plain(), usageLabel: "aufr\x1b[2Juf");
        $this->assertStringStartsWith("AUFRUF\n", $out);
        $this->assertStringNotContainsString("\x1b", $out);
    }

    /** Narrow canvases squeeze the continuation indent instead of overflowing it. */
    public function testStackedIndentIsClampedToWidth(): void
    {
        // '--x' plus margins puts the description column past width 5, so the
        // row stacks; the historical fixed 6-cell indent alone exceeded the
        // canvas before any text was added. The clamp leaves 4 indent cells
        // plus a 1-cell wrap column — every line fits width 5.
        $out = HelpText::renderRows(['--x' => 'a b c d'], Theme::plain(), 5);
        $this->assertSame("  --x\n    a\n    b\n    c\n    d", $out);
        foreach (explode("\n", $out) as $line) {
            $this->assertLessThanOrEqual(5, Width::string($line), "line fits: '{$line}'");
        }
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

    /**
     * The null-width variant: the whole render path still works with no
     * wrapping canvas at all (previously a byte-for-byte clone of
     * testEmptySectionsRenderUsageOnly above — differentiated to the
     * one canvas mode no other case here exercises).
     */
    public function testRenderWithNoSectionsNeverWraps(): void
    {
        $long = trim(str_repeat('word ', 30));
        $out = HelpText::render($long, [], theme: Theme::plain(), width: null);
        $this->assertSame("USAGE\n  " . $long, $out, 'no wrap point in the synopsis');
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

    /** With no terminal width advertised, the wrap falls back to 80 cells. */
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

    // ---- renderPage (multi-line-preserving page variant) ----------------

    public function testRenderPageReturnsCleanPageByteIdentical(): void
    {
        $page = "USAGE\n  myapp [flags]\n\nFLAGS\n  -v  verbose logging\n";

        $this->assertSame($page, HelpText::renderPage($page));
    }

    /**
     * Why this variant exists: an authored page's newlines survive sanitizing,
     * while escape bytes and stray controls (tab, CR) do not.
     */
    public function testRenderPagePreservesRowsThroughPoison(): void
    {
        $page = "USAGE\x1b[2J\n  my\ta [flags]\r\n\x07FLAGS\n  -v  verbose\n";

        // TAB folds to a space (word boundary preserved, SC-HT2), BEL is
        // deleted, CR is dropped so CRLF yields a bare LF row.
        $this->assertSame("USAGE\n  my a [flags]\nFLAGS\n  -v  verbose\n", HelpText::renderPage($page));
    }

    /**
     * null is the DEFAULT here on purpose (unlike render()/renderRows()):
     * a page ships already laid out, so an advertised terminal width must
     * not silently re-flow it.
     */
    public function testRenderPageDefaultNeverWrapsEvenWithTerminalWidth(): void
    {
        $this->pinTerminalColumns(20);
        $long = str_repeat('word ', 20);

        $out = HelpText::renderPage("HEAD\n{$long}\nTAIL\n");

        $this->assertSame(["HEAD", $long, "TAIL", ""], explode("\n", $out));
    }

    public function testRenderPageAutoResolvesTerminalWidth(): void
    {
        $this->pinTerminalColumns(20);
        $page = "HEAD\n" . str_repeat('word ', 20) . "TAIL";

        $out = HelpText::renderPage($page, AutoWidth::Auto);

        $this->assertNotSame($page, $out, 'the auto width must re-flow the over-long row');
        foreach (explode("\n", $out) as $line) {
            $this->assertLessThanOrEqual(20, Width::string($line), "line fits: '{$line}'");
        }
    }

    public function testRenderPageExplicitWidthWrapsOnlyLongRows(): void
    {
        $long  = str_repeat('alpha beta ', 6) . 'gamma';
        $page  = "short row\n{$long}\nanother short";

        $out = HelpText::renderPage($page, 30);

        $rows = explode("\n", $out);
        $this->assertContains('short row', $rows, 'fitting rows come back untouched');
        $this->assertContains('another short', $rows);
        foreach ($rows as $line) {
            $this->assertLessThanOrEqual(30, Width::string($line), "line fits: '{$line}'");
        }
        // Wrapping reflows whitespace at break points only — every word of
        // the page survives, none is invented.
        $this->assertSame(
            preg_split('/\s+/', $page, -1, PREG_SPLIT_NO_EMPTY),
            preg_split('/\s+/', $out, -1, PREG_SPLIT_NO_EMPTY),
        );
    }

    public function testRenderPageWrapsWideCharactersCellAware(): void
    {
        $out = HelpText::renderPage("日本語のテキストです", 6);

        $this->assertGreaterThan(1, count($lines = explode("\n", $out)));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(6, Width::string($line), "cells fit: '{$line}'");
        }
        $this->assertSame('日本語のテキストです', implode('', $lines), 'no characters lost or gained');
    }

    public function testRenderPageWidthBelowOneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HelpText::renderPage("a\nb", 0);
    }

    public function testRenderPageIsIdempotent(): void
    {
        $poison = "USAGE\x1b]0;evil\x07\n  my\ta [flags]\r\n\x07FLAGS\n";

        $once  = HelpText::renderPage($poison);
        $twice = HelpText::renderPage($once);

        $this->assertSame($once, $twice);
    }

    /**
     * Polarity guard: render()'s single-line flattening contract holds —
     * a newline in the description collapses to a word-boundary space
     * (never a fused 'ab', never a second row).
     */
    public function testRenderStillFlattensNewlinesInDescription(): void
    {
        $out = HelpText::render('myapp', [], description: "a\nb", theme: Theme::plain());

        $this->assertSame("USAGE\n  myapp\n\na b", $out);
    }
}
