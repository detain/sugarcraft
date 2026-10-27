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

    /**
     * SES-1 (≥ law): the spend figure's companion bit ships on the snapshot
     * payload the way Message::jsonSerialize ships its additive columns —
     * only when set, so a priced snapshot stays byte-identical for clients
     * that predate the key, and a lower-bound sum is never presented blind.
     */
    public function testTheSpendBoundBitShipsOnlyWhenSet(): void
    {
        self::assertArrayNotHasKey('spendUnpriced', SessionSnapshot::new('s', spentUsd: 0.25)->toArray());
        self::assertArrayNotHasKey('spendUnpriced', SessionSnapshot::new('s', spentUsd: 0.25, spendUnpriced: false)->toArray());

        $bound = SessionSnapshot::new('s', spentUsd: 0.25, spendUnpriced: true)->toArray();
        self::assertTrue($bound['spendUnpriced']);
        self::assertSame(0.25, $bound['spentUsd'], 'the figure rides unchanged; the bit qualifies it');
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

    public function testAFinishedCallNamingAnUnloadableArtifactGainsNoRefs(): void
    {
        // C-WEB-1.d moved the snapshot fold through the session store, and
        // with it the law that a ref no client can `media.fetch` is no ref
        // at all: this persisted path sits OUTSIDE the store and names no
        // bytes to adopt (real rows persist `imageBytesBase64` — Message —
        // precisely so a re-fold has something to adopt), so the ref is
        // dropped rather than emitted broken, and the row serialises
        // exactly as saved. The positive half — a finished media call
        // gaining the same refs the live event carried — is pinned at the
        // snapshot level by TranscriptProjectorMediaTest::
        // testTheSnapshotRowCarriesTheEventRefsByteIdentical.
        $row = Message::assistant('saved')->withToolResults([
            new ToolResult(name: 'GenerateImage', result: 'ok', id: 'g1', imagePath: '/media/s1/art.png'),
        ]);

        $wire = SessionSnapshot::new('s', [$row])->toArray();
        $entry = $wire['messages'][0]['toolResults'][0];

        self::assertArrayNotHasKey('media', $entry);
        self::assertArrayNotHasKey('mediaTruncated', $entry);
        // The persisted keys ride on untouched — fold-by-absence, the same
        // additive shape a media-less row has always kept.
        self::assertSame('/media/s1/art.png', $entry['imagePath']);
        self::assertSame($row->jsonSerialize(), $wire['messages'][0]);
    }
}
