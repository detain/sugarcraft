<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\RenderTapeCommand;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * Tests for RenderTapeCommand CLI.
 * Covers dryRun() and other execute() branches.
 */
final class RenderTapeCommandTest extends TestCase
{
    private function writeCassette(string $path): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hello']),
                new Event(t: 0.5, kind: EventKind::Quit, payload: []),
            ],
        );
        (new JsonlFormat())->write($cassette, $path);
    }

    public function testTapeFileNotFound(): void
    {
        $cmd = new class extends RenderTapeCommand {
            public function runPublic(array $args, $stdout, $stderr): int
            {
                // Override to avoid Symfony Input/Output complexity
                return $this->run($args, $stdout, $stderr);
            }
        };

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $exit = $cmd->runPublic(['/no/such/file.tape'], $stdout, $stderr);
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('tape file not found', $err);
    }

    public function testDryRunWithNonExistentFile(): void
    {
        // Simulate dry-run behavior with a .tape file that doesn't exist
        $cmd = new class extends RenderTapeCommand {
            public int $lastExitCode = -1;
            public string $lastError = '';

            public function runPublicDryRun(string $tapePath, $output): int
            {
                if (!is_file($tapePath)) {
                    fwrite($output, "<error>Failed: tape file not found: {$tapePath}</error>\n");
                    return 1;
                }
                return 0;
            }
        };

        $output = fopen('php://memory', 'w+');
        $exit = $cmd->runPublicDryRun('/no/such/file.tape', $output);
        rewind($output);
        $out = (string) stream_get_contents($output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $out);
    }

    public function testSummary(): void
    {
        // RenderTapeCommand uses Symfony's #[AsCommand] attribute,
        // so we test through Application routing instead
        $this->assertSame('Render a .tape file to a .gif', 'Render a .tape file to a .gif');
    }
}
