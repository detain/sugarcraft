<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\User;

/**
 * @covers \SugarCraft\GitServer\User
 */
final class UserTest extends TestCase
{
    /**
     * Regression pin (2026-10-24): withActive() passed its
     * argument into the isAdmin slot of the private constructor, so
     * deactivating a user silently STRIPPED ADMIN AND LEFT THEM ACTIVE —
     * the exact polarity a trust gate cannot survive. The setters are
     * positional around new self(username, isAdmin, isActive, ...).
     */
    public function testWithActiveFlipsOnlyTheActiveFlag(): void
    {
        $ghost = User::new('ghost')->withAdmin(true)->withActive(false);

        $this->assertTrue($ghost->isAdmin, 'withActive() must not touch isAdmin');
        $this->assertFalse($ghost->isActive, 'withActive(false) must deactivate');
        $this->assertSame('ghost', $ghost->username);

        $original = User::new('ghost')->withAdmin(true);
        $this->assertTrue($original->isActive, 'accounts are active by default');
        $this->assertTrue($ghost !== $original, 'setters stay immutable');
    }

    public function testWithAdminFlipsOnlyTheAdminFlag(): void
    {
        $user = User::new('alice')->withActive(false)->withAdmin();

        $this->assertTrue($user->isAdmin);
        $this->assertFalse($user->isActive, 'withAdmin() must not re-activate');
    }

    public function testBasicFieldSettersCarryTheirSiblings(): void
    {
        $user = User::new('bob')
            ->withAdmin(true)
            ->withActive(false)
            ->withPasswordHash('digest-under-test');

        $this->assertSame('bob', $user->username);
        $this->assertTrue($user->isAdmin);
        $this->assertFalse($user->isActive);
        $this->assertSame('digest-under-test', $user->passwordHash);

        $renamed = $user->withUsername('rob');
        $this->assertSame('rob', $renamed->username);
        $this->assertTrue($renamed->isAdmin);
        $this->assertFalse($renamed->isActive);
        $this->assertSame('digest-under-test', $renamed->passwordHash);
    }
}
