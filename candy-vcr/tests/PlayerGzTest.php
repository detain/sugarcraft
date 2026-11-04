<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\CompressedJsonlFormat;
use SugarCraft\Vcr\Player;

/**
 * mi6b (lib-reviews-1102): Player::open() detected formats by sniffing raw
 * first lines — for a gzipped cassette that is binary noise, so the reader
 * died inside the JSONL parser with an error that blamed the file. The
 * extension is the honest tell for gzip: .gz routes straight to
 * CompressedJsonlFormat, which gunzips on read.
 */
final class PlayerGzTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/cv-plyrgz-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0700);
        $this->path = $this->dir . '/session.cas.gz';

        $cassette = new Cassette(
            new CassetteHeader(
                version: CassetteHeader::CURRENT_VERSION,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 100, 'rows' => 30]),
                new Event(t: 1.0, kind: EventKind::Input, payload: ['b' => 'ls']),
                new Event(t: 2.0, kind: EventKind::Quit, payload: []),
            ],
        );
        (new CompressedJsonlFormat())->write($cassette, $this->path);
    }

    protected function tearDown(): void
    {
        @\unlink($this->path);
        @\rmdir($this->dir);
    }

    public function testOpenRoutesGzThroughTheCompressedReader(): void
    {
        // Positive control for the whole suite: the fixture really is gzip.
        $this->assertSame("\x1f\x8b", \substr((string) \file_get_contents($this->path), 0, 2));

        $player = Player::open($this->path);

        $events = $player->cassette->events;
        $this->assertCount(3, $events);
        $this->assertSame(EventKind::Resize, $events[0]->kind);
        $this->assertSame(80, $player->cassette->header->cols);
    }
}
