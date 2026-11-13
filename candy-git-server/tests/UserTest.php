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

    /**
     * S03 (finding 003): the pre-fix single-string match had no final
     * anchor and a comment group that stopped at the first newline, so
     * "valid-key-blob\n<anything>" passed and the trailing segment reached
     * the store as its own authorized_keys line — where sshd reads a
     * leading word as an OPTION (command="…" is login-time RCE planted by
     * a key-add). Per-line validation with D-anchoring refuses the whole
     * call on any bad line, atomically.
     */
    public function testAddAuthorizedKeyRejectsHostileLineShapes(): void
    {
        $good = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk alice@host';

        $hostile = [
            'newline-then-garbage'      => $good . "\nno-pty",
            'newline-then-option'       => $good . "\ncommand=\"/bin/sh\"",
            'garbage-then-newline'      => "not-a-key\n" . $good,
            'blank-line-in-middle'      => $good . "\n\n" . $good . " tail with\nsecond line garbage",
            'crlf-injected-option'      => $good . "\r\ncommand=\"rm -rf /\"",
            'second-line-short-blob'    => $good . "\nssh-ed25519 AAAA short",
            'single-garbage-with-eol'   => "not-a-valid-ssh-key\n",
        ];

        foreach ($hostile as $label => $input) {
            try {
                User::new('alice')->addAuthorizedKey($input);
                $this->fail("hostile shape accepted: {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsStringIgnoringCase('key', $e->getMessage(), "refusal must name the key problem ({$label})");
            }
        }
    }

    public function testAddAuthorizedKeyValidatesCrlfPastesAndAppendsEveryGoodLine(): void
    {
        $k1 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk alice@host';
        $k2 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQD' . \str_repeat('A', 300) . ' rsa@host';

        // A CRLF key-file paste of two valid lines: both land, stripped of
        // the carriage returns, one list entry per line.
        $user = User::new('alice')->addAuthorizedKey($k1 . "\r\n" . $k2 . "\r\n");
        $this->assertSame([$k1, $k2], $user->authorizedKeysList());

        // Whitespace-only input remains a no-op (old contract).
        $this->assertSame([], User::new('bob')->addAuthorizedKey(" \n\r\n ")->authorizedKeysList());

        // An all-blank SECOND line inside a paste is a separator, not an
        // error — only malformed NON-empty lines refuse.
        $ok = User::new('carol')->addAuthorizedKey($k1 . "\n   \n" . $k2);
        $this->assertSame([$k1, $k2], $ok->authorizedKeysList());

        // Immutability: refusal leaves the original untouched.
        $base = User::new('dave')->addAuthorizedKey($k1);
        try {
            $base->addAuthorizedKey($k2 . "\ncommand=\"x\"");
        } catch (\InvalidArgumentException) {
            // expected
        }
        $this->assertSame([$k1], $base->authorizedKeysList(), 'a refused call must append nothing');
    }

    /**
     * S07 (finding 007, store arm): the user store is hand-editable JSON
     * and `(bool) 'false'` is TRUE — the string "false" in admin used to
     * mint a superuser. Same vocabulary as config now; garbage refuses the
     * row so a corrupt security field can never fail open.
     */
    public function testStoreRowsCarryStrictBooleansAndRefuseGarbage(): void
    {
        $u = User::fromArray(['username' => 'a', 'admin' => 'false']);
        $this->assertFalse($u->isAdmin, "string 'false' in the store means NOT admin");

        $u = User::fromArray(['username' => 'b', 'admin' => 'true', 'active' => 'off']);
        $this->assertTrue($u->isAdmin);
        $this->assertFalse($u->isActive);

        $u = User::fromArray(['username' => 'c']);
        $this->assertFalse($u->isAdmin, 'absent admin defaults to the least-privilege false');
        $this->assertTrue($u->isActive, 'absent active defaults to true (the VO contract)');

        $this->expectException(\RuntimeException::class);
        User::fromArray(['username' => 'd', 'admin' => 'maybe']);
    }

    public function testWithAdminFlipsOnlyTheAdminFlag(): void    {
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
