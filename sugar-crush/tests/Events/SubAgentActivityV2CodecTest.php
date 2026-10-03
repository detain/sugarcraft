<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Events;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Live\ActivityItem;
use SugarCraft\Crush\Events\SubAgentActivity;

/**
 * The v2 `subagent` frame (step P-B1): {@see SubAgentActivity::toArray()} and
 * {@see SubAgentActivity::fromArray()} are the one codec the fork frame, the
 * grandchild relay and the server events share. A v1 frame still decodes; v2
 * extras degrade field by field; the caps hold on the reading side too.
 */
final class SubAgentActivityV2CodecTest extends TestCase
{
    public function testAVersionTwoBeatRoundTripsFieldForField(): void
    {
        $beat = new SubAgentActivity(
            SubAgentActivity::OP_FINISHED,
            'subagent_7_y',
            'explore',
            '',
            12,
            'the report',
            4100,
            0.0021,
            9,
            'qwen',
            3000,
            [['id' => 'c3', 'label' => 'Grep', 'state' => 'ok', 'at' => 5]],
            parentCallId: 'tc_42',
            parentAgentId: 'subagent_1_z',
            description: 'Map the login flow',
            items: [
                ActivityItem::toolStarted('c3', 'Grep', '"LoginController" routes/'),
                ActivityItem::toolFinished('c3', 'Grep', true, 41),
                ActivityItem::thinking(),
                ActivityItem::text('The guard is attached in'),
                ActivityItem::inbox(1),
            ],
            stats: ['step' => 4, 'maxSteps' => 50, 'tools' => 7, 'tokensIn' => 3100, 'tokensOut' => 1000, 'costUsd' => 0.0021, 'startedAt' => 1727790000.12],
            outcome: SubAgentActivity::OUTCOME_FAILED,
            error: 'step cap 50 reached',
            resumeId: '0123456789abcdef',
        );

        $wire = unserialize(serialize($beat->toArray()), ['allowed_classes' => false]);
        $back = SubAgentActivity::fromArray($wire);

        $this->assertNotNull($back);
        $this->assertSame($beat->toArray(), $back->toArray());
        $this->assertSame(2, $wire['v']);
    }

    public function testAVersionOneFrameDecodesWithTheVersionTwoDefaults(): void
    {
        $v1 = ['op' => 'finished', 'id' => 'subagent_1_x', 'name' => 'coder', 'task' => '', 'seq' => 3, 'tail' => 'done', 'tokens' => 10];

        $beat = SubAgentActivity::fromArray($v1);

        $this->assertNotNull($beat);
        $this->assertSame(10, $beat->tokensUsed);
        $this->assertSame('', $beat->parentCallId);
        $this->assertNull($beat->parentAgentId);
        $this->assertSame([], $beat->items);
        $this->assertSame([], $beat->stats);
        $this->assertSame('', $beat->outcome, 'no outcome: the reader keeps the v1 reading');
        $this->assertNull($beat->error);
        $this->assertNull($beat->resumeId);
    }

    public function testOutOfShapeExtrasDegradeWithoutCostingTheBeat(): void
    {
        $frame = [
            'v' => 2, 'op' => 'progress', 'id' => 'subagent_1_x', 'name' => 'coder', 'task' => '', 'seq' => 2, 'tail' => '',
            'parentCallId' => 17,
            'parentAgentId' => '',
            'items' => [
                ['t' => 'tool_started', 'callId' => 'c1', 'tool' => 'Read', 'summary' => 'a.php'],
                ['t' => 'exploded'],
                ['t' => 'tool_finished', 'callId' => 'c1', 'tool' => 'Read', 'ok' => 'yes', 'ms' => 3],
                'not an item',
                ['t' => 'text', 'delta' => 'hi'],
            ],
            'stats' => ['step' => -1, 'tools' => 3, 'costUsd' => 'lots', 'startedAt' => 5, 'bogus' => 1],
            'outcome' => 'exploded',
            'error' => ['no'],
            'resumeId' => 7,
        ];

        $beat = SubAgentActivity::fromArray($frame);

        $this->assertNotNull($beat);
        $this->assertSame('', $beat->parentCallId);
        $this->assertNull($beat->parentAgentId);
        $this->assertSame([ActivityItem::TOOL_STARTED, ActivityItem::TEXT], array_map(static fn (ActivityItem $i): string => $i->type, $beat->items));
        $this->assertSame(['tools' => 3, 'startedAt' => 5.0], $beat->stats);
        $this->assertSame('', $beat->outcome);
        $this->assertNull($beat->error);
        $this->assertNull($beat->resumeId);
    }

    public function testTheIdentityIsAllOrNothing(): void
    {
        $valid = ['op' => 'queued', 'id' => 'queued_tc_1', 'name' => 'coder', 'task' => 't', 'seq' => 1, 'tail' => ''];

        $this->assertNotNull(SubAgentActivity::fromArray($valid), 'queued is a v2 op');
        $this->assertNull(SubAgentActivity::fromArray(['name' => ''] + $valid));
        $this->assertNull(SubAgentActivity::fromArray(['op' => 'activity'] + $valid));
        $this->assertNull(SubAgentActivity::fromArray(['seq' => '1'] + $valid));
    }

    public function testTheReaderEnforcesTheCapsTheWriterPromised(): void
    {
        $items = [];
        for ($i = 0; $i < 40; $i++) {
            $items[] = ['t' => 'tool_started', 'callId' => 'c' . $i, 'tool' => 'Read', 'summary' => ''];
        }
        $frame = [
            'op' => 'progress', 'id' => 'subagent_1_x', 'name' => 'coder', 'task' => '', 'seq' => 2, 'tail' => '',
            'items' => $items,
            'error' => str_repeat('é', 400),
            'description' => str_repeat('d', 2000),
        ];

        $beat = SubAgentActivity::fromArray($frame);

        $this->assertNotNull($beat);
        $this->assertCount(SubAgentActivity::MAX_ITEMS, $beat->items);
        $this->assertSame('c8', $beat->items[0]->callId, 'the newest items are kept');
        $this->assertLessThanOrEqual(SubAgentActivity::MAX_TEXT_BYTES, strlen((string) $beat->error));
        $this->assertSame(1, preg_match('//u', (string) $beat->error), 'a clipped field is still valid UTF-8');
        $this->assertSame(SubAgentActivity::MAX_TEXT_BYTES, strlen($beat->description));
    }

    public function testATextItemKeepsItsNewestBytesAndActivityItemsRoundTrip(): void
    {
        $text = ActivityItem::text(str_repeat('a', 600) . 'END');

        $this->assertSame(ActivityItem::MAX_TEXT_BYTES, strlen($text->delta));
        $this->assertStringEndsWith('END', $text->delta);

        foreach ([
            ActivityItem::toolStarted('c1', 'Bash', 'ls -la'),
            ActivityItem::toolFinished('c1', 'Bash', false, 12),
            ActivityItem::thinking(),
            ActivityItem::text('prose'),
            ActivityItem::inbox(2),
        ] as $item) {
            $this->assertEquals($item, ActivityItem::fromArray($item->toArray()));
        }
    }

    public function testAQueuedPlaceholderIsKeyedByItsParentCall(): void
    {
        $this->assertSame('queued_tc_7', SubAgentActivity::queuedId('tc_7'));
    }
}
