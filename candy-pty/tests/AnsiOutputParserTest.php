<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use SugarCraft\Pty\Output\AnsiOutputParser;
use SugarCraft\Pty\Output\SgrHandler;
use SugarCraft\Pty\Output\SgrState;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Ansi\Parser\Parser;
use PHPUnit\Framework\TestCase;

final class AnsiOutputParserTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testForMasterWiresUpCorrectly(): void
    {
        $master = new FakeMasterPty2('');
        $parser = AnsiOutputParser::forMaster($master);

        $this->assertInstanceOf(AnsiOutputParser::class, $parser);
        $this->assertSame('default', $parser->state()->describe());
    }

    public function testReadChunkReturnsEmptyOnNoData(): void
    {
        $master = new FakeMasterPty2('');
        $parser = AnsiOutputParser::forMaster($master);

        $chunk = $parser->readChunk(0.001);
        $this->assertSame('', $chunk);
    }

    public function testReadChunkReturnsRawBytes(): void
    {
        $master = new FakeMasterPty2("hello");
        $parser = AnsiOutputParser::forMaster($master);

        $chunk = $parser->readChunk(0.001);
        $this->assertSame('hello', $chunk);
    }

    public function testReadChunkParsesSgrRedForeground(): void
    {
        $master = new FakeMasterPty2("\x1b[31m");
        $parser = AnsiOutputParser::forMaster($master);

        $parser->readChunk(0.001);
        $this->assertSame(SgrState::COLOR_RED, $parser->state()->foreground);
    }

    public function testReadChunkParsesSgrBold(): void
    {
        $master = new FakeMasterPty2("\x1b[1m");
        $parser = AnsiOutputParser::forMaster($master);

        $parser->readChunk(0.001);
        $this->assertTrue($parser->state()->bold);
    }

    public function testReadChunkParsesSgrReset(): void
    {
        $master = new FakeMasterPty2("\x1b[31m\x1b[0m");
        $parser = AnsiOutputParser::forMaster($master);

        $parser->readChunk(0.001);
        $this->assertSame(SgrState::COLOR_DEFAULT, $parser->state()->foreground);
        $this->assertFalse($parser->state()->bold);
    }

    public function testReadChunkWithTransitionsReturnsEmptyOnNoSgr(): void
    {
        $master = new FakeMasterPty2("hello");
        $parser = AnsiOutputParser::forMaster($master);

        $transitions = $parser->readChunkWithTransitions(0.001);
        $this->assertSame([], $transitions);
    }

    public function testReadChunkWithTransitionsReturnsTransitionOnSgr(): void
    {
        $master = new FakeMasterPty2("\x1b[31m");
        $parser = AnsiOutputParser::forMaster($master);

        $transitions = $parser->readChunkWithTransitions(0.001);
        $this->assertNotSame([], $transitions);
        $this->assertCount(1, $transitions);
        [$before, $after] = $transitions[0];
        $this->assertSame(SgrState::COLOR_DEFAULT, $before->foreground);
        $this->assertSame(SgrState::COLOR_RED, $after->foreground);
    }

    public function testReadChunkWithTransitionsReturnsTransitionOnResetAfterChange(): void
    {
        // \x1b[31m = SGR 1 (red foreground), \x1b[0m = SGR 0 (reset)
        // This produces TWO transitions:
        //   1. default → red (on \x1b[31m)
        //   2. red → reset/default (on \x1b[0m)
        $master = new FakeMasterPty2("\x1b[31m\x1b[0m");
        $parser = AnsiOutputParser::forMaster($master);

        $transitions = $parser->readChunkWithTransitions(0.001);
        $this->assertCount(2, $transitions);

        // Transition 1: default → red
        [$from1, $to1] = $transitions[0];
        $this->assertSame(SgrState::COLOR_DEFAULT, $from1->foreground);
        $this->assertSame(SgrState::COLOR_RED, $to1->foreground);

        // Transition 2: red → reset (back to default)
        [$from2, $to2] = $transitions[1];
        $this->assertSame(SgrState::COLOR_RED, $from2->foreground);
        $this->assertSame(SgrState::COLOR_DEFAULT, $to2->foreground);
    }

    public function testStateReturnsHandlerState(): void
    {
        $master = new FakeMasterPty2('');
        $parser = AnsiOutputParser::forMaster($master);

        $this->assertInstanceOf(SgrState::class, $parser->state());
    }

    /**
     * F4 (lane A7 re-verify): this test used to pin the BROKEN shape — reset
     * left the SGR state red — because its name admitted it ("StateOnly").
     * The docblock contract always promised "clears SGR state"; reset() now
     * honors it, so the pin is flipped in-step with the fix.
     */
    public function testResetClearsSgrState(): void
    {
        $master = new FakeMasterPty2("\x1b[31m");
        $parser = AnsiOutputParser::forMaster($master);

        $parser->readChunk(0.001);
        $this->assertSame(SgrState::COLOR_RED, $parser->state()->foreground);

        $parser->reset();
        $this->assertSame(SgrState::COLOR_DEFAULT, $parser->state()->foreground);
    }

    /**
     * F4: after reset, a following plain chunk parses from a clean slate —
     * no lingering red, and the event log starts empty.
     */
    public function testResetGivesSubsequentChunksACleanSlate(): void
    {
        $master = new FakeMasterPtyQueue(["\x1b[31mred text", "plain text"]);
        $handler = new SgrHandler();
        $parser = new AnsiOutputParser($master, new Parser($handler), $handler);

        $parser->readChunk(0.001);
        $this->assertSame(SgrState::COLOR_RED, $parser->state()->foreground);

        $parser->reset();
        $this->assertSame('default', $parser->state()->describe());
        $this->assertSame([], $handler->drainTransitions()); // log cleared too

        $this->assertSame('plain text', $parser->readChunk(0.001));
        $this->assertSame(SgrState::COLOR_DEFAULT, $parser->state()->foreground);
        $this->assertSame([], $handler->drainTransitions()); // no phantom events
    }

    /**
     * F1 (lane A7 re-verify): the probe stream "\x1b[31m…\x1b[91m…\x1b[39m"
     * reported 2 events for 3 real changes. Through the full parser path it
     * must now report all three transitions.
     */
    public function testBrightStreamReportsEveryTransitionThroughParser(): void
    {
        $master = new FakeMasterPty2("\x1b[31mred\x1b[91mbright\x1b[39mdefault");
        $parser = AnsiOutputParser::forMaster($master);

        $transitions = $parser->readChunkWithTransitions(0.001);
        $this->assertCount(3, $transitions);
        $this->assertSame(SgrState::COLOR_BRIGHT_RED, $transitions[1][1]->foreground);
        $this->assertSame(SgrState::COLOR_DEFAULT, $transitions[2][1]->foreground);
    }

    /** F3 (lane A7 re-verify): readChunk returns raw bytes, escapes included. */
    public function testReadChunkReturnsRawBytesIncludingEscapes(): void
    {
        $master = new FakeMasterPty2("\x1b[31mred");
        $parser = AnsiOutputParser::forMaster($master);

        $this->assertSame("\x1b[31mred", $parser->readChunk(0.001));
    }

    /**
     * F5 (lane A7 re-verify): truecolor channels clamp on the byte path too —
     * "\x1b[38;2;300;0;0m" must pack r=255, not wrap to 44.
     */
    public function testReadChunkClampsTruecolorChannels(): void
    {
        $master = new FakeMasterPty2("\x1b[38;2;300;0;0m");
        $parser = AnsiOutputParser::forMaster($master);

        $parser->readChunk(0.001);
        $this->assertSame(255 << 16, $parser->state()->foregroundRgb);
    }

    public function testReadChunkWithMultipleSgrParameters(): void
    {
        $master = new FakeMasterPty2("\x1b[1;31;42m");
        $parser = AnsiOutputParser::forMaster($master);

        $parser->readChunk(0.001);
        $this->assertTrue($parser->state()->bold);
        $this->assertSame(SgrState::COLOR_RED, $parser->state()->foreground);
        $this->assertSame(SgrState::COLOR_GREEN, $parser->state()->background);
    }

    public function testReadChunkWithSgrForeground256(): void
    {
        $master = new FakeMasterPty2("\x1b[38;5;196m");
        $parser = AnsiOutputParser::forMaster($master);

        $parser->readChunk(0.001);
        $this->assertSame(SgrState::COLOR_DEFAULT, $parser->state()->foreground);
        $this->assertSame(196, $parser->state()->foreground256);
    }

    public function testReadChunkWithSgrForegroundRgb(): void
    {
        $master = new FakeMasterPty2("\x1b[38;2;255;128;0m");
        $parser = AnsiOutputParser::forMaster($master);

        $parser->readChunk(0.001);
        $this->assertSame(SgrState::COLOR_DEFAULT, $parser->state()->foreground);
        $this->assertSame((255 << 16) | (128 << 8) | 0, $parser->state()->foregroundRgb);
    }
}

final class FakeMasterPty2 implements MasterPty
{
    public function __construct(
        private string $initial = '',
        private bool $eof = false,
    ) {}

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        if ($this->initial !== '') {
            $out = $this->initial;
            $this->initial = '';
            return $out;
        }
        if ($this->eof) {
            return '';
        }
        return null;
    }

    public function write(string $bytes): int
    {
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void {}

    public function size(): array
    {
        return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
    }

    public function stream(): mixed
    {
        return null;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function fd(): int
    {
        return -1;  // sentinel invalid fd for test fixture
    }
}

/**
 * Master PTY fixture that serves a queue of chunks, one per read() call,
 * then EOF — for tests that need multi-chunk sessions (e.g. F4's
 * paint→reset→paint-plain sequence).
 */
final class FakeMasterPtyQueue implements MasterPty
{
    /** @param list<string> $chunks */
    public function __construct(
        private array $chunks = [],
    ) {}

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        if ($this->chunks === []) {
            return '';
        }
        return \array_shift($this->chunks);
    }

    public function write(string $bytes): int
    {
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void {}

    public function size(): array
    {
        return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
    }

    public function stream(): mixed
    {
        return null;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function fd(): int
    {
        return -1;  // sentinel invalid fd for test fixture
    }
}
