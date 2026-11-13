<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Auth\TokenStore;

final class TokenStoreFailClosedTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/tsfc-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        @\chmod($this->dir . '/token', 0600);
        @\unlink($this->dir . '/token');
        @\rmdir($this->dir);
    }

    public function testUnreadableTokenFileIsNotRotated(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file modes');
        }
        $good = TokenStore::new($this->dir)->token();
        \chmod($this->dir . '/token', 0000);
        $caught = null;
        try {
            TokenStore::new($this->dir)->token();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'expected a refusal');
        $this->assertStringContainsString('cannot be read', $caught->getMessage());
        \chmod($this->dir . '/token', 0600);
        $this->assertSame($good, TokenStore::new($this->dir)->token());
    }

    public function testMalformedBytesStillRotate(): void
    {
        $store = TokenStore::new($this->dir);
        $old = $store->token();
        \file_put_contents($this->dir . '/token', "garbage\n");
        $new = TokenStore::new($this->dir)->token();
        $this->assertNotSame($old, $new);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $new);
    }
}
