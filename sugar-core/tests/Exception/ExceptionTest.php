<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Exception;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Exception\Exception;
use SugarCraft\Core\Exception\InvalidArgumentException;
use SugarCraft\Core\Exception\RuntimeException;
use SugarCraft\Core\Exception\TerminalException;
use SugarCraft\Core\Exception\RenderException;
use SugarCraft\Core\Exception\ProgramException;

final class ExceptionTest extends TestCase
{
    public function testExceptionIsThrowable(): void
    {
        $e = new Exception('test');
        $this->assertInstanceOf(\Throwable::class, $e);
        $this->assertSame('test', $e->getMessage());
    }

    public function testInvalidArgumentExceptionFromKey(): void
    {
        $e = InvalidArgumentException::fromKey('ansi.invalid_fg_code', ['code' => '999']);
        $this->assertSame('invalid 16-color fg code: 999', $e->getMessage());
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testInvalidArgumentExceptionWithCode(): void
    {
        $e = new InvalidArgumentException('test message', 42);
        $this->assertSame('test message', $e->getMessage());
        $this->assertSame(42, $e->getCode());
    }

    public function testRuntimeExceptionFromKey(): void
    {
        $e = RuntimeException::fromKey('program.proc_open_failed', ['cmd' => 'ls']);
        $this->assertSame('proc_open failed for: ls', $e->getMessage());
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testRuntimeExceptionWithCode(): void
    {
        $e = new RuntimeException('test message', 42);
        $this->assertSame('test message', $e->getMessage());
        $this->assertSame(42, $e->getCode());
    }

    public function testTerminalException(): void
    {
        $e = new TerminalException('TTY error');
        $this->assertSame('TTY error', $e->getMessage());
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testTerminalExceptionFromKey(): void
    {
        $e = TerminalException::fromKey('tty.open_failed');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testRenderException(): void
    {
        $e = new RenderException('Render error');
        $this->assertSame('Render error', $e->getMessage());
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testRenderExceptionFromKey(): void
    {
        $e = RenderException::fromKey('render.buffer_overflow');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testProgramException(): void
    {
        $e = new ProgramException('Program error');
        $this->assertSame('Program error', $e->getMessage());
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testProgramExceptionFromKey(): void
    {
        $e = ProgramException::fromKey('program.init_failed');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testExceptionChaining(): void
    {
        $previous = new \RuntimeException('previous');
        $e = new Exception('wrapped', 0, $previous);
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testExceptionIsNotFinal(): void
    {
        $reflection = new \ReflectionClass(Exception::class);
        $this->assertFalse($reflection->isFinal());
    }

    public function testInvalidArgumentExceptionIsFinal(): void
    {
        $reflection = new \ReflectionClass(InvalidArgumentException::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testRuntimeExceptionIsFinal(): void
    {
        $reflection = new \ReflectionClass(RuntimeException::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testTerminalExceptionIsFinal(): void
    {
        $reflection = new \ReflectionClass(TerminalException::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testRenderExceptionIsFinal(): void
    {
        $reflection = new \ReflectionClass(RenderException::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testProgramExceptionIsFinal(): void
    {
        $reflection = new \ReflectionClass(ProgramException::class);
        $this->assertTrue($reflection->isFinal());
    }
}
