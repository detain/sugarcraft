<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\SessionSnapshot;
use SugarCraft\Code\Message;
use SugarCraft\Code\ToolResult;

/**
 * Roadmap O-2g / Appendix O §4.3 and §6.8: a snapshot is what a client takes
 * before following a session's events from its `lastSeq` on.
 */
final class SessionSnapshotTest extends TestCase
{
    public function testTheWireShapeCarriesRowsStatusQueueAndCursor(): void
    {
        $snapshot = SessionSnapshot::new('s', [Message::user('hi')], ['next'], true, 't_1', 0.25, 7);

        self::assertSame('busy', $snapshot->status());
        $wire = $snapshot->toArray();
        self::assertSame('s', $wire['sessionId']);
        self::assertSame('busy', $wire['status']);
        self::assertSame('t_1', $wire['turnId']);
        self::assertSame('hi', $wire['messages'][0]['content']);
        self::assertSame('user', $wire['messages'][0]['role']);
        self::assertSame(['next'], $wire['queued']);
        self::assertSame(0.25, $wire['spentUsd']);
        self::assertSame(7, $wire['lastSeq']);
    }

    public function testAnIdleSnapshotOmitsTheTurn(): void
    {
        $wire = SessionSnapshot::new('s')->toArray();

        self::assertSame('idle', $wire['status']);
        self::assertArrayNotHasKey('turnId', $wire);
        self::assertSame([], $wire['messages']);
    }

    public function testAMediaLessRowSerialisesExactlyAsSaved(): void
    {
        // The W7 fold is additive by absence: a row whose calls name no
        // artifact ships byte-for-byte the persisted shape, so every client
        // that read rows before media existed keeps reading them unchanged.
        $plain = Message::user('hi');
        $finished = Message::assistant('done')->withToolResults([
            new ToolResult(name: 'Read', result: 'text', id: 'c1'),
        ]);

        $wire = SessionSnapshot::new('s', [$plain, $finished])->toArray();

        self::assertSame($plain->jsonSerialize(), $wire['messages'][0]);
        self::assertSame($finished->jsonSerialize(), $wire['messages'][1]);
        self::assertArrayNotHasKey('media', $wire['messages'][1]['toolResults'][0]);
    }

    public function testAFinishedCallNamingAnArtifactGainsTheRefsOnItsRow(): void
    {
        // R16(a): reload hydration. The snapshot row of a finished media call
        // carries the ref list — path, id, size, mime — and still no bytes.
        $row = Message::assistant('saved')->withToolResults([
            new ToolResult(name: 'GenerateImage', result: 'ok', id: 'g1', imagePath: '/media/s1/art.png'),
        ]);

        $wire = SessionSnapshot::new('s', [$row])->toArray();
        $entry = $wire['messages'][0]['toolResults'][0];

        self::assertSame([[
            'artifactId' => 'art',
            'kind' => 'image',
            'path' => '/media/s1/art.png',
            'bytes' => 0,
            'mime' => 'image/png',
        ]], $entry['media']);
        self::assertArrayNotHasKey('mediaTruncated', $entry);
        // The persisted keys ride on untouched.
        self::assertSame('/media/s1/art.png', $entry['imagePath']);
    }
}
