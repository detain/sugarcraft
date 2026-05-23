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

/**
 * Section I.2 — `candy-vcr inspect <cassette> --frames` walks the cassette
 * through a Renderer + Terminal and prints one line per Snapshot.
 */
final class InspectFramesTest extends TestCase
{
    public function testInspectFramesEmitsTimelineForTape(): void
    {
        $tape = $this->writeFile(
            sys_get_temp_dir() . '/candy-vcr-frames-' . bin2hex(random_bytes(4)) . '.tape',
            "Type \"hi\"\nSleep 100ms\n",
        );

        [$exit, $out] = $this->runCli(['candy-vcr', 'inspect', $tape, '--frames']);

        $this->assertSame(0, $exit, $out);

        $lines = array_values(array_filter(explode("\n", $out), static fn ($l) => $l !== ''));
        // Header (one line), then frame lines, then summary footer.
        $this->assertGreaterThan(2, count($lines));

        // Find a frame line — `time<TAB>cursor_row,cursor_col<TAB>sha1`.
        $matched = false;
        foreach ($lines as $line) {
            if (preg_match('/^\d+\.\d+\t\d+,\d+\t[0-9a-f]{40}$/', $line)) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue($matched, "expected at least one frame line. got:\n{$out}");

        // Footer should report frames/unique/deduped counts.
        $footer = end($lines);
        $this->assertIsString($footer);
        $this->assertMatchesRegularExpression('/frames: \d+\s+unique: \d+\s+deduped: \d+/', $footer);

        @unlink($tape);
    }

    public function testInspectFramesGridHashIsDeterministic(): void
    {
        $tape = $this->writeFile(
            sys_get_temp_dir() . '/candy-vcr-frames-det-' . bin2hex(random_bytes(4)) . '.tape',
            "Type \"x\"\n",
        );

        [$exit1, $out1] = $this->runCli(['candy-vcr', 'inspect', $tape, '--frames']);
        [$exit2, $out2] = $this->runCli(['candy-vcr', 'inspect', $tape, '--frames']);

        $this->assertSame(0, $exit1);
        $this->assertSame(0, $exit2);
        // Strip cassette `created=…` line off both headers — the rest must be byte-identical.
        $strip = static fn (string $s) => preg_replace('/created=\S+/', 'created=…', $s) ?? $s;
        $this->assertSame($strip($out1), $strip($out2));

        @unlink($tape);
    }

    public function testInspectFramesOnZeroEventCassettePrintsHeaderAndZeroFooter(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [],
        );

        $path = sys_get_temp_dir() . '/candy-vcr-frames-empty-' . bin2hex(random_bytes(4)) . '.cas';
        (new JsonlFormat())->write($cassette, $path);

        [$exit, $out] = $this->runCli(['candy-vcr', 'inspect', $path, '--frames']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('frames: 0', $out);
        $this->assertStringContainsString('unique: 0', $out);

        @unlink($path);
    }

    public function testInspectFramesAlsoWorksOnJsonlCassette(): void
    {
        // Build a tiny cassette with one Output event so a frame is produced.
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => "hello\r\n"]),
            ],
        );

        $path = sys_get_temp_dir() . '/candy-vcr-frames-jsonl-' . bin2hex(random_bytes(4)) . '.cas';
        (new JsonlFormat())->write($cassette, $path);

        [$exit, $out] = $this->runCli(['candy-vcr', 'inspect', $path, '--frames']);

        $this->assertSame(0, $exit, $out);
        $this->assertMatchesRegularExpression('/\d+\.\d+\t\d+,\d+\t[0-9a-f]{40}/', $out);

        @unlink($path);
    }

    /**
     * @param list<string> $argv
     * @return array{0:int,1:string}
     */
    private function runCli(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        $combined = (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr);
        return [$exit, $combined];
    }

    private function writeFile(string $path, string $contents): string
    {
        file_put_contents($path, $contents);
        return $path;
    }
}
