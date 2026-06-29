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
}
