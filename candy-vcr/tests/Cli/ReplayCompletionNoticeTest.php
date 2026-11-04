<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\ReplayCommand;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Tests\Support\Stream;
use PHPUnit\Framework\TestCase;

/**
 * A finished replay reports where it got to. Before this the command exited 0
 * in silence, so an empty cassette was indistinguishable from a full replay
 * whose program produced nothing — and neither could be told apart from a
 * failure that had simply said nothing.
 *
 * The notice lives on STDERR: on a piped stdout the byte stream is the capture
 * (see ReplayCommandTest's byte-exact stdout pins), so the verdict must not
 * touch it.
 */
#[CoversClass(ReplayCommand::class)]
final class ReplayCompletionNoticeTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '') {
            @unlink($this->path);
            $this->path = '';
        }
    }

    public function testAPlayedCassetteReportsWhereItGotTo(): void
    {
        $path = $this->cassetteAt([
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hello']),
            new Event(t: 0.25, kind: EventKind::Output, payload: ['b' => ' world']),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ]);

        [$exit, $out, $err] = $this->exec(['candy-vcr', 'replay', $path, '--speed=instant']);

        self::assertSame(0, $exit);
        self::assertSame('hello world', $out, 'stdout stays the byte-exact capture');
        self::assertStringContainsString('replay: played 3 of 3 events', $err);
        self::assertStringContainsString('11 output bytes', $err);
        self::assertStringContainsString('0.500 s of recorded time', $err);
    }

    public function testAnEmptyCassetteStillReports(): void
    {
        $path = $this->cassetteAt([]);

        [$exit, $out, $err] = $this->exec(['candy-vcr', 'replay', $path, '--speed=instant']);

        self::assertSame(0, $exit);
        self::assertSame('', $out);
        self::assertStringContainsString('replay: played 0 of 0 events', $err);
        self::assertStringContainsString('0 output bytes', $err);
    }

    /**
     * @param list<Event> $events
     */
    private function cassetteAt(array $events): string
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            $events,
        );

        $this->path = (string) tempnam(sys_get_temp_dir(), 'cv-replay-notice-');
        (new JsonlFormat())->write($cassette, $this->path);

        return $this->path;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function exec(array $argv): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $exit = (new ReplayCommand())->run(array_slice($argv, 1), $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
