<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\{Ansi, Width};
use SugarCraft\Toast\{Action, Position, Toast, ToastType};

/**
 * The bounds cluster: the frame Buffer allocates
 * width*height cells eagerly, so viewport dimensions are now refused outside
 * 1..Toast::MAX_VIEWPORT_DIMENSION instead of OOM-killing the host (an
 * h=30000 winsize previously hit "Allowed memory size exhausted" — an Error
 * catch(\Throwable) cannot trap); narrow boxes must ELLIPSIZE, never delete
 * message characters or action buttons; and the progress bar fills exactly
 * the box interior rather than flooring at 4 cells and pushing the right
 * border off a 5-column box mid-row.
 */
final class ToastViewportBoundsTest extends TestCase
{
    private static function cellWidth(string $line): int
    {
        return Width::string(Ansi::strip($line));
    }

    /** @return list<string> the box rows (rows carrying any border glyph) */
    private static function boxRows(string $frame): array
    {
        $rows = [];
        foreach (\explode("\n", $frame) as $line) {
            $plain = Ansi::strip($line);
            if (\str_contains($plain, '│') || \str_contains($plain, '╭') || \str_contains($plain, '╰')) {
                $rows[] = $plain;
            }
        }
        return $rows;
    }

    public function testOversizedViewportFailsLoudInsteadOfAllocating(): void
    {
        // The historical killer: h=65535 reached Buffer::new and died in
        // OOM territory. The throw must land BEFORE any allocation, which
        // is why the pins below use the tiny-allowed side for the positive.
        $toast = Toast::new(40)->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'bounds');
        foreach ([65535, 10001] as $height) {
            $thrown = null;
            try {
                $toast->view('', 8, $height);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e->getMessage();
            }
            $this->assertNotNull($thrown, "viewport height {$height} must be refused");
            $this->assertStringContainsString('1..10000', $thrown);
        }
    }

    public function testOversizedViewportWidthFailsLoud(): void
    {
        $toast = Toast::new(40)->alert(ToastType::Info, 'bounds');
        $this->expectException(\InvalidArgumentException::class);
        $toast->view('', 20000, 24);
    }

    /** @param array{int,int} $dims */
    #[DataProvider('bogusDimensions')]
    public function testNonPositiveDimensionsAreRefused(array $dims): void
    {
        [$w, $h] = $dims;
        $toast = Toast::new(40)->alert(ToastType::Info, 'bounds');
        $this->expectException(\InvalidArgumentException::class);
        $toast->view('', $w, $h);
    }

    /** @return array<string, array{array{int,int}}> */
    public static function bogusDimensions(): array
    {
        return [
            'zero width'  => [[0, 24]],
            'zero height' => [[80, 0]],
            'both zero'   => [[0, 0]],
            'negative'    => [[-5, -10]],
        ];
    }

    public function testCeilingItselfIsAllowed(): void
    {
        // A MAX x MAX-bounded frame must render (10000x10 = 100k cells —
        // cheap) to prove the range is inclusive, not an off-by-one wall.
        $toast = Toast::new(40)->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'x');
        $out = $toast->view('', Toast::MAX_VIEWPORT_DIMENSION, 10);
        $this->assertNotSame('', $out);
    }

    public function testHitTestDoorsInheritTheGuard(): void
    {
        $toast = Toast::new(40)->alert(ToastType::Info, 'bounds');
        $this->expectException(\InvalidArgumentException::class);
        $toast->actionRects('', 80, 70000);
    }

    public function testNarrowFrameEllipsizesInsteadOfDeletingTheMessage(): void
    {
        // M4 symptom, pre-fix: progressToast('loading') at cols=4 rendered
        // rows bearing only 'o/a/d/i/n' — the leading character was eaten by
        // the styled icon prefix that no longer fit.
        $toast = Toast::new(40)->withPosition(Position::TopLeft)
            ->progressToast(ToastType::Info, 'loading', 0.5);
        $frame = $toast->view('', 4, 24);

        foreach (self::boxRows($frame) as $row) {
            $this->assertSame(4, self::cellWidth($row), 'box row must be exactly the box width: ' . \var_export($row, true));
        }
        // The message must actually be present, not silently vanished.
        $visible = \count(array_filter(
            \str_split('loading'),
            static fn(string $ch): bool => \str_contains($frame, $ch)
        ));
        $this->assertGreaterThanOrEqual(6, $visible, 'narrow boxes degrade (drop the icon), they never delete content');
    }

    public function testActionButtonEllipsizesInsteadOfVanishing(): void
    {
        $toast = Toast::new(60)->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'save failed', actions: [Action::new('Retry', static function () {})]);

        $roomy = $toast->view('', 48, 24);
        $this->assertStringContainsString('[Retry]', $roomy);

        $tight = $toast->view('', 5, 24);
        foreach (self::boxRows($tight) as $row) {
            $this->assertLessThanOrEqual(5, self::cellWidth($row));
        }
        // '[Retry]' cannot fit in 3 inner cells; it must appear ELLIPSIZED
        // (pre-fix: absent entirely, silently).
        $this->assertStringContainsString('…', $tight, 'a button that cannot fit must show the ellipsis');
        $this->assertStringContainsString('[', $tight);
    }

    public function testProgressBarFillsExactlyTheBoxInterior(): void
    {
        // m8 pre-fix: renderProgressBar floored at 4 cells regardless of the
        // box budget — a 5-column frame carried a 6-cell bar row whose right
        // border fell off mid-box.
        $toast = Toast::new(40)->withPosition(Position::TopLeft)
            ->progressToast(ToastType::Info, 'up', 0.5);
        $frame = $toast->view('', 5, 24);
        $rows = self::boxRows($frame);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(5, self::cellWidth($row), 'every box row, progress included, is box-wide: ' . \var_export($row, true));
            // The old 4-cell floor clipped the bar's RIGHT BORDER (the 6th
            // cell fell off the buffer edge) — the row stayed box-wide but
            // ended in a block glyph mid-bar. A box row must always close.
            $this->assertMatchesRegularExpression(
                '/[│╮╯]$/u',
                $row,
                'box row lost its closing border glyph: ' . \var_export($row, true)
            );
        }
    }
}
