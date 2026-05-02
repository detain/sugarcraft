<?php

declare(strict_types=1);

namespace CandyCore\Crush\Tests;

use CandyCore\Crush\Message;
use CandyCore\Crush\Role;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testFactoriesSetTheRoleField(): void
    {
        $this->assertSame(Role::User,      Message::user('hi')->role);
        $this->assertSame(Role::Assistant, Message::assistant('hi')->role);
        $this->assertSame(Role::System,    Message::system('hi')->role);
    }

    public function testToWireMatchesProviderShape(): void
    {
        $m = Message::user('hello world', 1700000000);
        $this->assertSame(
            ['role' => 'user', 'content' => 'hello world'],
            $m->toWire(),
        );
    }

    public function testCreatedAtIsUsedWhenProvided(): void
    {
        $m = Message::assistant('reply', 12345);
        $this->assertSame(12345, $m->createdAt);
    }
}
