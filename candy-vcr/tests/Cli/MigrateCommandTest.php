<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\MigrateCommand;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * Tests for MigrateCommand CLI.
 * Coverage: 1.47% lines -> needs full coverage of run() method branches.
 */
final class MigrateCommandTest extends TestCase
{
    private function writeCassette(string $path, int $version = 1): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: $version,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );
        (new JsonlFormat())->write($cassette, $path);
    }

    public function testMigrateShowsUsageWhenNoInput(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $exit = (new MigrateCommand())->run([], $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);
        $out = (string) stream_get_contents($stdout);
        $err = (string) stream_get_contents($stderr);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('usage:', $err);
    }

    public function testMigrateUnknownOption(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $exit = (new MigrateCommand())->run(['--unknown-opt'], $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('unknown option', $err);
    }

    public function testMigrateTooManyArguments(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-migrate-');
        $this->writeCassette($path);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new MigrateCommand())->run([$path, 'out1.cas', 'out2.cas'], $stdout, $stderr);
            rewind($stdout);
            rewind($stderr);
            $err = (string) stream_get_contents($stderr);

            $this->assertSame(2, $exit);
            $this->assertStringContainsString('too many arguments', $err);
        } finally {
            @unlink($path);
        }
    }

    public function testMigrateUnreadableFile(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $exit = (new MigrateCommand())->run(['/no/such/path.cas'], $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cannot read', $err);
    }

    public function testMigrateCorruptFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-migrate-');
        file_put_contents($path, "not valid json\n");
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new MigrateCommand())->run([$path], $stdout, $stderr);
            rewind($stdout);
            rewind($stderr);
            $err = (string) stream_get_contents($stderr);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('invalid JSON', $err);
        } finally {
            @unlink($path);
        }
    }

    public function testMigrateAlreadyAtLatestVersion(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-migrate-');
        $this->writeCassette($path, 1);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new MigrateCommand())->run([$path], $stdout, $stderr);
            rewind($stdout);
            rewind($stderr);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(0, $exit);
            $this->assertStringContainsString('already at the latest format version', $out);
        } finally {
            @unlink($path);
        }
    }

    public function testMigrateDryRun(): void
    {
        // Create a v1 cassette - but since no migrator exists, it will say already at latest
        $path = tempnam(sys_get_temp_dir(), 'cv-migrate-');
        $this->writeCassette($path, 1);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new MigrateCommand())->run([$path, '--dry-run'], $stdout, $stderr);
            rewind($stdout);
            rewind($stderr);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(0, $exit);
            $this->assertStringContainsString('dry-run', $out);
        } finally {
            @unlink($path);
        }
    }

    public function testMigrateInfoFlag(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $exit = (new MigrateCommand())->run(['--info'], $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);
        $out = (string) stream_get_contents($stdout);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('migration system', $out);
    }

    public function testSummary(): void
    {
        $cmd = new MigrateCommand();
        $this->assertSame('Migrate a cassette to the current format version', $cmd->summary());
    }
}
