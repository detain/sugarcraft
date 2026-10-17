<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Exception;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Exception\ExpectEofException;
use SugarCraft\Pty\PtyException;

final class ExpectEofExceptionTest extends TestCase
{
    public function testExtendsPtyException(): void
    {
        $e = new ExpectEofException('EOF before match', ['needle'], '');
        $this->assertInstanceOf(PtyException::class, $e);
    }

    public function testConstructorStoresNeedles(): void
    {
        $e = new ExpectEofException('EOF before match', ['foo', 'bar'], 'buffer content');
        $this->assertSame(['foo', 'bar'], $e->needles);
    }

    public function testConstructorStoresBuffer(): void
    {
        $e = new ExpectEofException('EOF before match', ['needle'], 'partial output');
        $this->assertSame('partial output', $e->buffer);
    }

    public function testForNeedlesSingleNeedle(): void
    {
        $e = ExpectEofException::forNeedles(['password:'], 'user@host$ ');
        $this->assertSame(['password:'], $e->needles);
        $this->assertSame('user@host$ ', $e->buffer);
        $this->assertStringContainsString('"password:"', $e->getMessage());
        $this->assertStringContainsString('password:', $e->getMessage());
        $this->assertStringContainsString('EOF', $e->getMessage());
    }

    public function testForNeedlesMultipleNeedles(): void
    {
        $e = ExpectEofException::forNeedles(['foo', 'bar', 'baz'], 'some text');
        $this->assertStringContainsString('[', $e->getMessage());
        $this->assertStringContainsString('foo', $e->getMessage());
        $this->assertStringContainsString('bar', $e->getMessage());
        $this->assertStringContainsString('baz', $e->getMessage());
    }

    public function testForNeedlesIncludesBufferSize(): void
    {
        $e = ExpectEofException::forNeedles(['needle'], '1234567890');
        $this->assertStringContainsString('10 bytes', $e->getMessage());
    }

    public function testForPattern(): void
    {
        $e = ExpectEofException::forPattern('/pass(word)?:/i', 'buffer content');
        $this->assertSame(['/pass(word)?:/i'], $e->needles);
        $this->assertStringContainsString('pattern', $e->getMessage());
        $this->assertStringContainsString('/pass(word)?:/i', $e->getMessage());
    }

    public function testEscapeHandlesBackslash(): void
    {
        $e = ExpectEofException::forNeedles(['path\\to\\file'], 'buffer');
        // The escape function converts backslashes to \\, so the message contains the escaped form
        $this->assertStringContainsString('path\\\\to\\\\file', $e->getMessage());
    }

    public function testEscapeHandlesQuotes(): void
    {
        $e = ExpectEofException::forNeedles(['say "hello"'], 'buffer');
        // The escape function escapes quotes, so " becomes \"
        $this->assertStringContainsString('\\"hello\\"', $e->getMessage());
    }

    public function testEscapeHandlesNewlines(): void
    {
        $e = ExpectEofException::forNeedles(["line1\nline2"], 'buffer');
        // The escape function escapes \n, so the message should not contain raw newline
        $this->assertStringNotContainsString("\n", $e->getMessage());
        $this->assertStringContainsString('line1', $e->getMessage());
    }

    public function testMessageFormatSingleNeedle(): void
    {
        $e = ExpectEofException::forNeedles(['login:'], 'partial');
        $msg = $e->getMessage();
        $this->assertStringContainsString('Expect:', $msg);
        $this->assertStringContainsString('master EOF', $msg);
        $this->assertStringContainsString('matching', $msg);
    }

    public function testMessageFormatPattern(): void
    {
        $e = ExpectEofException::forPattern('/regex/', 'buffer');
        $msg = $e->getMessage();
        $this->assertStringContainsString('Expect:', $msg);
        $this->assertStringContainsString('pattern', $msg);
    }
}
