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
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * mi5 (lib-reviews-1102): migrate always dialled a plain JsonlFormat, so
 * handing it a cast/YAML/tape/gzip file produced a raw JSON parse error
 * that read like a corrupt cassette. The command names the file kind and
 * refuses at the usage door instead — input AND output sides (mi6c: an
 * explicit .gz target used to receive plain bytes under a gzip name).
 */
final class MigrateFormatRefusalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/cv-migrate-refuse-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach ((array) \scandir($this->dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @\unlink($this->dir . '/' . $entry);
            }
        }
        @\rmdir($this->dir);
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function refusedExtensions(): array
    {
        return [
            'cast'  => ['show.cast', 'asciinema cast'],
            'yaml'  => ['session.yaml', 'YAML'],
            'yml'   => ['session.yml', 'YAML'],
            'tape'  => ['demo.tape', 'tape script'],
            'gzip'  => ['tape.json.gz', 'gzip'],
        ];
    }

    /**
     * @return array{0:int, 1:string}
     */
    private function migrate(array $argv): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new MigrateCommand())->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        return [$exit, (string) \stream_get_contents($stdout) . (string) \stream_get_contents($stderr)];
    }

    private function writeCassette(string $path): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: CassetteHeader::CURRENT_VERSION,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );
        (new JsonlFormat())->write($cassette, $path);
    }

    /**
     * @param string $name fixture file name whose extension must be refused
     * @param string $kind upstream format label the refusal message must name
     * @dataProvider refusedExtensions
     */
    public function testForeignFormatInputIsNamedAndRefused(string $name, string $kind): void
    {
        $path = $this->dir . '/' . $name;
        \file_put_contents($path, '{"version": 1, broken as jsonl? no — wrong shape');

        [$exit, $out] = $this->migrate([$path]);

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('is a ' . $kind . ' file', $out);
        $this->assertStringContainsString('JSONL cassettes', $out);
    }

    public function testPlainCassetteStillMigrates(): void
    {
        $path = $this->dir . '/session-2026-05-07';

        $this->writeCassette($path);
        [$exit, $out] = $this->migrate([$path]);

        $this->assertSame(0, $exit, $out);
        $this->assertStringNotContainsString(' is a ', $out);
    }

    public function testGzOutputTargetIsRefusedWithoutSideEffects(): void
    {
        $input = $this->dir . '/in.cas';
        $output = $this->dir . '/out.json.gz';

        $this->writeCassette($input);
        [$exit, $out] = $this->migrate([$input, $output]);

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('output cannot be a .gz path', $out);
        $this->assertFalse(\file_exists($output), 'refusal must not create the target');
        $this->assertFalse(\file_exists($input . '.bak'), 'refusal must not touch the input');
    }

    public function testUsageAdvertisesTheJsonlOnlyLaw(): void
    {
        [$exit, $out] = $this->migrate([]);

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('usage:', $out);
        $this->assertStringContainsString('JSONL cassettes only', $out);
    }
}
