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

    public function testResetClearsParserStateOnly(): void
    {
        $master = new FakeMasterPty2("\x1b[31m");
        $parser = AnsiOutputParser::forMaster($master);

        $parser->readChunk(0.001);
        $this->assertSame(SgrState::COLOR_RED, $parser->state()->foreground);

        $parser->reset();
        $this->assertSame(SgrState::COLOR_RED, $parser->state()->foreground);
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
