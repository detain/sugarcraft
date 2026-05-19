<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware\Auth;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\Auth\AuthMethods;
use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class AuthMethodsTest extends TestCase
{
    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    private function stdout(): array
    {
        $w = fopen('php://memory', 'w+');
        $this->assertNotFalse($w);
        return [$w, fn() => $this->readAll($w)];
    }

    private function readAll($r): string
    {
        rewind($r);
        return (string) stream_get_contents($r);
    }

    public function testWritesBannerWithAllMethods(): void
    {
        [$out, $read] = $this->stdout();
        $am = new AuthMethods(['publickey', 'password', 'keyboard-interactive'], $out);
        $reached = false;
        $am->handle(Context::background(), $this->session(), function (Context $c, Session $s) use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
        $banner = $read();
        $this->assertStringStartsWith('SSH_AUTH_METHODS', $banner);
        $this->assertStringContainsString('publickey', $banner);
        $this->assertStringContainsString('password', $banner);
        $this->assertStringContainsString('keyboard-interactive', $banner);
    }

    public function testStoresMethodsInContext(): void
    {
        [$out] = $this->stdout();
        $receivedCtx = null;
        $am = new AuthMethods(['publickey', 'password'], $out);
        $am->handle(Context::background(), $this->session(), function (Context $c, Session $s) use (&$receivedCtx): void {
            $receivedCtx = $c;
        });
        $this->assertNotNull($receivedCtx);
        $methods = AuthMethods::fromContext($receivedCtx);
        $this->assertSame(['publickey', 'password'], $methods);
    }

    public function testFromContextReturnsEmptyWhenKeyMissing(): void
    {
        $ctx = Context::background();
        $methods = AuthMethods::fromContext($ctx);
        $this->assertSame([], $methods);
    }

    public function testFromContextReturnsEmptyWhenValueNotArray(): void
    {
        $ctx = Context::background()->withValue('auth.methods', 'not an array');
        $methods = AuthMethods::fromContext($ctx);
        $this->assertSame([], $methods);
    }

    public function testCallsNextEvenWithEmptyMethodsList(): void
    {
        [$out] = $this->stdout();
        $am = new AuthMethods([], $out);
        $reached = false;
        $am->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testBannerFormatIsSpaceSeparated(): void
    {
        [$out, $read] = $this->stdout();
        $am = new AuthMethods(['a', 'b', 'c'], $out);
        $am->handle(Context::background(), $this->session(), function (): void {});
        $banner = trim($read());
        $this->assertSame('SSH_AUTH_METHODS a b c', $banner);
    }
}
