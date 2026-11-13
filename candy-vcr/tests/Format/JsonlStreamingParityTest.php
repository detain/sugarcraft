<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Format;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Format\RelativeFormat;

/**
 * VCR-S26 (finding 081) — read() now streams lines through LineIo while
 * decode() keeps the whole-buffer split. Both shapes MUST agree on every
 * frame-assembly decision: identical cassettes for identical bodies,
 * identical error text (including line numbering across blank lines),
 * and byte-exact files from the streamed writer.
 */
final class JsonlStreamingParityTest extends TestCase
{
    private string $dir;

    private const HEADER = '{"v":1,"created":"2026-01-01T00:00:00Z","cols":80,"rows":24,"runtime":"bash"}';

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/vcrparity-' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            $this->fail("Failed to create temp dir: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
    }

    public function testStreamedAndBufferedDecodeAgreeOnEveryShape(): void
    {
        $event = '{"t":0.5,"k":"output","b":"hello","meta":{"x":1}}';
        foreach ([
            'plain' => self::HEADER . "\n" . $event . "\n",
            'torn tail dropped' => self::HEADER . "\n" . $event . "\n" . '{"t":1.0,"k":"out',
            'blank lines counted in numbering' => self::HEADER . "\n\n\n" . $event . "\n",
            'crlf body' => self::HEADER . "\r\n" . $event . "\r\n",
            'no trailing newline, honest' => self::HEADER . "\n" . $event,
        ] as $name => $body) {
            $path = $this->dir . '/body-' . bin2hex(random_bytes(4));
            file_put_contents($path, $body);

            $buffered = (new JsonlFormat())->decode($body);
            $streamed = (new JsonlFormat())->read($path);

            $this->assertEquals($buffered, $streamed, "shapes must agree: {$name}");
        }
    }

    public function testInvalidLineAfterBlanksNamesTheSameLineBothWays(): void
    {
        $body = self::HEADER . "\n\n\nnot json\n";
        $path = $this->dir . '/bad-' . bin2hex(random_bytes(4));
        file_put_contents($path, $body);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON on line 4');
        (new JsonlFormat())->read($path);
    }

    public function testStreamedWriterBytesMatchEncode(): void
    {
        foreach ([new JsonlFormat(), new RelativeFormat()] as $format) {
            $relative = $format instanceof RelativeFormat;
            $cassette = $this->cassette($relative);
            $path = $this->dir . '/out-' . bin2hex(random_bytes(4));
            $format->write($cassette, $path);

            $this->assertSame($format->encode($cassette), (string) file_get_contents($path));
            $this->assertEquals($cassette, $format->read($path));
        }
    }

    private function cassette(bool $relativeMode = false): Cassette
    {
        return new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-01-01T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'bash',
                timestampMode: $relativeMode ? CassetteHeader::TIMESTAMP_MODE_RELATIVE : CassetteHeader::TIMESTAMP_MODE_ABSOLUTE,
            ),
            [
                new Event(t: 0.25, kind: EventKind::Output, payload: ['b' => 'hello ']),
                new Event(t: 0.5, kind: EventKind::Output, payload: ['b' => 'world', 'nested' => ['a' => ['b' => 1]]]),
            ],
        );
    }
}
