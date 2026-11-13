<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiInspect\Exec\ExecRunner;

/**
 * Contract tests for the exec-mode runner (audit 003): the pump loop is
 * pinned against a fake session/child pair — these tests never require a
 * real pseudo-terminal, exactly as the finding prescribes. The real-PTY leg
 * lives in CliTest behind a capability skip.
 */
final class ExecRunnerTest extends TestCase
{
    /** @return callable(list<string>): array{0: object, 1: object} */
    private static function fakeSession(array $script, int $status): callable
    {
        return static function (array $cmd) use ($script, $status): array {
            $session = new class ($script) {
                /** @param list<string> $chunks */
                public function __construct(private array $chunks)
                {
                }

                public function read(int $len, ?float $timeout): ?string
                {
                    return array_shift($this->chunks) ?? '';
                }

                public function close(): void
                {
                }
            };
            $child = new class ($status) {
                public function __construct(private int $status)
                {
                }

                public function exited(): bool
                {
                    return true;
                }

                public function wait(): int
                {
                    return $this->status;
                }
            };
            return [$session, $child];
        };
    }

    public function testChunksAreReportedLineByLineAndChildStatusIsCarried(): void
    {
        $lines = [];
        $code = ExecRunner::run(
            ['printf', 'hi'],
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            self::fakeSession(["a\x1b[31m", "red\x1b[0m", ''], 7),
        );

        $this->assertSame(7, $code, 'the child exit status is the tool exit status');
        $this->assertSame(
            ['a', 'ESC[31m  SGR foreground red', 'red', 'ESC[0m  SGR reset'],
            $lines,
        );
    }

    public function testBytesStillPendingAfterTheExitProbeAreDrainedBeforeTheTail(): void
    {
        // A chunk sits in the master buffer while the loop watches for the
        // exit/EOF edge: the drain pass must hand it to the inspector before
        // finish() renders the tail. Note how the plain text across chunks
        // joins into ONE segment — chunk boundaries never mint segments.
        $lines = [];
        $code = ExecRunner::run(
            ['sh', '-c', 'printf x'],
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            self::fakeSession(["\x1b[1mdim-start", "x\x1b[0m", "\x1b[31m", '', ''], 0),
        );

        $this->assertSame(0, $code);
        $this->assertSame(
            ['ESC[1m  SGR bold', 'dim-startx', 'ESC[0m  SGR reset', 'ESC[31m  SGR foreground red'],
            $lines,
        );
    }

    public function testCommandThatPrintsNothingReportsNothingAndKeepsTheStatus(): void
    {
        $lines = [];
        $code = ExecRunner::run(
            ['true'],
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            self::fakeSession([''], 0),
        );

        $this->assertSame(0, $code);
        $this->assertSame([], $lines);
    }

    public function testUnavailablePtyIsARefusalWhoseReasonGoesThroughTheTokenizer(): void
    {
        $err = fopen('php://memory', 'r+');
        $this->assertIsResource($err);
        $code = ExecRunner::run(
            ["printf\r injected"],
            static function (string $line): void {
                self::fail('nothing may be reported when the PTY never opened');
            },
            static function (array $cmd): array {
                throw new \RuntimeException("no /dev/ptmx\r here\x1b[31m");
            },
            $err,
        );
        rewind($err);
        $text = (string) stream_get_contents($err);
        fclose($err);

        $this->assertSame(ExecRunner::EXIT_UNAVAILABLE, $code);
        $this->assertStringContainsString('pseudo-terminal', $text);
        $this->assertStringContainsString('no /dev/ptmxCR hereESC[31m', $text, 'the throw message arrives tokenized');
        $this->assertStringNotContainsString("\x1b", $text, 'the refusal itself goes through the byte tokenizer');
        $this->assertStringNotContainsString("\r", $text, 'the throw message cannot forge refusal lines');
    }

    public function testEmptyCommandIsAFailFastProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExecRunner::run([], static function (string $line): void {
        });
    }
}
