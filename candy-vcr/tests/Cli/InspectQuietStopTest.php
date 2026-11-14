<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * VCR-S70 (findings 098/104/115): `inspect` shares the well-behaved CLI
 * contract — when the downstream stdout reader closes the pipe
 * (`candy-vcr inspect x.cas | head -2`) the verb stops iterating and exits
 * 0, instead of spraying errno-32 notices after the last reader line while
 * still walking the whole cassette. The frames timeline additionally counts
 * uniqueness off the once-per-frame grid hash (a full Snapshot::equals()
 * only breaks a hash tie), so its output is pinned byte-stable here too.
 */
final class InspectQuietStopTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    public function testSocketpairEpipeEndsTheEventWalkQuietly(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('unix stream sockets only.');
        }

        $cassette = $this->writeCassette('quiet-events', 20);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        [$writer, $reader] = $pair;
        fclose($reader); // The downstream reader hangs up before the first line.

        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run(
            ['candy-vcr', 'inspect', $cassette],
            $writer,
            $stderr,
        );

        rewind($stderr);
        $this->assertSame(0, $exit, 'a broken pipe is a normal end, not an error');
        $this->assertSame('', (string) stream_get_contents($stderr), 'no errno-32 notice may reach stderr');
        fclose($writer);
    }

    public function testSocketpairEpipeEndsTheFramesWalkQuietly(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('unix stream sockets only.');
        }

        $cassette = $this->writeCassette('quiet-frames', 20);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        [$writer, $reader] = $pair;
        fclose($reader);

        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run(
            ['candy-vcr', 'inspect', $cassette, '--frames'],
            $writer,
            $stderr,
        );

        rewind($stderr);
        $this->assertSame(0, $exit);
        $this->assertSame('', (string) stream_get_contents($stderr));
        fclose($writer);
    }

    public function testFramesTallyCountsUniqueRunsOffTheGridHash(): void
    {
        // Two identical typed runs with a sleep between them: the stream
        // holds repeated frames, so unique/deduped must be strictly below
        // total while the per-frame hash column proves hash-equal runs.
        $tape = $this->writeFile(
            sys_get_temp_dir() . '/candy-vcr-tally-' . bin2hex(random_bytes(4)) . '.tape',
            "Type \"ab\"\nSleep 300ms\n",
        );

        [$exit, $out] = $this->runCli(['candy-vcr', 'inspect', $tape, '--frames', '--fps', '20']);
        $this->assertSame(0, $exit, $out);

        $frameLines = array_values(array_filter(
            explode("\n", $out),
            static fn (string $l): bool => (bool) preg_match('/^\d+\.\d+\t\d+,\d+\t[0-9a-f]{40}$/', $l),
        ));
        $this->assertGreaterThan(2, count($frameLines), 'expected a multi-frame stream');

        // Every distinct hash appears in the order frames change: the run of
        // identical trailing frames must share one hash.
        $hashes = array_map(
            static fn (string $l): string => substr($l, strrpos($l, "\t") + 1),
            $frameLines,
        );
        $this->assertGreaterThan(count(array_unique($hashes)), count($hashes), 'repeated frames must re-use a hash');

        $this->assertMatchesRegularExpression('/frames: \d+\s+unique: \d+\s+deduped: \d+/', $out);
        $this->assertMatchesRegularExpression('/frames: ' . count($hashes) . '\b/', $out, 'total tally must match the emitted frames');
    }

    /**
     * One real-pipe end-to-end pin: the writer must block on the 64 KB pipe
     * buffer, the `head -2` reader must hang up, and the verb must still
     * exit 0 with a clean stderr (bash pipefail keeps OUR exit code).
     */
    public function testTruncatedHeadPipeExitsZeroWithoutNotices(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX pipes only.');
        }

        $cassette = $this->writeCassette('head-pipe', 4000);
        $bin = dirname(__DIR__, 2) . '/bin/candy-vcr';
        $cmd = 'set -o pipefail; ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin)
            . ' inspect ' . escapeshellarg($cassette) . ' 2> ' . escapeshellarg($cassette . '.err')
            . ' | head -2 >/dev/null';

        $proc = proc_open(
            ['/bin/bash', '-c', $cmd],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        stream_get_contents($pipes[1]);
        $stderrText = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);

        $errFile = $cassette . '.err';
        $verbErr = is_file($errFile) ? (string) file_get_contents($errFile) : '';
        @unlink($errFile);

        $this->assertSame(0, $status, 'bash stderr: ' . $stderrText);
        $this->assertStringNotContainsString('Broken pipe', $verbErr);
        $this->assertStringNotContainsString('errno=32', $verbErr);
    }

    private function writeCassette(string $tag, int $events): string
    {
        $payloads = [];
        for ($i = 0; $i < $events; $i++) {
            $payloads[] = new Event(t: $i * 0.01, kind: EventKind::Output, payload: ['b' => "line-{$i}\r\n"]);
        }
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            $payloads,
        );
        $path = sys_get_temp_dir() . '/candy-vcr-' . $tag . '-' . bin2hex(random_bytes(4)) . '.cas';
        (new JsonlFormat())->write($cassette, $path);
        $this->paths[] = $path;
        return $path;
    }

    /**
     * @param list<string> $argv
     * @return array{0:int,1:string}
     */
    private function runCli(array $argv): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        return [$exit, (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr)];
    }

    private function writeFile(string $path, string $contents): string
    {
        file_put_contents($path, $contents);
        $this->paths[] = $path;
        return $path;
    }
}
