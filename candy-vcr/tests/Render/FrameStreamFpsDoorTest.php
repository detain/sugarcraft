<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\FrameStream;
use SugarCraft\Vt\Terminal;

/**
 * F2 (round 90): FrameStream::getIterator() divides 1.0/$fps on the shared
 * clock — zero crashed mid-generator and negative fps spun the emit loop
 * forever. The constructor is now the parse boundary: internals only ever
 * see a positive finite float.
 */
final class FrameStreamFpsDoorTest extends TestCase
{
    /** @return array<array{float}> */
    public static function forbiddenCadences(): array
    {
        return [[0.0], [-0.001], [-30.0], [NAN], [INF], [-INF]];
    }

    /** @dataProvider forbiddenCadences */
    public function testConstructorRefusesNonPositiveNonFiniteFps(float $fps): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');
        new FrameStream($this->player(), Terminal::new(80, 24), $fps);
    }

    public function testConstructorStillAcceptsTheFullPositiveRange(): void
    {
        // Empty-event cassette: getIterator() returns before driving any
        // playback, so this exercises the accepted door without a session.
        $empty = new Player(new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-09-30T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [],
        ));

        $this->assertSame(0, iterator_count(new FrameStream($empty, Terminal::new(80, 24), 0.001)));
        $this->assertSame(0, iterator_count(new FrameStream($empty, Terminal::new(80, 24), 240.0)));
    }

    private function player(): Player
    {
        return new Player(new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-09-30T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        ));
    }
}
