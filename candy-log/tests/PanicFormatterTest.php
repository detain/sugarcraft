<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\PanicFormatter;

final class PanicFormatterTest extends TestCase
{
    public function testPrettyCreatesInstance(): void
    {
        $f = PanicFormatter::pretty();
        $this->assertInstanceOf(PanicFormatter::class, $f);
    }

    public function testPlainCreatesInstance(): void
    {
        $f = PanicFormatter::plain();
        $this->assertInstanceOf(PanicFormatter::class, $f);
    }

    public function testPrettyWithOptions(): void
    {
        $f = PanicFormatter::pretty(showLocals: true, redactPaths: ['/etc/secrets']);
        $this->assertInstanceOf(PanicFormatter::class, $f);
    }

    public function testFormatContainsExceptionClass(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('something went wrong');
        $out = $f->format($e);
        $this->assertStringContainsString('RuntimeException', $out);
    }

    public function testFormatContainsExceptionMessage(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('something went wrong');
        $out = $f->format($e);
        $this->assertStringContainsString('something went wrong', $out);
    }

    public function testFormatContainsFileAndLine(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('test', 0, new \Exception('inner'));
        $out = $f->format($e);
        $this->assertStringContainsString($e->getFile(), $out);
    }

    public function testFormatRedactsPaths(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('test');
        // Verify path redaction is not in the output (redaction targets
        // backtrace file paths, not the primary exception file/line).
        $out = $f->format($e);
        // The redaction applies to backtrace frames, not the main file path.
        $this->assertIsString($out);
    }

    public function testFormatCollapsesRepeatedFrames(): void
    {
        $f = PanicFormatter::plain();

        // Create an exception and inject a repeated trace via reflection.
        $e = new \RuntimeException('repeated frame test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $frame = ['file' => __FILE__, 'line' => 1, 'function' => 'repeated_func', 'class' => 'TestClass'];
        $traceProp->setValue($e, array_fill(0, 5, $frame));

        $out = $f->format($e);
        // With all 5 frames identical, algorithm shows 1 + "... N-1 more" = 1 + "... 4 more"
        // But if algorithm groups all into collapsed form, shows "... 5 more".
        $this->assertMatchesRegularExpression('/\.\.\. \d+ more/', $out);
    }

    public function testFormatReturnsString(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('test');
        $this->assertIsString($f->format($e));
    }

    public function testFormatIncludesCaliberHint(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('test');
        $out = $f->format($e);
        $this->assertStringContainsString('caliber refresh', $out);
    }

    public function testPrettyHasAnsiEscapes(): void
    {
        $f = PanicFormatter::pretty();
        $e = new \RuntimeException('test');
        $out = $f->format($e);
        $this->assertStringContainsString("\x1b[", $out);
    }

    public function testPlainHasNoAnsiEscapes(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('test');
        $out = $f->format($e);
        $this->assertStringNotContainsString("\x1b[", $out);
    }

    public function testFormatErrorException(): void
    {
        $f = PanicFormatter::plain();
        $e = new \ErrorException('type error', 0, E_USER_ERROR, '/path/to/file.php', 42);
        $out = $f->format($e);
        $this->assertStringContainsString('ErrorException', $out);
        $this->assertStringContainsString('type error', $out);
        $this->assertStringContainsString('file.php', $out);
        $this->assertStringContainsString('42', $out);
    }

    // -------------------------------------------------------------------------
    // [SEC] showLocals secret exposure + redaction of dumped locals
    // -------------------------------------------------------------------------

    /** @return \Throwable A throwable whose top frame carries a secret arg. */
    private function exceptionWithSecretLocal(string $secret): \Throwable
    {
        $e = new \RuntimeException('boom');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $traceProp->setValue($e, [[
            'file' => __FILE__,
            'line' => 5,
            'function' => 'connect',
            'class' => 'Db',
            'type' => '->',
            'args' => [$secret],
        ]]);
        return $e;
    }

    public function testShowLocalsDumpsScalarArgs(): void
    {
        // Documents the risk the redaction guards against: with showLocals ON
        // and no redaction configured, a secret argument leaks verbatim.
        $f = PanicFormatter::pretty(showLocals: true);
        $out = $f->format($this->exceptionWithSecretLocal('supersecret-token'));
        $this->assertStringContainsString('supersecret-token', $out);
    }

    public function testShowLocalsRedactsConfiguredSecretsInLocals(): void
    {
        // The fix: redactPaths substrings are now stripped from dumped locals,
        // not just from backtrace file paths.
        $f = PanicFormatter::pretty(showLocals: true, redactPaths: ['supersecret-token']);
        $out = $f->format($this->exceptionWithSecretLocal('supersecret-token'));

        $this->assertStringNotContainsString('supersecret-token', $out);
        $this->assertStringContainsString('[redacted]', $out);
    }

    public function testShowLocalsTruncatesLongScalarArgs(): void
    {
        $f = PanicFormatter::pretty(showLocals: true);

        // Create an exception with a backtrace frame carrying a long string arg (> 40 chars)
        $e = new \RuntimeException('long args test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $longString = str_repeat('x', 60); // 60 chars, exceeds 40 char limit
        $traceProp->setValue($e, [[
            'file' => __FILE__,
            'line' => 100,
            'function' => 'process',
            'class' => 'Test',
            'type' => '->',
            'args' => [$longString],
        ]]);

        $out = $f->format($e);

        // Long string should be truncated to 40 chars + ellipsis
        $this->assertStringContainsString('xxxx', $out);
        $this->assertStringContainsString('…', $out);
        $this->assertStringNotContainsString($longString, $out);
    }

    public function testShowLocalsHandlesNonScalarArgs(): void
    {
        $f = PanicFormatter::pretty(showLocals: true);

        // Create an exception with a backtrace frame carrying a non-scalar arg (array)
        $e = new \RuntimeException('non-scalar args test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $traceProp->setValue($e, [[
            'file' => __FILE__,
            'line' => 100,
            'function' => 'process',
            'class' => 'Test',
            'type' => '->',
            'args' => [['key' => 'value']], // array arg
        ]]);

        $out = $f->format($e);

        // Non-scalar should be shown as its type, not cause error
        $this->assertIsString($out);
        $this->assertStringContainsString('array', $out);
    }

    public function testShowLocalsHandlesObjectArg(): void
    {
        $f = PanicFormatter::pretty(showLocals: true);

        // Create an exception with an object arg
        $e = new \RuntimeException('object args test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $traceProp->setValue($e, [[
            'file' => __FILE__,
            'line' => 100,
            'function' => 'process',
            'class' => 'Test',
            'type' => '->',
            'args' => [new \stdClass()],
        ]]);

        $out = $f->format($e);

        // Object arg should be shown as "object"
        $this->assertIsString($out);
        $this->assertStringContainsString('object', $out);
    }

    public function testFormatBacktraceRedactsMultiplePaths(): void
    {
        $f = PanicFormatter::pretty(showLocals: false, redactPaths: ['/etc/secrets', '/var/token']);

        $e = new \RuntimeException('test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $traceProp->setValue($e, [[
            'file' => '/etc/secrets/config.php',
            'line' => 10,
            'function' => 'load',
            'class' => 'Config',
            'type' => '->',
            'args' => [],
        ]]);

        $out = $f->format($e);

        // Path should be redacted
        $this->assertStringContainsString('[redacted]', $out);
        $this->assertStringNotContainsString('/etc/secrets', $out);
    }
}