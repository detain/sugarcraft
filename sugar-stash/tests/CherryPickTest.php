<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\CherryPick;
use PHPUnit\Framework\TestCase;

final class CherryPickTest extends TestCase
{
    public function testCollectingFactorySetsFlag(): void
    {
        $cp = CherryPick::collecting();
        $this->assertTrue($cp->collecting);
        $this->assertSame('', $cp->commitRef);
    }

    public function testWithCharAppendsToCommitRef(): void
    {
        $cp = CherryPick::collecting();
        $cp2 = $cp->withChar('a');
        $this->assertSame('a', $cp2->commitRef);

        $cp3 = $cp2->withChar('b');
        $this->assertSame('ab', $cp3->commitRef);

        $cp4 = $cp3->withChar('c');
        $this->assertSame('abc', $cp4->commitRef);
    }

    public function testCancelReturnsNonCollecting(): void
    {
        $cp = CherryPick::collecting()->withChar('a')->withChar('b');
        $this->assertSame('ab', $cp->commitRef);

        $cancelled = $cp->cancel();
        $this->assertFalse($cancelled->collecting);
        $this->assertSame('', $cancelled->commitRef);
    }

    public function testWithCommitRefUpdatesRef(): void
    {
        $cp = CherryPick::collecting();
        $cp2 = $cp->withCommitRef('abc1234');
        $this->assertSame('abc1234', $cp2->commitRef);
    }
}
