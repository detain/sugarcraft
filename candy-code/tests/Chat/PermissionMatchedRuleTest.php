<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;

/**
 * #9 (prompt display): the matched-rule accessor the permission prompt reads.
 * With no request pending there is no rule to name, so it answers null rather
 * than guessing one.
 */
final class PermissionMatchedRuleTest extends TestCase
{
    public function testNoPendingRequestMeansNoMatchedRule(): void
    {
        $chat = new Chat(history: [], backend: new EchoBackend());

        self::assertNull($chat->permissionMatchedRule());
    }
}
