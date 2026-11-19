<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Host\AskExpiry;

final class AskExpiryTest extends TestCase
{
    public function testTheApprovalCeilingIsThirtyMinutes(): void
    {
        // Pinned to the host's approval expiry (the host's wire constant ASK_EXPIRY_SECONDS);
        // sugar-plugins cannot read that constant, so the literal is the contract.
        $this->assertSame(1800, AskExpiry::ASK_EXPIRY_SECONDS);
    }
}
