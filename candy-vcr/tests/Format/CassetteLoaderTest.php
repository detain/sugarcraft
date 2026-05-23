<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Format;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\CassetteLoader;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Format\RelativeFormat;
use SugarCraft\Vcr\Player;

/**
 * Section I.3 — auto-detection between `.tape` source and the four cassette
 * formats. The loader sniffs by file extension first, falling back to a
 * content sniff (first non-blank non-comment line).
 */
final class CassetteLoaderTest extends TestCase
{
    public function testLoadsTapeFileByExtension(): void
    {
        $path = $this->writeFile('.tape', "Type \"hello\"\nEnter\n");
        $cassette = (new CassetteLoader())->load($path);
        $this->assertGreaterThan(0, $cassette->eventCount());
    }

    public function testLoadsJsonlCassetteByExtension(): void
    {
        $cassette = $this->jsonlCassette();
        $path = $this->cassettePath('.cas');
        (new JsonlFormat())->write($cassette, $path);

        $loaded = (new CassetteLoader())->load($path);
        $this->assertSame($cassette->eventCount(), $loaded->eventCount());
    }

    public function testLoadsRelativeCassetteByContentSniff(): void
    {
        $cassette = $this->jsonlCassette();
        $path = $this->cassettePath('.cas');
        (new RelativeFormat())->write(
            new Cassette(
                new CassetteHeader(
                    version: 1,
                    createdAt: '2026-05-22T00:00:00Z',
                    cols: 80,
                    rows: 24,
                    runtime: 'test',
                    timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
                ),
                $cassette->events,
            ),
            $path,
        );

        $loaded = (new CassetteLoader())->load($path);
        $this->assertSame($cassette->eventCount(), $loaded->eventCount());
    }

    public function testTapeContentSniffWithCommentLeader(): void
    {
        // Tape sniff must skip leading `#` comment lines.
        $source = "# this is a comment\n#another\n\nType \"hi\"\n";
        $path = $this->writeFile('.notape', $source);
        $loader = new CassetteLoader();
        $this->assertTrue($loader->isTape($path));

        $cassette = $loader->load($path);
        $this->assertGreaterThan(0, $cassette->eventCount());
    }

    public function testJsonSniffOnUnknownExtension(): void
    {
        $cassette = $this->jsonlCassette();
        $body = (new JsonlFormat())->encode($cassette);
        $path = $this->cassettePath('.notext');
        file_put_contents($path, $body);

        $loader = new CassetteLoader();
        $this->assertFalse($loader->isTape($path));

        $loaded = $loader->load($path);
        $this->assertSame($cassette->eventCount(), $loaded->eventCount());
    }

    public function testThrowsForUndetectableFile(): void
    {
        $path = $this->writeFile('.junk', "random garbage line\nno json no directives\n");
        $this->expectException(\InvalidArgumentException::class);
        (new CassetteLoader())->load($path);
    }

    public function testThrowsForMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CassetteLoader())->load('/tmp/candy-vcr-loader-missing-' . bin2hex(random_bytes(4)));
    }

    public function testPlayerLoadAnyAcceptsTape(): void
    {
        $path = $this->writeFile('.tape', "Type \"x\"\n");
        $player = Player::loadAny($path);
        $this->assertGreaterThan(0, $player->cassette->eventCount());
    }

    public function testPlayerLoadAnyAcceptsJsonl(): void
    {
        $cassette = $this->jsonlCassette();
        $path = $this->cassettePath('.cas');
        (new JsonlFormat())->write($cassette, $path);
        $player = Player::loadAny($path);
        $this->assertSame($cassette->eventCount(), $player->cassette->eventCount());
    }

    private function jsonlCassette(): Cassette
    {
        return new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hello']),
                new Event(t: 0.1, kind: EventKind::Quit, payload: []),
            ],
        );
    }

    private function writeFile(string $ext, string $contents): string
    {
        $path = $this->cassettePath($ext);
        file_put_contents($path, $contents);
        return $path;
    }

    private function cassettePath(string $ext): string
    {
        return sys_get_temp_dir() . '/candy-vcr-loader-' . bin2hex(random_bytes(4)) . $ext;
    }
}
