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
 * mi7/mi13 (lib-reviews-1102): `inspect --since/--until` cast their values
 * with (float), so `--since=abc` became 0.0 and showed the ENTIRE cassette
 * — a window filter that silently becomes no filter. Garbage now refuses at
 * the same rc-2 misuse door as `--fps` (`nan`/`inf` included, since
 * is_numeric rejects both spellings and is_finite catches the rest).
 *
 * The second family: snapshot paths and msg `@type` labels were printed
 * raw, so a cassette carrying escape bytes painted the inspecting
 * terminal. They ride a displaySafe() scrub — control bytes become '.'.
 */
final class InspectWindowOptionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/cv-window-' . \bin2hex(\random_bytes(4));
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
     * @return list<array{0:string}>
     */
    public static function garbageWindowValues(): array
    {
        return [
            ['abc'],
            ['-1'],
            ['nan'],
            ['inf'],
            [''],
            ['1.5x'],
        ];
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
        return [$exit, (string) \stream_get_contents($stdout) . (string) \stream_get_contents($stderr)];
    }

    private function plainTape(): string
    {
        $path = $this->dir . '/plain-' . \bin2hex(\random_bytes(4)) . '.tape';
        \file_put_contents($path, "Type \"x\"\n");
        $this->assertIsString($path);
        return $path;
    }

    /**
     * @dataProvider garbageWindowValues
     */
    public function testGarbageWindowValueRefusesWithExit2(string $garbage): void
    {
        foreach (['--since=', '--until='] as $flag) {
            [$exit, $out] = $this->runCli([
                'candy-vcr', 'inspect', $this->plainTape(), $flag . $garbage,
            ]);

            $this->assertSame(2, $exit, "{$flag}{$garbage} must refuse, got:\n{$out}");
            $this->assertStringContainsString(
                \trim($flag, '=') . ' must be a non-negative number',
                $out,
            );
        }
    }

    public function testLegitimateWindowValuesAreAccepted(): void
    {
        foreach (['--since=0', '--since=1.5', '--until=10'] as $flag) {
            [$exit, $out] = $this->runCli(['candy-vcr', 'inspect', $this->plainTape(), $flag]);

            $this->assertSame(0, $exit, "{$flag} must pass the door, got:\n{$out}");
            $this->assertStringContainsString('event(s) shown', $out);
        }
    }

    public function testSnapshotPathControlBytesAreDotted(): void
    {
        $path = $this->dir . '/shot-' . \bin2hex(\random_bytes(4)) . '.tape';
        // Bare token: the lexer reads the directive argument up to the next
        // space, so the raw escape byte rides straight into the snapshot path.
        \file_put_contents($path, "Screenshot a\x1b[31mb\n");

        [$exit, $out] = $this->runCli(['candy-vcr', 'inspect', $path]);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('screenshot: a.', $out);
        // Nothing downstream of a scrubbed listing may emit a raw ESC.
        $this->assertStringNotContainsString(\chr(27), $out);
    }

    public function testForgedMsgTypeControlBytesAreDotted(): void
    {
        // Build the cassette through the format, not the DSL: an Input
        // event whose msg payload carries a hostile @type label.
        $path = $this->dir . '/msg-' . \bin2hex(\random_bytes(4)) . '.cas';
        $cassette = new Cassette(
            new CassetteHeader(
                version: CassetteHeader::CURRENT_VERSION,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [new Event(
                t: 0.5,
                kind: EventKind::Input,
                payload: ['msg' => ['@type' => "os\x1b]0;pwned\x07"]],
            )],
        );
        (new JsonlFormat())->write($cassette, $path);

        [$exit, $out] = $this->runCli(['candy-vcr', 'inspect', $path]);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('@os.', $out);
        $this->assertStringNotContainsString(\chr(27), $out);
        $this->assertStringNotContainsString(\chr(7), $out);
    }
}
