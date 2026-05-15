<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\StatsCommand;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * @covers \SugarCraft\Vcr\Cli\StatsCommand
 */
final class StatsCommandTest extends TestCase
{
    public function testStatsShowsEventCounts(): void
    {
        $cassette = $this->buildCassette([
            new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'hello']),
            new Event(t: 0.2, kind: EventKind::Output, payload: ['b' => ' world']),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new StatsCommand(), [$cassette]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Events: 4', $stdout);
            $this->assertStringContainsString('output: 2', $stdout);
            $this->assertStringContainsString('resize: 1', $stdout);
            $this->assertStringContainsString('quit: 1', $stdout);
        } finally {
            @unlink($cassette);
        }
    }

    public function testStatsShowsDuration(): void
    {
        $cassette = $this->buildCassette([
            new Event(t: 1.5, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new StatsCommand(), [$cassette]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Duration: 1.500s', $stdout);
        } finally {
            @unlink($cassette);
        }
    }

    public function testStatsShowsInputMsgBreakdown(): void
    {
        $cassette = $this->buildCassette([
            new Event(t: 0.0, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg', 'type' => 'enter']]),
            new Event(t: 0.1, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg', 'type' => 'enter']]),
            new Event(t: 0.2, kind: EventKind::Input, payload: ['msg' => ['@type' => 'MouseClickMsg', 'x' => 10, 'y' => 5]]),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new StatsCommand(), [$cassette]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Input msgs: KeyMsg(2), MouseClickMsg(1)', $stdout);
        } finally {
            @unlink($cassette);
        }
    }

    public function testStatsShowsOutputByteCount(): void
    {
        $escBold = "\x1b[1mbold"; // 8 bytes: ESC + "[1m" + "bold"
        $cassette = $this->buildCassette([
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hello world']), // 11 bytes
            new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => '']),              // 0 bytes
            new Event(t: 0.2, kind: EventKind::Output, payload: ['b' => $escBold]),       // 8 bytes
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new StatsCommand(), [$cassette]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Output bytes: 19', $stdout);
            $this->assertStringContainsString('Avg output/event: 6.3 bytes', $stdout);
        } finally {
            @unlink($cassette);
        }
    }

    public function testStatsShowsRawByteInput(): void
    {
        $cassette = $this->buildCassette([
            new Event(t: 0.0, kind: EventKind::Input, payload: ['b' => 'a']),
            new Event(t: 0.1, kind: EventKind::Input, payload: ['b' => 'b']),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new StatsCommand(), [$cassette]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Input msgs: raw bytes(2)', $stdout);
        } finally {
            @unlink($cassette);
        }
    }

    public function testStatsShowsHeaderInfo(): void
    {
        $cassette = $this->buildCassette([
            new Event(t: 0.0, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new StatsCommand(), [$cassette]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('cassette v1', $stdout);
            $this->assertStringContainsString('80x24', $stdout);
            $this->assertStringContainsString('sugarcraft/candy-vcr@dev', $stdout);
        } finally {
            @unlink($cassette);
        }
    }

    public function testStatsMissingArgExitsTwo(): void
    {
        [$exit, , $stderr] = $this->exec(new StatsCommand(), []);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('usage:', $stderr);
    }

    public function testStatsMissingFileExitsOne(): void
    {
        [$exit, , $stderr] = $this->exec(new StatsCommand(), ['/no/such/cassette.cas']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('candy-vcr stats', $stderr);
    }

    public function testStatsZeroDuration(): void
    {
        $cassette = $this->buildCassette([]);
        try {
            [$exit, $stdout] = $this->exec(new StatsCommand(), [$cassette]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Duration: 0.000s', $stdout);
            $this->assertStringContainsString('Events: 0', $stdout);
        } finally {
            @unlink($cassette);
        }
    }

    /**
     * @param list<Event> $events
     * @return string Path to temporary cassette file
     */
    private function buildCassette(array $events): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-stats-');
        $this->assertNotFalse($path);
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-13T12:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            $events,
        );
        (new JsonlFormat())->write($cassette, $path);
        return $path;
    }

    /**
     * @param Command $command
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    private function exec($command, array $args): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $exit = $command->run($args, $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);
        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
