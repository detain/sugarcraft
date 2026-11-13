<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * Finding 004 end to end: a hostile cassette header must never drive the
 * terminal of the person who ran `inspect`, `stats`, or `diff` on it.
 *
 * The primary defence is the CassetteHeader trust door (refused at load:
 * named error, exit 1). The secondary defence is the per-command
 * display-safe printing — proven here by forging a header object past
 * the constructor with Reflection and showing the CLIs still emit only
 * printable bytes.
 */
final class HeaderControlBytesTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @\unlink($path);
        }
        $this->tempFiles = [];
    }

    private function forgedCassette(string $runtime, ?string $created = null): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'hdr-') . '.jsonl';
        $this->tempFiles[] = $path;
        // Raw ESC/BEL are legal single-byte UTF-8; json_encode escapes
        // them as \u001b etc., so the file carries a faithful forgery.
        $header = json_encode([
            'v' => 2,
            'created' => $created ?? '2026-11-12T00:00:00Z',
            'cols' => 80,
            'rows' => 24,
            'runtime' => $runtime,
        ], \JSON_THROW_ON_ERROR);
        $event = json_encode([
            't' => 0.5,
            'k' => EventKind::Output->value,
            'b' => 'x',
        ], \JSON_THROW_ON_ERROR);
        \file_put_contents($path, $header . "\n" . $event . "\n");

        return $path;
    }

    /** @return array{0:int,1:string,2:string} */
    private function runCli(array $argv): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    public function testInspectRefusesEscapedRuntimeWithNamedLoadError(): void
    {
        $path = $this->forgedCassette("evil\x1b[31mRED\x1b[0m-theme");

        [$exit, $out, $err] = $this->runCli(['candy-vcr', 'inspect', $path]);

        $this->assertSame(1, $exit, 'a forged header must exit 1: ' . $err);
        $this->assertStringContainsString('must not contain control characters', $err);
        $this->assertStringNotContainsString('runtime=evil', $out);
        $this->assertStringNotContainsString("\x1b[31m", $out . $err, 'no SGR may reach the host terminal');
    }

    public function testInspectRefusesOscClipboardForgeryInCreated(): void
    {
        // The FUZZ lens of the same door: {"runtime": "\u001b]52;c;…\u0007"}
        // — an OSC 52 clipboard write smuggled as a timestamp.
        $path = $this->forgedCassette('test', "2026-11-12T00:00:00Z\x1b]52;c;aGVsbG8=\x07");

        [$exit, $out, $err] = $this->runCli(['candy-vcr', 'inspect', $path]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("createdAt must not contain control characters", $err);
        $this->assertStringNotContainsString('1b5d' , \bin2hex($out . $err));
    }

    public function testStatsRefusesTheSameForgery(): void
    {
        $path = $this->forgedCassette("evil\x1b[31mRED");

        [$exit, , $err] = $this->runCli(['candy-vcr', 'stats', $path]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must not contain control characters', $err);
    }

    public function testDiffRefusesTheSameForgery(): void
    {
        $hostile = $this->forgedCassette("evil\x1b[31mRED");
        $benign = $this->forgedCassette('test');

        [$exit, , $err] = $this->runCli(['candy-vcr', 'diff', $hostile, $benign]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must not contain control characters', $err);
    }

    /**
     * Belt-and-braces: if a header object is forged PAST the constructor
     * (a future deserializer bug, a partial migration), the display code
     * still must not emit raw ESC bytes. bin2hex() is the probe the
     * finding's measurement used: 1b5b / 1b5d must be absent.
     */
    public function testDisplayCodeFiltersEvenConstructorBypassedHeaders(): void
    {
        $reflection = new \ReflectionClass(CassetteHeader::class);
        $header = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'version' => 1,
            'createdAt' => "2026-11-12T00:00:00Z\x1b]52;c;aGVsbG8=\x07",
            'cols' => 80,
            'rows' => 24,
            'runtime' => "evil\x1b[31mRED\x1b[0m",
            'timestampMode' => CassetteHeader::TIMESTAMP_MODE_ABSOLUTE,
            'env' => [],
            'typingSpeed' => null,
            'theme' => null,
            'playbackSpeed' => null,
            'fontSize' => null,
            'fontFamily' => null,
            'widthPx' => null,
            'heightPx' => null,
        ] as $prop => $value) {
            $reflection->getProperty($prop)->setValue($header, $value);
        }

        $cassette = new \SugarCraft\Vcr\Cassette(
            $header,
            [new Event(t: 0.5, kind: EventKind::Output, payload: ['b' => 'x'])],
        );

        $inspect = new \SugarCraft\Vcr\Cli\InspectCommand();
        $stdout = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $renderHeader = new \ReflectionMethod($inspect, 'renderHeader');
        $renderHeader->invoke($inspect, $stdout, $cassette);
        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        \fclose($stdout);

        $hex = \bin2hex($out);
        $this->assertStringNotContainsString('1b5b', $hex, 'inspect header print leaked CSI: ' . $out);
        $this->assertStringNotContainsString('1b5d', $hex, 'inspect header print leaked OSC: ' . $out);
        $this->assertStringContainsString('runtime=evil.', $out, 'forged runtime must be dotted, not dropped silently');

        $stats = new \SugarCraft\Vcr\Cli\StatsCommand();
        $stdout = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        (new \ReflectionMethod($stats, 'renderHeader'))->invoke($stats, $stdout, $cassette);
        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        \fclose($stdout);
        $hex = \bin2hex($out);
        $this->assertStringNotContainsString('1b5b', $hex, 'stats header print leaked CSI');
        $this->assertStringNotContainsString('1b5d', $hex, 'stats header print leaked OSC');
    }

    public function testEventDoorsRemainIndependentOfTheHeaderDoor(): void
    {
        // Sanity that a fully valid header still loads (no over-tightening
        // of the new ctor checks).
        $path = $this->forgedCassette('sugarcraft/candy-vcr@record');
        [$exit] = $this->runCli(['candy-vcr', 'inspect', $path]);
        $this->assertSame(0, $exit);
    }
}
