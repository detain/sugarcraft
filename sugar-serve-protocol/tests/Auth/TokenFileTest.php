<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Tests\Auth;

use PHPUnit\Framework\TestCase;
use SugarCraft\ServeProtocol\Auth\TokenFile;
use SugarCraft\ServeProtocol\Tests\Support\TempTree;

final class TokenFileTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempTree::make();
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->dir);
    }

    public function testFileNameIsToken(): void
    {
        self::assertSame('token', TokenFile::FILE);
    }

    public function testAbsentFileReadsAsNull(): void
    {
        self::assertNull(TokenFile::read($this->dir));
    }

    public function testValidTokenIsReturnedTrimmed(): void
    {
        \file_put_contents($this->dir . '/token', self::TOKEN . "\n");

        self::assertSame(self::TOKEN, TokenFile::read($this->dir));
    }

    public function testUppercaseHexIsMalformed(): void
    {
        \file_put_contents($this->dir . '/token', \strtoupper(self::TOKEN));

        self::assertNull(TokenFile::read($this->dir));
    }

    public function testShortTokenIsMalformed(): void
    {
        \file_put_contents($this->dir . '/token', 'abc123');

        self::assertNull(TokenFile::read($this->dir));
    }

    public function testLargeNonTokenFileIsMalformed(): void
    {
        \file_put_contents($this->dir . '/token', self::TOKEN . \str_repeat('a', 1 << 20));

        self::assertNull(TokenFile::read($this->dir));
    }

    public function testTokenFollowedByTrailingWhitespacePastOldLimitStillReads(): void
    {
        // Matches the host's TokenStore::read, which trims before it validates and
        // has no size cap: a good token must not be rotated away for its padding.
        \file_put_contents($this->dir . '/token', self::TOKEN . "\n" . \str_repeat(' ', 200));

        self::assertSame(self::TOKEN, TokenFile::read($this->dir));
    }

    public function testSymlinkedTokenIsIgnored(): void
    {
        $real = $this->dir . '/real-token';
        \file_put_contents($real, self::TOKEN);
        \symlink($real, $this->dir . '/token');

        self::assertNull(TokenFile::read($this->dir));
    }

    public function testDirectoryNamedTokenIsIgnored(): void
    {
        \mkdir($this->dir . '/token');

        self::assertNull(TokenFile::read($this->dir));
    }

    public function testPresentButUnreadableTokenThrowsInsteadOfReadingAsNull(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root reads a mode 000 file, so the unreadable case cannot be built');
        }
        $path = $this->dir . '/token';
        \file_put_contents($path, self::TOKEN);
        \chmod($path, 0o000);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('cannot be read');
            TokenFile::read($this->dir);
        } finally {
            \chmod($path, 0o600);
        }
    }
}
