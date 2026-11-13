<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\ScriptUi\Command\TableCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * scriptui-31 (finding 042): --widths budgets are DISPLAY CELLS. The old
 * mb_strlen/mb_substr pair counted codepoints, so a row of CJK/emoji blew
 * to ~2x its budget and every border on that row skewed — the exact
 * condition the flag exists to prevent — and the cut could slice a
 * grapheme cluster in half.
 */
final class TableWidthsTest extends TestCase
{
    /** @param list<string> $args */
    private static function renderTable(array $args): string
    {
        $cmd    = new TableCommand();
        $tester = new CommandTester($cmd);
        $status = $tester->execute(['--file' => self::fixture($args)] + $args['opts']);
        self::assertSame(0, $status);
        return $tester->getDisplay();
    }

    private static function fixture(array $args): string
    {
        $path = tempnam(sys_get_temp_dir(), 'swtab');
        file_put_contents($path, $args['csv']);
        return $path;
    }

    public function testCjkBordersStayCellExact(): void
    {
        $out = self::renderTable([
            'csv'  => "名前,説明\n漢字テストです,短い\nok,ascii row\n",
            'opts' => ['--widths' => ['4', '4'], '--border' => 'normal', '--has-header' => true],
        ]);
        $lines = explode("\n", trim($out));
        $widths = array_unique(array_map(static fn (string $l): int => Width::string($l), $lines));
        self::assertCount(1, $widths, 'every rendered line shares one cell width: ' . json_encode($widths));
        self::assertStringContainsString('…', $out, 'truncated cells ellipsise (gum posture)');
    }

    public function testEmojiBudgetCountsCellsNotCodepoints(): void
    {
        // 4 emoji = 4 codepoints but 8 cells — the old code kept ALL of
        // them (strlen 4 <= budget 4); the cell reader must truncate.
        $out = self::renderTable([
            'csv'  => "a,b\n😀😀😀😀,x\n",
            'opts' => ['--widths' => ['4', '4'], '--border' => 'normal'],
        ]);
        self::assertStringNotContainsString('😀😀😀😀', $out);
        $lines = explode("\n", trim($out));
        self::assertCount(1, array_unique(array_map(static fn (string $l): int => Width::string($l), $lines)));
    }

    public function testGraphemeClustersAreNeverSplit(): void
    {
        // e + U+0301 combining acute: a codepoint cut would orphan the
        // combining mark; the cell cut keeps clusters whole.
        $cluster = "e\u{0301}";
        $cell    = $cluster . 'colead' . $cluster;
        $out     = self::renderTable([
            'csv'  => "h\n$cell\n",
            'opts' => ['--widths' => ['5'], '--border' => 'normal'],
        ]);
        // No lone combining mark may appear without its base letter.
        // (Double-quoted so PHP interpolates the codepoint — PCRE2 has
        // no \u{…} escape of its own.)
        self::assertDoesNotMatchRegularExpression("/(?<![\x00-\x7F])\u{0301}/u", $out);
        self::assertStringContainsString($cluster, $out);
    }
}
