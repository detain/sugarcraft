<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Theme;
use SugarCraft\Style\Style;

/**
 * C-TUI-2.c: split diff bodies, token-tinted rows, and the `diffStyle` setting.
 *
 * The two-column half of a split frame is the one thing that must never exceed
 * the frame width — an over-wide row reintroduces the absolute-cursorTo row
 * collision PR #1403 cured — so the width sweep is the load-bearing pin here,
 * mirrored from tests/Renderer/PaneWidthInvariantTest.php's methodology: strip
 * SGR (renderDiff emits no mouse zones), then measure every painted row.
 */
final class DiffSplitRenderTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/crush-diff-split-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        UiSettings::forget();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        self::removeTree($this->home);
    }

    public function testTheAutoLayoutPairsRemovalsWithAdditionsOnlyAtTheWidthThreshold(): void
    {
        $narrow = self::paint(self::phpFixture(), 119);
        $wide = self::paint(self::phpFixture(), 120);

        self::assertFalse(self::rowPaired($narrow, '$old = 1;', '$name ='), 'below 120 the auto layout stays single column');
        self::assertTrue(self::rowPaired($wide, '$old = 1;', '$name ='), 'at 120 the auto layout splits');
    }

    public function testTheUnifiedSettingKeepsOneColumnEvenOnHugeTerminals(): void
    {
        self::writeStyle('unified');
        $painted = self::paint(self::phpFixture(), 200);

        self::assertStringContainsString('-$old = 1;', Ansi::strip($painted));
        self::assertFalse(self::rowPaired($painted, '$old = 1;', '$name ='), 'a unified removal never shares its row with its addition');
    }

    public function testTheSplitSettingPaintsTwoColumnsEvenOnNarrowTerminals(): void
    {
        self::writeStyle('split');
        $painted = self::paint(self::phpFixture(), 40);

        self::assertTrue(self::rowPaired($painted, '$old', '$name'), 'the split setting forces two columns regardless of width');
        foreach (self::frameRows($painted) as $row) {
            self::assertLessThanOrEqual(40, Width::string(Ansi::strip($row)), 'narrow forced split: ' . $row);
        }
    }

    public function testNoSplitRowEverExceedsTheRequestedWidth(): void
    {
        foreach ([110, 112, 114, 116, 118, 120, 121, 124, 128, 130, 134, 140, 150] as $width) {
            foreach (self::fixtures() as $name => $diff) {
                $painted = self::paint($diff, $width);
                foreach (self::frameRows($painted) as $row) {
                    self::assertLessThanOrEqual(
                        $width,
                        Width::string(Ansi::strip($row)),
                        $name . ' at width ' . $width . ' painted row: ' . $row,
                    );
                }
            }
        }
    }

    public function testTabsAreExpandedBeforeSplitHalvesAreMeasured(): void
    {
        self::writeStyle('split');
        $painted = self::paint(self::tabFixture(), 60);
        $stripped = Ansi::strip($painted);

        self::assertStringNotContainsString("\t", $stripped);
        self::assertTrue(self::rowPaired($painted, 'rule:', 'recipe'));
        foreach (self::frameRows($painted) as $row) {
            self::assertLessThanOrEqual(60, Width::string(Ansi::strip($row)), $row);
        }
    }

    public function testUnnumberableHunksFallBackToFullWidthRowsAndNeverDropOne(): void
    {
        $painted = self::paint(self::oversizedFixture(), 130);
        $stripped = Ansi::strip($painted);

        foreach (['old body', 'new body', '@@ -9999999999,3 +9999999999,3 @@'] as $needle) {
            self::assertStringContainsString($needle, $stripped);
        }
        self::assertFalse(self::rowPaired($painted, 'old body', 'new body'), 'the fallback paints the group exactly single column');
        self::assertCount(15, self::frameRows($painted), '13 content rows plus the top and bottom border rows');
    }

    public function testAnnotatedHunksStayFullWidthWhereThePairingEngineWouldDropThem(): void
    {
        $painted = self::paint(self::annotatedFixture(), 130);
        $stripped = Ansi::strip($painted);

        self::assertStringContainsString('-first', $stripped);
        self::assertStringContainsString('+second', $stripped);
        self::assertStringContainsString('\\ No newline at end of file', $stripped);
        self::assertFalse(self::rowPaired($painted, '-first', '+second'), 'annotated hunks are never paired');
    }

    public function testDeletionsAndInsertionsShareTheirRowAndLoneSidesRideBlanks(): void
    {
        $painted = self::paint(self::phpFixture(), 130);

        self::assertTrue(self::rowPaired($painted, '$old = 1;', '$name = "hello";'), 'paired removal and addition share one row');

        $loneRow = self::strippedRow($painted, '$new');
        self::assertStringContainsString('$new = 2;', $loneRow);
        // A lone addition leaves its left half blank: the blank field runs from
        // the left gutter's divider straight into the middle divider, then the
        // right side's number — the divider never slides.
        self::assertMatchesRegularExpression('/│\s+│\d+│/', $loneRow);
    }

    public function testDiffBodiesCarryTokenSpansWhileTheMarkerColumnKeepsItsColor(): void
    {
        $theme = Theme::default();
        $painted = self::paint(self::phpFixture(), 130);

        self::assertTrue(self::tokenPainted($theme, 'keyword', self::rawRow($painted, 'function greet')), 'the php keyword tints inside the split half');
        self::assertTrue(self::tokenPainted($theme, 'string', self::rawRow($painted, '"hello"')));

        $greenMarker = Style::new()->foreground(Color::ansi(2))->render('+');
        self::assertStringContainsString($greenMarker, self::rawRow($painted, '$name ='), 'the marker column keeps its colour');
        $redMarker = Style::new()->foreground(Color::ansi(1))->render('-');
        self::assertStringContainsString($redMarker, self::rawRow($painted, '$old ='));
    }

    public function testTheTintRidesTheSingleColumnToo(): void
    {
        self::writeStyle('unified');
        $theme = Theme::default();
        $painted = self::paint(self::phpFixture(), 100);

        self::assertTrue(self::tokenPainted($theme, 'keyword', self::rawRow($painted, 'function greet')));
    }

    public function testLanguagelessFilesPaintExactlyTheRowStyle(): void
    {
        $theme = Theme::default();
        $painted = self::paint(self::textFixture(), 130);

        // .txt has no highlighter language; bodies stay plain row colour even
        // where the same text inside a php diff would tint `return` and the string.
        self::assertTrue(self::tokenPainted($theme, 'keyword', self::rawRow(self::paint(self::phpFixture(), 130), 'function greet')), 'sanity: php tint is live');
        foreach (self::frameRows($painted) as $row) {
            self::assertFalse(self::tokenPainted($theme, 'keyword', $row), Ansi::strip($row));
            self::assertFalse(self::tokenPainted($theme, 'string', $row), Ansi::strip($row));
        }
        self::assertStringContainsString('alpha', Ansi::strip($painted));
    }

    public function testTheSplitCarriesTwoPerSideGutters(): void
    {
        $context = self::strippedRow(self::paint(self::phpFixture(), 130), '<?php');

        self::assertStringContainsString('<?php', $context);
        self::assertSame(2, substr_count($context, '1│'), 'the same context number is painted on both sides of the divider');
    }

    private function writeStyle(string $style): void
    {
        file_put_contents(
            $this->home . '/.candy-code/config.json',
            (string) json_encode(['diffStyle' => $style]),
        );
        UiSettings::forget();
    }

    private function paint(string $diff, int $width): string
    {
        $method = new ReflectionMethod(Renderer::class, 'renderDiff');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $diff, Theme::default(), $width);
    }

    /**
     * @return list<string>
     */
    private static function frameRows(string $painted): array
    {
        return explode("\n", $painted);
    }

    /**
     * Did one painted row carry both fragments? That pairing is the split's
     * signature — the single column never puts them together.
     */
    private static function rowPaired(string $painted, string $first, string $second): bool
    {
        foreach (explode("\n", $painted) as $row) {
            $text = Ansi::strip($row);
            if (str_contains($text, $first) && str_contains($text, $second)) {
                return true;
            }
        }

        return false;
    }

    private static function strippedRow(string $painted, string $needle): string
    {
        foreach (explode("\n", $painted) as $row) {
            if (str_contains(Ansi::strip($row), $needle)) {
                return Ansi::strip($row);
            }
        }

        self::fail('no painted row carries ' . $needle);
    }

    /**
     * The RAW (SGR-carrying) row whose stripped text contains the needle —
     * highlight spans split the visible text, so searching raw bytes misses.
     */
    private static function rawRow(string $painted, string $needle): string
    {
        foreach (explode("\n", $painted) as $row) {
            if (str_contains(Ansi::strip($row), $needle)) {
                return $row;
            }
        }

        self::fail('no painted row carries ' . $needle);
    }

    /**
     * Whether the theme's token slot actually painted this row: the slot's SGR
     * opener (derived by rendering a sentinel and keeping the bytes before it)
     * must appear in the raw row. A null slot paints nothing.
     */
    private static function tokenPainted(Theme $theme, string $slot, string $row): bool
    {
        $style = $theme->markdown->{$slot};
        if ($style === null) {
            return false;
        }
        $open = explode('_probe_', $style->render('_probe_'), 2)[0];

        return $open !== '' && str_contains($row, $open);
    }

    /**
     * @return array<string, string>
     */
    private static function fixtures(): array
    {
        return [
            'php split' => self::phpFixture(),
            'makefile tabs' => self::tabFixture(),
            'oversized hunk' => self::oversizedFixture(),
            'annotated hunk' => self::annotatedFixture(),
            'plain text' => self::textFixture(),
            'cjk' => self::cjkFixture(),
        ];
    }

    private static function phpFixture(): string
    {
        return implode("\n", [
            '--- a/greet.php',
            '+++ b/greet.php',
            '@@ -1,3 +1,4 @@',
            ' <?php',
            '-$old = 1;',
            '+$name = "hello";',
            '+        $new = 2;',
            ' function greet() {',
            '     return true;',
            ' }',
        ]) . "\n";
    }

    private static function tabFixture(): string
    {
        return implode("\n", [
            '--- a/Makefile',
            '+++ b/Makefile',
            '@@ -1,2 +1,2 @@',
            '-rule:	recipe old',
            '+rule:	recipe',
            ' all:',
            ' 	echo done',
        ]) . "\n";
    }

    private static function oversizedFixture(): string
    {
        return implode("\n", [
            '--- a/big.txt',
            '+++ b/big.txt',
            '@@ -9999999999,3 +9999999999,3 @@',
            ' context line',
            '-old body',
            '+new body',
            ' tail line',
            ' more tail',
            ' and more',
            ' yet more',
            ' final more',
            ' extra tail',
            ' last tail',
        ]) . "\n";
    }

    private static function annotatedFixture(): string
    {
        return implode("\n", [
            '--- a/n.txt',
            '+++ b/n.txt',
            '@@ -1,2 +1,2 @@',
            '-first',
            '\\ No newline at end of file',
            '+second',
        ]) . "\n";
    }

    private static function textFixture(): string
    {
        return implode("\n", [
            '--- a/notes.txt',
            '+++ b/notes.txt',
            '@@ -1,2 +1,2 @@',
            '-alpha return 1',
            '+alpha returns true',
            ' gamma = "delta"',
        ]) . "\n";
    }

    private static function cjkFixture(): string
    {
        return implode("\n", [
            '--- a/cjk.txt',
            '+++ b/cjk.txt',
            '@@ -1,2 +1,2 @@',
            '-日本語のとても長い行です',
            '+中文这是一行很长的内容',
            ' context 上下文',
        ]) . "\n";
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
