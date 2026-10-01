<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Lister\Model;
use SugarCraft\Lister\StringItem;
use PHPUnit\Framework\TestCase;

/**
 * Pins for the View()-diff cell projection (audit M3 fix wave).
 *
 * bufferFromOutput() used to copy the whole grid once per cell
 * (Buffer::withCellAt per position → O((w·h)²)); it now builds the flat cell
 * list once and wraps it via Buffer::fromGrid(). These tests pin both the
 * performance envelope and the unchanged cell semantics.
 */
final class BufferProjectionTest extends TestCase
{
    /**
     * Invoke the private projection without paying the render path.
     */
    private function project(string $output, int $width, int $height): Buffer
    {
        $method = new \ReflectionMethod(Model::class, 'bufferFromOutput');
        $model = Model::new()->addItem(new StringItem('seed'));
        return $method->invoke($model, $output, $width, $height);
    }

    public function testLargeViewportProjectionIsFast(): void
    {
        // Pre-fix this shape exceeded 120 s and had to be killed; post-fix it
        // measures ~10 ms on the audit box. The 5 s budget is deliberately
        // generous — it only has to catch a quadratic regression, not jitter.
        $output = \str_repeat("x\n", 200);
        $start = \hrtime(true);
        $buffer = $this->project($output, 500, 200);
        $elapsedSeconds = (\hrtime(true) - $start) / 1e9;

        $this->assertSame(500, $buffer->width());
        $this->assertSame(200, $buffer->height());
        $this->assertLessThan(5.0, $elapsedSeconds,
            '500x200 projection regressed toward quadratic behaviour');
    }

    public function testProjectionCellSemanticsUnchanged(): void
    {
        // 'héllo' with an SGR prefix: every code point — escapes included —
        // occupies one width-1 cell, rows pad with blanks, overflow truncates.
        $buffer = $this->project("\x1b[1mhi\nwaytoolongline", 6, 3);

        $this->assertSame("\x1b", $buffer->cellAt(0, 0)->rune());
        $this->assertSame('i', $buffer->cellAt(5, 0)->rune());
        $this->assertSame('w', $buffer->cellAt(0, 1)->rune());
        $this->assertSame('o', $buffer->cellAt(5, 1)->rune()); // 'waytoo' — truncated at 6
        $this->assertSame(' ', $buffer->cellAt(0, 2)->rune()); // missing row → blank
        $this->assertSame(1, $buffer->cellAt(0, 2)->width());
    }

    public function testViewFullPathOnLargeViewportCompletes(): void
    {
        // End-to-end through View(): first frame + delta on the largest
        // viewport the area guard admits at height 2000.
        $m = Model::new()
            ->setViewport(2000, 1)
            ->addItem(new StringItem('alpha'))
            ->addItem(new StringItem('beta'));

        $start = \hrtime(true);
        $frame1 = $m->view();
        $frame2 = $m->setCursor(1)->view();
        $elapsed = (\hrtime(true) - $start) / 1e9;

        $this->assertStringContainsString('alpha', $frame1);
        $this->assertNotSame($frame1, $frame2);
        $this->assertLessThan(5.0, $elapsed);
    }
}
