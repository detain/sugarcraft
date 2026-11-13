<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\App;

/**
 * admindata-010: the async admin connection key must fold the credential in.
 * The old key (flavor|dsn|user) let a password rotation keep handing out the
 * OLD authenticated session — the fingerprint leg is the entire cure.
 */
final class AdminConnKeyTest extends TestCase
{
    /** @Invoke the private static App::adminConnKey. */
    private function keyFor(bool $pg, string $dsn, string $user, string $pass): string
    {
        $m = new \ReflectionMethod(App::class, 'adminConnKey');
        $m->setAccessible(true);

        return $m->invoke(null, $pg, $dsn, $user, $pass);
    }

    public function testRotatedPasswordChangesTheKey(): void
    {
        $before = $this->keyFor(false, 'mysql:host=h;dbname=d', 'app', 'alpha');
        $after = $this->keyFor(false, 'mysql:host=h;dbname=d', 'app', 'bravo');

        $this->assertNotSame($before, $after, 'a rotation must force a fresh dial');
    }

    public function testTheSecretNeverRidesTheKeyItself(): void
    {
        $key = $this->keyFor(false, 'mysql:host=h;dbname=d', 'app', 'hunter2correct');

        $this->assertStringNotContainsString('hunter2correct', $key);
        $this->assertStringContainsString(hash('sha256', 'hunter2correct'), $key);
    }

    public function testStableInputsProduceTheStableKey(): void
    {
        $this->assertSame(
            $this->keyFor(true, 'pgsql:host=h', 'u', 'p'),
            $this->keyFor(true, 'pgsql:host=h', 'u', 'p'),
        );
        $this->assertStringStartsWith('pgsql|', $this->keyFor(true, 'x', 'u', 'p'));
        $this->assertStringStartsWith('mysql|', $this->keyFor(false, 'x', 'u', 'p'));
    }
}
