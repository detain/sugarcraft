<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\MdView\MdViewModel;
use SugarCraft\MdView\ReloadTickMsg;
use PHPUnit\Framework\TestCase;

final class MdViewModelTest extends TestCase
{
    private function content(int $n): string
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = "line $i";
        }
        return implode("\n", $out);
    }

    public function testInitialView(): void
    {
        $m = MdViewModel::fromContent($this->content(3), 80, 5);
        $this->assertStringContainsString('line 1', $m->view());
    }

    public function testQExits(): void
    {
        $m = MdViewModel::fromContent($this->content(3));
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertTrue($m->isExited());
        $this->assertNotNull($cmd);
    }

    public function testEscExits(): void
    {
        $m = MdViewModel::fromContent($this->content(3));
        [$m, ] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($m->isExited());
    }

    public function testCtrlCExits(): void
    {
        $m = MdViewModel::fromContent($this->content(3));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($m->isExited());
    }

    public function testDownScrollsViewport(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $m->viewport->yOffset);
    }

    public function testIgnoresKeysAfterExit(): void
    {
        $m = MdViewModel::fromContent($this->content(20));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        [$m2, $cmd] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame($m, $m2);
        $this->assertNull($cmd);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testPageUpScrollsViewport(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        // Scroll down first
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        // PageUp should go back one viewport height
        [$m, ] = $m->update(new KeyMsg(KeyType::PageUp));
        $this->assertLessThan(2, $m->viewport->yOffset);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testPageDownScrollsViewport(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::PageDown));
        $this->assertGreaterThan(0, $m->viewport->yOffset);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testCtrlUHalfPageUp(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'u', ctrl: true));
        $this->assertLessThan(1, $m->viewport->yOffset);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testHomeGoesToTop(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Home));
        $this->assertSame(0, $m->viewport->yOffset);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testEndGoesToBottom(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::End));
        $this->assertTrue($m->viewport->atBottom());
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testContentFitsViewportNoScrollNeeded(): void
    {
        $m = MdViewModel::fromContent($this->content(2), 80, 5);
        $this->assertSame(0, $m->viewport->yOffset);
        // Press Down - should clamp to max offset (0)
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $m->viewport->yOffset);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testAtBottomWhenContentFits(): void
    {
        $m = MdViewModel::fromContent($this->content(2), 80, 5);
        $this->assertTrue($m->viewport->atBottom());
    }

    public function testInitReturnsNull(): void
    {
        $m = MdViewModel::fromContent($this->content(3));
        $this->assertNull($m->init());
    }

    public function testSubscriptionsReturnsNull(): void
    {
        $m = MdViewModel::fromContent($this->content(3));
        $this->assertNull($m->subscriptions());
    }

    public function testFromContentWithDefaultDimensions(): void
    {
        $m = MdViewModel::fromContent("line1\nline2");
        $this->assertStringContainsString('line1', $m->view());
        $this->assertFalse($m->isExited());
    }

    public function testStatusHeaderExactBytesAndNarrowClip(): void
    {
        // Finding 048: the header was pinned only via contains('%') plus the
        // ↻/'·' markers — dropping '%' into a separate segment or swapping
        // the divide stayed green, and the mid-number clip at narrow width
        // was unpinned. Exact literals now.
        $frame = MdViewModel::fromContent($this->content(5), 80, 4)
            ->withDocument('name')
            ->view();
        // 'name · 0%' spans 9 CELLS ('·' is one cell but two bytes), so the
        // pad is 80 − 9 spaces — a byte-counting str_pad would be one short
        // and this is precisely the cell-vs-byte seam the header keeps.
        $this->assertSame('name · 0%' . str_repeat(' ', 71), explode("\n", $frame)[0]);

        // Narrow clip: at width 6 the truncation lands after the separator,
        // before any digit — a regression that reorders segments shows here.
        $narrow = MdViewModel::fromContent($this->content(5), 6, 4)
            ->withDocument('name')
            ->view();
        $this->assertSame('name ·', explode("\n", $narrow)[0]);
    }

    public function testReloadSweepRefusesOversizeReplacementKeepsFrameAndMarks(): void
    {
        // Finding 003's gate leg: the reload sweep re-read the watched file
        // through a guard-free @file_get_contents, so a grow-past-cap swap
        // OOM'd the LIVE pager while the launch-time "64 MiB contract" sat
        // unused. The sweep now goes through the same DocumentGate: the
        // refused replacement keeps the frame, consumes the tuple, and the
        // header marks ↻! until a later edit is accepted.
        $path = sys_get_temp_dir() . '/mdview-sweep-cap-' . uniqid() . '.md';
        file_put_contents($path, "# live\n");
        $model = MdViewModel::fromContent('RENDERED-LIVE', 40, 5)
            ->withDocument('doc.md')
            ->withWatch($path, static fn (string $raw): string => 'RENDERED:' . $raw);

        // Replace with a file past the cap — metadata-only sparse write,
        // and the gate refuses on fstat before any slurp can happen.
        unlink($path);
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        $this->assertTrue(ftruncate($handle, 67_108_865));
        fclose($handle);

        [$blocked] = $model->update(new ReloadTickMsg());
        $this->assertTrue($blocked->reloadBlocked);
        $this->assertSame(0, $blocked->reloadCount, 'a refusal is not a reload');
        $frame = $blocked->view();
        $this->assertStringContainsString('↻!', $frame);
        $this->assertStringContainsString('RENDERED-LIVE', $frame, 'the refused frame must never blank the pager');

        // Tuple consumed: an identical next tick re-judges nothing, marker holds.
        [$held] = $blocked->update(new ReloadTickMsg());
        $this->assertTrue($held->reloadBlocked);

        // A later real edit recovers: accepted, counter moves, ↻! clears.
        file_put_contents($path, "# newer\n");
        clearstatcache(true, $path);
        [$fixed] = $held->update(new ReloadTickMsg());
        $this->assertFalse($fixed->reloadBlocked);
        $this->assertSame(1, $fixed->reloadCount);
        $this->assertStringContainsString('↻1', $fixed->view());
        unlink($path);
    }

    public function testReloadSweepRefusesBinarySwapAndKeepsLastFrame(): void
    {
        // Finding 003's second probe leg: an ELF payload swapped onto the
        // watched path rendered WITH A SUCCESS FRAME. The shared gate now
        // refuses it; the header tells the user the frame is stale.
        $path = sys_get_temp_dir() . '/mdview-sweep-bin-' . uniqid() . '.md';
        file_put_contents($path, "# live\n");
        $model = MdViewModel::fromContent('RENDERED-LIVE', 40, 5)
            ->withDocument('doc.md')
            ->withWatch($path, static fn (string $raw): string => 'RENDERED:' . $raw);

        file_put_contents($path, "# ELF-ish\x00hostile");
        clearstatcache(true, $path);

        [$blocked] = $model->update(new ReloadTickMsg());
        $this->assertTrue($blocked->reloadBlocked);
        $this->assertSame(0, $blocked->reloadCount);
        $this->assertStringContainsString('↻!', $blocked->view());
        $this->assertStringContainsString('RENDERED-LIVE', $blocked->view());
        unlink($path);
    }

    public function testFromContentClampsHeightToAtLeastOne(): void
    {
        // height of 0 should be clamped to 1
        $m = MdViewModel::fromContent("line1\nline2", 80, 0);
        $this->assertStringContainsString('line1', $m->view());
    }
}
