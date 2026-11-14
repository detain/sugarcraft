<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Exception;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Exception\Exception;
use SugarCraft\Core\Exception\InvalidArgumentException;
use SugarCraft\Core\Exception\ProgramException;
use SugarCraft\Core\Exception\RenderException;
use SugarCraft\Core\Exception\RuntimeException;
use SugarCraft\Core\Exception\SemaphoreClosedException;
use SugarCraft\Core\Exception\TerminalException;

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
        // sc-22 (sugar-core-042): the mirror classes extend their SPL
        // counterparts, not the domain base — the trap closed is precisely
        // that `instanceof Exception` (the lib one) must now be FALSE.
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        $this->assertNotInstanceOf(Exception::class, $e);
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
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertNotInstanceOf(Exception::class, $e);
    }

    public function testRuntimeExceptionWithCode(): void
    {
        $e = new RuntimeException('test message', 42);
        $this->assertSame('test message', $e->getMessage());
        $this->assertSame(42, $e->getCode());
    }

    public function testSPLIdiomCatchesTheMirrorClasses(): void
    {
        // sugar-core-042's acceptance pin: the documented catch idiom
        // catches what the library throws. Reverting either parent to the
        // domain base reddens the matching arm — mutation-proven at sc-22.
        $runtimeCaught = false;
        try {
            throw new RuntimeException('runtime idiom');
        } catch (\RuntimeException $e) {
            $runtimeCaught = true;
            $this->assertSame('runtime idiom', $e->getMessage());
        }
        $this->assertTrue($runtimeCaught);

        $invalidCaught = false;
        try {
            throw new InvalidArgumentException('argument idiom');
        } catch (\InvalidArgumentException $e) {
            $invalidCaught = true;
            $this->assertSame('argument idiom', $e->getMessage());
        }
        $this->assertTrue($invalidCaught);

        // SemaphoreClosedException was the precedent; it stays in the line.
        $semaphoreCaught = false;
        try {
            throw new SemaphoreClosedException('pool gone');
        } catch (\RuntimeException $e) {
            $semaphoreCaught = true;
        }
        $this->assertTrue($semaphoreCaught);
    }

    public function testProgramExceptionKeepsTheRuntimeCatchLineage(): void
    {
        // sc-22 (sugar-core-023): the proc_open site threw a bare
        // \RuntimeException before being wired to this named type; callers
        // catching \RuntimeException at that site must keep working.
        $caught = false;
        try {
            throw ProgramException::fromKey('program.proc_open_failed', ['cmd' => 'sleep 1']);
        } catch (\RuntimeException $e) {
            $caught = true;
            $this->assertInstanceOf(ProgramException::class, $e);
        }
        $this->assertTrue($caught, 'ProgramException must remain catchable as \\RuntimeException');
    }

    public function testTerminalException(): void
    {
        $e = new TerminalException('TTY error');
        $this->assertSame('TTY error', $e->getMessage());
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testTerminalExceptionFromKey(): void
    {
        // sc-22 (sugar-core-044): rewritten from the assertInstanceOf-only
        // shape that could not fail; the key landed with sc-20, so the
        // rendering is pinned concretely.
        $e = TerminalException::fromKey('tty.open_failed');
        $this->assertInstanceOf(TerminalException::class, $e);
        $this->assertSame('could not open the terminal', $e->getMessage());
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
        $this->assertInstanceOf(RenderException::class, $e);
        $this->assertSame('render buffer overflow: the frame exceeded the byte ceiling', $e->getMessage());
    }

    public function testProgramException(): void
    {
        $e = new ProgramException('Program error');
        $this->assertSame('Program error', $e->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testProgramExceptionFromKey(): void
    {
        $e = ProgramException::fromKey('program.init_failed');
        $this->assertInstanceOf(ProgramException::class, $e);
        $this->assertSame('program initialization failed', $e->getMessage());
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
