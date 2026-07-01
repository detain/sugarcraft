<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Glow\GlowModel;
use PHPUnit\Framework\TestCase;

final class GlowModelTest extends TestCase
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
        $m = GlowModel::fromContent($this->content(3), 80, 5);
        $this->assertStringContainsString('line 1', $m->view());
    }

    public function testQExits(): void
    {
        $m = GlowModel::fromContent($this->content(3));
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertTrue($m->isExited());
        $this->assertNotNull($cmd);
    }

    public function testEscExits(): void
    {
        $m = GlowModel::fromContent($this->content(3));
        [$m, ] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($m->isExited());
    }

    public function testCtrlCExits(): void
    {
        $m = GlowModel::fromContent($this->content(3));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($m->isExited());
    }

    public function testDownScrollsViewport(): void
    {
        $m = GlowModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $m->viewport->yOffset);
    }

    public function testIgnoresKeysAfterExit(): void
    {
        $m = GlowModel::fromContent($this->content(20));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        [$m2, $cmd] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame($m, $m2);
        $this->assertNull($cmd);
    }

    /**
     * @see plan_sugar-glow.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testPageUpScrollsViewport(): void
    {
        $m = GlowModel::fromContent($this->content(20), 80, 3);
        // Scroll down first
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        // PageUp should go back one viewport height
        [$m, ] = $m->update(new KeyMsg(KeyType::PageUp));
        $this->assertLessThan(2, $m->viewport->yOffset);
    }

    /**
     * @see plan_sugar-glow.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testPageDownScrollsViewport(): void
    {
        $m = GlowModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::PageDown));
        $this->assertGreaterThan(0, $m->viewport->yOffset);
    }

    /**
     * @see plan_sugar-glow.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testCtrlUHalfPageUp(): void
    {
        $m = GlowModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'u', ctrl: true));
        $this->assertLessThan(1, $m->viewport->yOffset);
    }

    /**
     * @see plan_sugar-glow.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testHomeGoesToTop(): void
    {
        $m = GlowModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Home));
        $this->assertSame(0, $m->viewport->yOffset);
    }

    /**
     * @see plan_sugar-glow.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testEndGoesToBottom(): void
    {
        $m = GlowModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::End));
        $this->assertTrue($m->viewport->atBottom());
    }

    /**
     * @see plan_sugar-glow.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testContentFitsViewportNoScrollNeeded(): void
    {
        $m = GlowModel::fromContent($this->content(2), 80, 5);
        $this->assertSame(0, $m->viewport->yOffset);
        // Press Down - should clamp to max offset (0)
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $m->viewport->yOffset);
    }

    /**
     * @see plan_sugar-glow.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testAtBottomWhenContentFits(): void
    {
        $m = GlowModel::fromContent($this->content(2), 80, 5);
        $this->assertTrue($m->viewport->atBottom());
    }
}
