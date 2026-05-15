<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Cli\Command;
use SugarCraft\Vcr\Cli\DiffCommand;
use SugarCraft\Vcr\Cli\InspectCommand;
use SugarCraft\Vcr\Cli\ReplayCommand;
use SugarCraft\Vcr\Cli\StatsCommand;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

final class ApplicationTest extends TestCase
{
    public function testNoArgsPrintsUsageAndExitsNonZero(): void
    {
        [$exit, $stdout, $stderr] = $this->exec(new Application(), ['candy-vcr']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('usage:', $stdout);
        $this->assertStringContainsString('inspect', $stdout);
        $this->assertStringContainsString('replay', $stdout);
        $this->assertStringContainsString('diff', $stdout);
        $this->assertStringContainsString('stats', $stdout);
    }

    public function testHelpExitsZero(): void
    {
        foreach (['help', '-h', '--help'] as $flag) {
            [$exit, $stdout] = $this->exec(new Application(), ['candy-vcr', $flag]);
            $this->assertSame(0, $exit, "flag {$flag} should exit 0");
            $this->assertStringContainsString('usage:', $stdout);
        }
    }

    public function testUnknownSubcommandExitsTwo(): void
    {
        [$exit, , $stderr] = $this->exec(new Application(), ['candy-vcr', 'noop']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString("unknown subcommand 'noop'", $stderr);
    }

    public function testCustomCommandRouting(): void
    {
        $stub = new class implements Command {
            public int $called = 0;
            public function summary(): string { return 'stub'; }
            public function run(array $args, $stdout, $stderr): int
            {
                $this->called++;
                fwrite($stdout, 'STUB: ' . implode(',', $args));
                return 7;
            }
        };
        $app = new Application(['stub' => $stub]);
        [$exit, $stdout] = $this->exec($app, ['candy-vcr', 'stub', 'a', 'b']);
        $this->assertSame(7, $exit);
        $this->assertSame(1, $stub->called);
        $this->assertStringContainsString('STUB: a,b', $stdout);
    }

    public function testInspectDumpsCassetteEvents(): void
    {
        $path = $this->writeFixtureCassette([
            new Event(t: 0.1, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.2, kind: EventKind::Output, payload: ['b' => 'hello']),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new InspectCommand(), [], [$path]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('cassette v1', $stdout);
            $this->assertStringContainsString('80x24', $stdout);
            $this->assertStringContainsString('hello', $stdout);
            $this->assertStringContainsString('3 / 3 event(s) shown', $stdout);
        } finally {
            @unlink($path);
        }
    }

    public function testInspectFiltersBySinceUntil(): void
    {
        $path = $this->writeFixtureCassette([
            new Event(t: 0.1, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 1.5, kind: EventKind::Output, payload: ['b' => 'mid']),
            new Event(t: 5.0, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new InspectCommand(), [], [$path, '--since=1.0', '--until=2.0']);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('1 / 3 event(s) shown', $stdout);
            $this->assertStringContainsString('mid', $stdout);
        } finally {
            @unlink($path);
        }
    }

    public function testInspectMissingPathReportsUsage(): void
    {
        [$exit, , $stderr] = $this->exec(new InspectCommand(), [], []);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('usage:', $stderr);
    }

    public function testInspectMissingFileReportsError(): void
    {
        [$exit, , $stderr] = $this->exec(new InspectCommand(), [], ['/no/such/cassette.cas']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('candy-vcr inspect', $stderr);
    }

    public function testReplayStreamsOutputBytes(): void
    {
        $path = $this->writeFixtureCassette([
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'first ']),
            new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => 'second']),
            new Event(t: 0.002, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new ReplayCommand(), [], [$path, '--speed=instant']);
            $this->assertSame(0, $exit);
            $this->assertSame('first second', $stdout);
        } finally {
            @unlink($path);
        }
    }

    public function testReplayRejectsUnknownSpeed(): void
    {
        [$exit, , $stderr] = $this->exec(new ReplayCommand(), [], ['x.cas', '--speed=warp']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('--speed', $stderr);
    }

    public function testDiffIdenticalCassettesExitsZero(): void
    {
        $events = [
            new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ];
        $a = $this->writeFixtureCassette($events);
        $b = $this->writeFixtureCassette($events);
        try {
            [$exit, $stdout] = $this->exec(new DiffCommand(), [], [$a, $b]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('identical', $stdout);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffDifferentCassettesExitsOne(): void
    {
        $a = $this->writeFixtureCassette([
            new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ]);
        $b = $this->writeFixtureCassette([
            new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.5, kind: EventKind::Output, payload: ['b' => 'extra']),
            new Event(t: 0.6, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new DiffCommand(), [], [$a, $b]);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('event count: 2 != 3', $stdout);
            $this->assertStringContainsString('difference(s)', $stdout);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffWrongArityShowsUsage(): void
    {
        [$exit, , $stderr] = $this->exec(new DiffCommand(), [], ['only-one.cas']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('usage:', $stderr);
    }

    public function testStatsShowsEventTalliesThroughApp(): void
    {
        $path = $this->writeFixtureCassette([
            new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ]);
        try {
            [$exit, $stdout] = $this->exec(new Application(), ['candy-vcr', 'stats', $path]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Events: 2', $stdout);
            $this->assertStringContainsString('resize: 1', $stdout);
            $this->assertStringContainsString('quit: 1', $stdout);
        } finally {
            @unlink($path);
        }
    }

    public function testStatsMissingArgThroughAppExitsTwo(): void
    {
        [$exit, , $stderr] = $this->exec(new Application(), ['candy-vcr', 'stats']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('usage:', $stderr);
    }

    /**
     * @param Application|Command $target
     * @param list<string>        $argv     Used when target is an Application.
     * @param list<string>        $cmdArgs  Used when target is a Command.
     * @return array{0:int, 1:string, 2:string}
     */
    private function exec($target, array $argv = [], array $cmdArgs = []): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        if ($target instanceof Application) {
            $exit = $target->run($argv, $stdout, $stderr);
        } else {
            $exit = $target->run($cmdArgs, $stdout, $stderr);
        }
        rewind($stdout);
        rewind($stderr);
        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    /**
     * @param list<Event> $events
     */
    private function writeFixtureCassette(array $events): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-cli-');
        $this->assertNotFalse($path);
        $cassette = new Cassette(
            new CassetteHeader(version: 1, createdAt: '2026-05-08T12:00:00Z', cols: 80, rows: 24, runtime: 'sugarcraft/candy-vcr@dev'),
            $events,
        );
        (new JsonlFormat())->write($cassette, $path);
        return $path;
    }
}
