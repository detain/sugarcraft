<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Exception;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Exception\ExpectTimeoutException;
use SugarCraft\Pty\PtyException;

final class ExpectTimeoutExceptionTest extends TestCase
{
    public function testExtendsPtyException(): void
    {
        $e = new ExpectTimeoutException('timeout', ['needle'], 5.0, 'buffer');
        $this->assertInstanceOf(PtyException::class, $e);
    }

    public function testConstructorStoresNeedles(): void
    {
        $e = new ExpectTimeoutException('timeout', ['foo', 'bar'], 3.5, 'buffer');
        $this->assertSame(['foo', 'bar'], $e->needles);
    }

    public function testConstructorStoresTimeout(): void
    {
        $e = new ExpectTimeoutException('timeout', ['needle'], 10.5, 'buffer');
        $this->assertSame(10.5, $e->timeoutSec);
    }

    public function testConstructorStoresBuffer(): void
    {
        $e = new ExpectTimeoutException('timeout', ['needle'], 5.0, 'partial output');
        $this->assertSame('partial output', $e->buffer);
    }

    public function testForNeedlesSingleNeedle(): void
    {
        $e = ExpectTimeoutException::forNeedles(['password:'], 2.5, 'user@host$ ');
        $this->assertSame(['password:'], $e->needles);
        $this->assertSame(2.5, $e->timeoutSec);
        $this->assertSame('user@host$ ', $e->buffer);
        $this->assertStringContainsString('"password:"', $e->getMessage());
        $this->assertStringContainsString('password:', $e->getMessage());
        $this->assertStringContainsString('2.500', $e->getMessage());
        $this->assertStringContainsString('timed out', $e->getMessage());
    }

    public function testForNeedlesMultipleNeedles(): void
    {
        $e = ExpectTimeoutException::forNeedles(['foo', 'bar', 'baz'], 1.0, 'some text');
        $this->assertStringContainsString('[', $e->getMessage());
        $this->assertStringContainsString('foo', $e->getMessage());
        $this->assertStringContainsString('bar', $e->getMessage());
        $this->assertStringContainsString('baz', $e->getMessage());
    }

    public function testForPattern(): void
    {
        $e = ExpectTimeoutException::forPattern('/pass(word)?:/i', 5.0, 'buffer content');
        $this->assertSame(['/pass(word)?:/i'], $e->needles);
        $this->assertSame(5.0, $e->timeoutSec);
        $this->assertStringContainsString('pattern', $e->getMessage());
        $this->assertStringContainsString('/pass(word)?:/i', $e->getMessage());
    }

    public function testForEof(): void
    {
        $e = ExpectTimeoutException::forEof(3.14, 'partial buffer');
        $this->assertSame([], $e->needles);
        $this->assertSame(3.14, $e->timeoutSec);
        $this->assertSame('partial buffer', $e->buffer);
        $this->assertStringContainsString('3.140', $e->getMessage());
        $this->assertStringContainsString('EOF', $e->getMessage());
    }

    public function testEscapeHandlesBackslash(): void
    {
        $e = ExpectTimeoutException::forNeedles(['path\\to\\file'], 1.0, 'buffer');
        // The escape function converts backslashes to \\, so the message contains the escaped form
        $this->assertStringContainsString('path\\\\to\\\\file', $e->getMessage());
    }

    public function testEscapeHandlesQuotes(): void
    {
        $e = ExpectTimeoutException::forNeedles(['say "hello"'], 1.0, 'buffer');
        // The escape function escapes quotes, so " becomes \"
        $this->assertStringContainsString('\\"hello\\"', $e->getMessage());
    }

    public function testEscapeHandlesNewlines(): void
    {
        $e = ExpectTimeoutException::forNeedles(["line1\nline2"], 1.0, 'buffer');
        // The escape function escapes \n, so the message should not contain raw newline
        $this->assertStringNotContainsString("\n", $e->getMessage());
        $this->assertStringContainsString('line1', $e->getMessage());
    }

    public function testMessageFormatSingleNeedle(): void
    {
        $e = ExpectTimeoutException::forNeedles(['login:'], 2.5, 'partial');
        $msg = $e->getMessage();
        $this->assertStringContainsString('Expect:', $msg);
        $this->assertStringContainsString('timed out', $msg);
        $this->assertStringContainsString('waiting for', $msg);
    }

    public function testMessageFormatPattern(): void
    {
        $e = ExpectTimeoutException::forPattern('/regex/', 5.0, 'buffer');
        $msg = $e->getMessage();
        $this->assertStringContainsString('Expect:', $msg);
        $this->assertStringContainsString('pattern', $msg);
    }

    public function testMessageFormatEof(): void
    {
        $e = ExpectTimeoutException::forEof(3.0, 'buffer');
        $msg = $e->getMessage();
        $this->assertStringContainsString('Expect:', $msg);
        $this->assertStringContainsString('EOF', $msg);
    }
}
