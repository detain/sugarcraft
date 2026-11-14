<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Git\CherryPick;
use PHPUnit\Framework\TestCase;

final class CherryPickTest extends TestCase
{
    public function testCollectingFactorySetsFlag(): void
    {
        $cp = CherryPick::collecting();
        $this->assertTrue($cp->collecting);
        $this->assertSame('', $cp->commitRef);
    }

    public function testWithCommitRefUpdatesRef(): void
    {
        $cp = CherryPick::collecting();
        $cp2 = $cp->withCommitRef('abc1234');
        $this->assertSame('abc1234', $cp2->commitRef);
        $this->assertTrue($cp2->collecting, 'the ref setter keeps the mode the caller is in');
    }
}
