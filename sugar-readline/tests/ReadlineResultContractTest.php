<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\ReadlineResult;
use SugarCraft\Readline\TextPrompt;

/**
 * The typed run() outcome contract (finding 081, gate G7 default leg):
 * submitted / aborted / stopped / value / prompt must state WHY the loop
 * ended instead of leaving hosts to reconstruct it from the prompt shape,
 * and the one-shot stop() latch must be visible on every result a stopped
 * instance produces (the documented no-resume story, not a silent one).
 */
final class ReadlineResultContractTest extends TestCase
{
    public function testSubmitProducesATypedSubmittedResult(): void
    {
        $result = $this->drive("hi\r");

        self::assertTrue($result->submitted);
        self::assertFalse($result->aborted);
        self::assertFalse($result->stopped);
        self::assertSame('hi', $result->value);
        self::assertInstanceOf(TextPrompt::class, $result->prompt);
        self::assertTrue($result->prompt->isSubmitted(), 'the prompt passthrough keeps the full state machine');
    }

    public function testEscapeProducesATypedAbortedResult(): void
    {
        $result = $this->drive("hi\x1b");

        self::assertFalse($result->submitted);
        self::assertTrue($result->aborted);
        self::assertFalse($result->stopped);
    }

    public function testStopLatchIsVisibleOnTheResult(): void
    {
        $driver = new class implements \SugarCraft\Input\InputDriver {
            public int $reads = 0;

            public function read(): ?\SugarCraft\Input\Event
            {
                $this->reads++;

                return null;
            }
        };

        $readline = new Readline($driver, static function (int $us): void {
            self::fail('the idle sleeper must never run once the loop is pre-cancelled');
        });
        $readline->stop();

        $result = $readline->run(TextPrompt::new('> '), null);

        self::assertSame(0, $driver->reads, 'a stopped instance must not poll');
        self::assertTrue($result->stopped);
        self::assertFalse($result->submitted);
        self::assertFalse($result->aborted);
        self::assertSame('', $result->value);
    }

    public function testValueResolutionPrefersTheAnswerAccessors(): void
    {
        $valueFirst = new class {
            public function value(): string
            {
                return 'via-value';
            }

            public function selectedValues(): array
            {
                return ['never'];
            }

            public function selectedValue(): string
            {
                return 'never';
            }

            public function isSubmitted(): bool
            {
                return true;
            }

            public function isAborted(): bool
            {
                return false;
            }
        };
        self::assertSame('via-value', ReadlineResult::fromPrompt($valueFirst, false)->value);

        $multiOnly = new class {
            public function selectedValues(): array
            {
                return ['a', 'b'];
            }

            public function isSubmitted(): bool
            {
                return true;
            }

            public function isAborted(): bool
            {
                return false;
            }
        };
        self::assertSame(['a', 'b'], ReadlineResult::fromPrompt($multiOnly, false)->value);

        $none = new class {
            public function isSubmitted(): bool
            {
                return false;
            }

            public function isAborted(): bool
            {
                return false;
            }
        };
        self::assertNull(ReadlineResult::fromPrompt($none, true)->value);
    }

    private function drive(string $keys): ReadlineResult
    {
        $in = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($in);
        [$iw, $ir] = $in;

        \fwrite($iw, $keys);
        \fclose($iw);

        try {
            return (new Readline(new StreamInputDriver($ir)))->run(TextPrompt::new('> '), null);
        } finally {
            \fclose($ir);
        }
    }
}
