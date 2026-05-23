<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;

/**
 * Section I.1 — `candy-vcr render-tape --dry-run` runs Lexer + Parser +
 * Compiler and prints the compiled event stream as JSONL to stdout
 * (no Renderer / Rasterizer / Encoder, no GIF file).
 */
final class RenderTapeDryRunTest extends TestCase
{
    public function testDryRunEmitsHeaderAndEventsAsJsonl(): void
    {
        $tape = $this->writeTape("Set Theme \"Dracula\"\nType \"hi\"\nEnter\n");
        $gifPath = sys_get_temp_dir() . '/candy-vcr-dryrun-' . bin2hex(random_bytes(4)) . '.gif';

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run(
            ['candy-vcr', 'render-tape', $tape, '--dry-run', '-o', $gifPath],
            $stdout,
            $stderr,
        );

        rewind($stdout);
        $out = (string) stream_get_contents($stdout);

        $this->assertSame(0, $exit, 'dry-run should succeed: ' . $out);
        $this->assertFileDoesNotExist($gifPath, 'dry-run must NOT write the GIF even when -o is passed');

        $lines = array_values(array_filter(explode("\n", $out), static fn ($l) => $l !== ''));
        $this->assertNotEmpty($lines, 'dry-run produced no output');

        $headerLine = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($headerLine);
        $this->assertArrayHasKey('_header', $headerLine, 'first line must be the header tagged with _header');
        $header = $headerLine['_header'];
        $this->assertIsArray($header);
        $this->assertSame('Dracula', $header['theme'] ?? null);
        $this->assertSame(80, $header['cols'] ?? null);

        foreach (array_slice($lines, 1) as $line) {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($row);
            $this->assertArrayHasKey('t', $row);
            $this->assertArrayHasKey('kind', $row);
            $this->assertArrayHasKey('payload', $row);
        }

        // First few payloads should be the "hi" keystrokes.
        $event = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($event);
        $this->assertSame('input', $event['kind']);
        $this->assertIsArray($event['payload']);
        $this->assertSame('h', $event['payload']['b'] ?? null);

        @unlink($tape);
    }

    public function testDryRunFailsCleanlyForMissingTape(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run(
            ['candy-vcr', 'render-tape', '/tmp/does-not-exist-' . bin2hex(random_bytes(4)) . '.tape', '--dry-run'],
            $stdout,
            $stderr,
        );

        $this->assertSame(1, $exit);
    }

    private function writeTape(string $contents): string
    {
        $path = sys_get_temp_dir() . '/candy-vcr-dryrun-' . bin2hex(random_bytes(4)) . '.tape';
        file_put_contents($path, $contents);
        return $path;
    }
}
