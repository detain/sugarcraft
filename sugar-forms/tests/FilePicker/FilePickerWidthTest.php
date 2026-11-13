<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\FilePicker;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\FilePicker\FilePicker;

/**
 * SF-48 (sugar-forms-076): the documented horizontal cap of the FilePicker
 * viewport is real — rows cost terminal cells, long paths are
 * MIDDLE-truncated so the filename tail (the identifying end) survives, and
 * the natural-width (0) shape is byte-frozen at the pre-cap render.
 */
final class FilePickerWidthTest extends TestCase
{
    private string $root;
    private string $deep;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fpw-' . uniqid('', true);
        mkdir($this->root);
        $this->deep = $this->root . '/' . str_repeat('nested-directory-segment/', 6) . 'leaf';
        mkdir($this->deep, 0777, true);
        file_put_contents($this->deep . '/a-fairly-long-directory-entry-name-that-overflow-hopefully-tailmarker.txt', 'x');
        file_put_contents($this->deep . '/short.txt', 'y');
    }

    protected function tearDown(): void
    {
        foreach ([$this->deep . '/a-fairly-long-directory-entry-name-that-overflow-hopefully-tailmarker.txt', $this->deep . '/short.txt',
                  $this->deep, $this->root] as $junk) {
            (is_dir($junk) ? @rmdir($junk) : @unlink($junk));
        }
    }

    /** @return list<string> */
    private function plainRows(FilePicker $p): array
    {
        $out = [];
        foreach (explode("\n", $p->focus()[0]->view()) as $line) {
            $out[] = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
        }
        return $out;
    }

    public function testNaturalWidthIsByteFrozen(): void
    {
        $fresh = FilePicker::new($this->deep, 5);
        $this->assertSame($fresh->view(), $fresh->withWidth(0)->view(), 'width 0 is the documented no-op');
        $this->assertSame(0, $fresh->width());
        $this->assertGreaterThan(40, Width::of($this->deep), 'the fixture must actually overflow a 40-cell cap');
    }

    public function testEveryCappedRowFitsTheBudget(): void
    {
        $rows = $this->plainRows(FilePicker::new($this->deep, 5)->withWidth(40));
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(40, Width::of($row), 'row overflows the cap: ' . $row);
        }
    }

    public function testTailSurvivesTheMiddleTruncation(): void
    {
        $rows = $this->plainRows(FilePicker::new($this->deep, 5)->withWidth(40));
        $long = null;
        foreach ($rows as $row) {
            if (str_contains($row, 'tailmarker')) {
                $long = $row;
            }
        }
        $this->assertNotNull($long, 'the identifying tail of a long name must stay visible');
        $this->assertStringContainsString('…', $long, 'the cut happens in the middle, marked');
        $withCursor = array_values(array_filter($rows, static fn(string $r): bool => str_starts_with($r, '> ')));
        $this->assertNotEmpty($withCursor, 'the cursor row keeps its two prefix cells at the cap');
    }

    public function testHeaderKeepsTheLeafDirectory(): void
    {
        $rows = $this->plainRows(FilePicker::new($this->deep, 5)->withWidth(40));
        $this->assertLessThanOrEqual(40, Width::of($rows[0]));
        $this->assertStringEndsWith('leaf', $rows[0], 'the cwd header elides towards the root, keeps the leaf');
    }

    public function testUnderBudgetRowsAreUntouchedBytes(): void
    {
        $natural = FilePicker::new($this->root, 5);
        $wide    = $natural->withWidth(200);
        $this->assertSame($natural->view(), $wide->view(), 'a budget nothing overflows must not repaint');
    }

    public function testWidthSurvivesNavigationAndRefresh(): void
    {
        // The cap is viewport chrome — setCwd/refresh churn must carry it.
        $p = FilePicker::new($this->root, 5)->withWidth(40)->setCwd($this->deep);
        $this->assertSame(40, $p->width);
        foreach ($this->plainRows($p) as $row) {
            $this->assertLessThanOrEqual(40, Width::of($row));
        }
    }
}
