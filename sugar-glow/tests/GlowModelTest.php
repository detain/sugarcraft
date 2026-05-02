<?php

declare(strict_types=1);

namespace CandyCore\Glow\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Glow\GlowModel;
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
}
