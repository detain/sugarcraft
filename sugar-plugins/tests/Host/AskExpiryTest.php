<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Host\AskExpiry;

final class AskExpiryTest extends TestCase
{
    public function testTheApprovalCeilingIsThirtyMinutes(): void
    {
        // A literal copy of the host's ASK_EXPIRY_SECONDS. sugar-plugins cannot read that
        // constant, so this assertion checks only the copy, not agreement with the host.
        $this->assertSame(1800, AskExpiry::ASK_EXPIRY_SECONDS);
    }
}
