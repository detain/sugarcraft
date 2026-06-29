<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware\Auth;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\Auth\KeyboardInteractive;
use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class KeyboardInteractiveTest extends TestCase
{
    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    private function makeStdin(string $data): mixed
    {
        $s = fopen('php://memory', 'r+');
        $this->assertNotFalse($s);
        fwrite($s, $data);
        rewind($s);
        return $s;
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

    private function stderr(): array
    {
        $w = fopen('php://memory', 'w+');
        $this->assertNotFalse($w);
        return [$w, fn() => $this->readAll($w)];
    }

    public function testPassesThroughWhenNoValidatorAndAllPromptsAnswered(): void
    {
        $stdin = $this->makeStdin("answer1\nanswer2\n");
        [$out] = $this->stdout();
        [$err] = $this->stderr();
        $ki = new KeyboardInteractive(
            [['prompt' => 'First?', 'echo' => true], ['prompt' => 'Second?']],
            null,
            stdout: $out,
            stdin: $stdin,
            stderr: $err
        );
        $reached = false;
        $ki->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectsWhenValidatorReturnsFalse(): void
    {
        $stdin = $this->makeStdin("wrong\n");
        [$out] = $this->stdout();
        [$err, $readErr] = $this->stderr();
        $ki = new KeyboardInteractive(
            [['prompt' => 'Password?']],
            fn($responses) => $responses[0] === 'correct',
            stdout: $out,
            stdin: $stdin,
            stderr: $err
        );
        $reached = false;
        $ki->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Authentication failed', $readErr());
    }

    public function testAcceptsWhenValidatorReturnsTrue(): void
    {
        $stdin = $this->makeStdin("correct\n");
        [$out] = $this->stdout();
        [$err] = $this->stderr();
        $ki = new KeyboardInteractive(
            [['prompt' => 'Password?']],
            fn($responses) => $responses[0] === 'correct',
            stdout: $out,
            stdin: $stdin,
            stderr: $err
        );
        $reached = false;
        $ki->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testStoresResponsesInContext(): void
    {
        $stdin = $this->makeStdin("r1\nr2\nr3\n");
        [$out] = $this->stdout();
        [$err] = $this->stderr();
        $ki = new KeyboardInteractive(
            [['prompt' => 'Q1'], ['prompt' => 'Q2'], ['prompt' => 'Q3']],
            null,
            stdout: $out,
            stdin: $stdin,
            stderr: $err
        );
        $receivedCtx = null;
        $ki->handle(Context::background(), $this->session(), function (Context $c, Session $s) use (&$receivedCtx): void {
            $receivedCtx = $c;
        });
        $this->assertNotNull($receivedCtx);
        $responses = $receivedCtx->value('auth.ki.responses');
        $this->assertSame(['r1', 'r2', 'r3'], $responses);
    }

    public function testWritesPromptCountThenPromptsToStdout(): void
    {
        $stdin = $this->makeStdin("\n");
        [$out, $readOut] = $this->stdout();
        [$err] = $this->stderr();
        $ki = new KeyboardInteractive(
            [['prompt' => 'Enter PIN:', 'echo' => false]],
            null,
            stdout: $out,
            stdin: $stdin,
            stderr: $err
        );
        $ki->handle(Context::background(), $this->session(), function (): void {});
        $output = $readOut();
        $this->assertStringContainsString('Enter PIN:', $output);
    }

    public function testCallsNextWithDerivedContext(): void
    {
        $stdin = $this->makeStdin("x\n");
        [$out] = $this->stdout();
        [$err] = $this->stderr();
        $ki = new KeyboardInteractive(
            [['prompt' => 'Q?']],
            null,
            stdout: $out,
            stdin: $stdin,
            stderr: $err
        );
        $original = Context::background()->withValue('existing', 'key');
        $derived = null;
        $ki->handle($original, $this->session(), function (Context $c, Session $s) use (&$derived): void {
            $derived = $c;
        });
        $this->assertNotNull($derived);
        $this->assertSame('key', $derived->value('existing'));
        $this->assertSame(['x'], $derived->value('auth.ki.responses'));
    }

    public function testWritesRfc4256WireFormatWithNameInstructionAndCount(): void
    {
        $stdin = $this->makeStdin("\n");
        [$out, $readOut] = $this->stdout();
        [$err] = $this->stderr();

        $ki = new KeyboardInteractive(
            [['prompt' => 'Enter PIN:', 'echo' => false]],
            null,
            'Login',
            'Please enter your PIN',
            stdout: $out,
            stdin: $stdin,
            stderr: $err
        );

        $ki->handle(Context::background(), $this->session(), function (): void {});

        $output = $readOut();
        $lines = explode("\n", rtrim($output, "\n"));

        // RFC 4256: Name, Instruction, NumberOfPrompts, then per-prompt Prompt + Echo flag
        $this->assertSame('Login', $lines[0]);
        $this->assertSame('Please enter your PIN', $lines[1]);
        $this->assertSame('1', $lines[2]);
        $this->assertSame('Enter PIN:', $lines[3]);
        $this->assertSame('false', $lines[4]);
    }

    public function testEchoFlagFalseWrittenForNonEchoPrompt(): void
    {
        $stdin = $this->makeStdin("\n");
        [$out, $readOut] = $this->stdout();
        [$err] = $this->stderr();

        $ki = new KeyboardInteractive(
            [['prompt' => 'Password:', 'echo' => false]],
            null,
            stdout: $out,
            stdin: $stdin,
            stderr: $err
        );

        $ki->handle(Context::background(), $this->session(), function (): void {});

        $output = $readOut();
        // The 'false' flag line must appear after the prompt line.
        $this->assertStringContainsString("Password:\n", $output);
        $this->assertStringContainsString("false\n", $output);
    }

    public function testEchoFlagTrueWrittenForEchoPrompt(): void
    {
        $stdin = $this->makeStdin("\n");
        [$out, $readOut] = $this->stdout();
        [$err] = $this->stderr();

        $ki = new KeyboardInteractive(
            [['prompt' => 'Username:', 'echo' => true]],
            null,
            stdout: $out,
            stdin: $stdin,
            stderr: $err
        );

        $ki->handle(Context::background(), $this->session(), function (): void {});

        $output = $readOut();
        $this->assertStringContainsString("Username:\n", $output);
        $this->assertStringContainsString("true\n", $output);
    }

    public function testMultiPromptWireFormat(): void
    {
        $stdin = $this->makeStdin("a\nb\n");
        [$out, $readOut] = $this->stdout();
        [$err] = $this->stderr();

        $ki = new KeyboardInteractive(
            [
                ['prompt' => 'First:', 'echo' => true],
                ['prompt' => 'Second:', 'echo' => false],
            ],
            null,
            'Auth',
            'Step 1 of 2',
            stdout: $out,
            stdin: $stdin,
            stderr: $err
        );

        $ki->handle(Context::background(), $this->session(), function (): void {});

        $output = $readOut();
        $lines = explode("\n", rtrim($output, "\n"));

        $this->assertSame('Auth', $lines[0]);
        $this->assertSame('Step 1 of 2', $lines[1]);
        $this->assertSame('2', $lines[2]);
        $this->assertSame('First:', $lines[3]);
        $this->assertSame('true', $lines[4]);
        $this->assertSame('Second:', $lines[5]);
        $this->assertSame('false', $lines[6]);
    }
}
