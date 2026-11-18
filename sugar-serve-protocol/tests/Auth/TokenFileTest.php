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
}
