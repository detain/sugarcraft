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
use SugarCraft\Vcr\Format\YamlFormat;
use Symfony\Component\Yaml\Yaml;

/**
 * VCR-S32 (finding 062): YamlFormat must honour header timestampMode in
 * BOTH directions — a relative header emits `dt` interval rows (the same
 * spelling the JSONL dt-family carries) and a `dt`-keyed YAML body under a
 * relative header decodes back to cumulative absolute timestamps. Before
 * this the mode was parsed off the header and then silently ignored: an
 * encode rewrote a relative tape as absolute-corrupt.
 */
final class YamlTimestampModeTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            @unlink($path);
        }
        $this->tmpFiles = [];
    }

    public function testRelativeHeaderEmitsDtIntervalRows(): void
    {
        $encoded = (new YamlFormat())->encode($this->relativeCassette());
        $tree = Yaml::parse($encoded);

        $this->assertSame('relative', $tree['header']['timestampMode']);

        // [0.0, 0.001, 0.450, 1.201] -> intervals [0, 0.001, 0.449, 0.751]
        $expectedDt = [0.0, 0.001, 0.449, 0.751];
        $this->assertCount(4, $tree['events']);
        foreach ($expectedDt as $i => $dt) {
            $this->assertArrayHasKey('dt', $tree['events'][$i], "event #{$i} must carry dt");
            $this->assertArrayNotHasKey('t', $tree['events'][$i], "event #{$i} must not carry t");
            $this->assertEqualsWithDelta($dt, $tree['events'][$i]['dt'], 0.0005);
        }
    }

    public function testAbsoluteHeaderStillEmitsTAndNoModeStamp(): void
    {
        $encoded = (new YamlFormat())->encode($this->absoluteCassette());
        $tree = Yaml::parse($encoded);

        $this->assertArrayNotHasKey('timestampMode', $tree['header'], 'absolute mode stays off the wire');

        $expectedT = [0.0, 0.001, 0.450, 1.201];
        foreach ($expectedT as $i => $t) {
            $this->assertArrayHasKey('t', $tree['events'][$i]);
            $this->assertArrayNotHasKey('dt', $tree['events'][$i]);
            $this->assertEqualsWithDelta($t, $tree['events'][$i]['t'], 0.0005);
        }
    }

    public function testRelativeRoundTripRestoresAbsoluteTimestamps(): void
    {
        $format = new YamlFormat();
        $loaded = $format->decode($format->encode($this->relativeCassette()));

        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $loaded->header->timestampMode);
        $this->assertSame(4, $loaded->eventCount());

        $expectedT = [0.0, 0.001, 0.450, 1.201];
        foreach ($expectedT as $i => $t) {
            $this->assertEqualsWithDelta($t, $loaded->events[$i]->t, 0.001, "event #{$i} timestamp");
        }

        $this->assertSame(EventKind::Resize, $loaded->events[0]->kind);
        $this->assertSame(80, $loaded->events[0]->payload['cols']);
        $this->assertSame("\x1b[2J\x1b[H", $loaded->events[1]->payload['b']);
        $this->assertSame(EventKind::Quit, $loaded->events[3]->kind);
    }

    public function testRelativeRowCarryingTInsteadOfDtIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("YAML event #2 missing 'dt' or 'k'");

        // Strict spelling: a `t`-keyed row under a relative header is an
        // authoring mistake, not a legacy shape to tolerate.
        $tree = [
            'header' => $this->relativeHeader()->toWireArray(),
            'events' => [
                ['dt' => 0.0, 'k' => 'resize', 'cols' => 80, 'rows' => 24],
                ['t' => 0.5, 'k' => 'output', 'b' => 'x'],
            ],
        ];
        (new YamlFormat())->decode(Yaml::dump($tree, inline: 3, indent: 4));
    }

    public function testStackedLegalDtValuesHitTheTapeCeilingBelt(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pushes the tape past the 24h ceiling');

        // Neither dt breaches the per-field door (86400s); the cumulative
        // sum does (120000s). The belt must catch the sum, not the pieces.
        $tree = [
            'header' => $this->relativeHeader()->toWireArray(),
            'events' => [
                ['dt' => 60000.0, 'k' => 'output', 'b' => 'first'],
                ['dt' => 60000.0, 'k' => 'quit'],
            ],
        ];
        (new YamlFormat())->decode(Yaml::dump($tree, inline: 3, indent: 4));
    }

    public function testStrictYamlRelativeToJsonlAbsoluteConversion(): void
    {
        // The acceptance leg: a relative YAML tape converted to JSONL must
        // land bit-for-bit on the same absolute timeline — exact equality,
        // no deltas (every value is ms-rounded by both writers).
        $yamlFormat = new YamlFormat();
        $loaded = $yamlFormat->decode($yamlFormat->encode($this->relativeCassette()));

        $jsonl = new JsonlFormat();
        $rebuilt = $jsonl->decode($jsonl->encode(new Cassette($this->absoluteHeader(), $loaded->events)));

        $this->assertSame($loaded->eventCount(), $rebuilt->eventCount());
        for ($i = 0; $i < $loaded->eventCount(); $i++) {
            $this->assertEqualsWithDelta($loaded->events[$i]->t, $rebuilt->events[$i]->t, 0.0, "event #{$i} timeline");
            $this->assertSame($loaded->events[$i]->kind, $rebuilt->events[$i]->kind);
            $this->assertSame($loaded->events[$i]->payload, $rebuilt->events[$i]->payload);
        }
    }

    public function testYamlRelativeAndJsonlRelativeShareTheDtSequence(): void
    {
        // One family, one spelling: the dt intervals a relative YAML tape
        // carries are exactly the ones RelativeFormat's JSONL writes.
        $cassette = $this->relativeCassette();

        $yamlTree = Yaml::parse((new YamlFormat())->encode($cassette));
        $jsonlLines = array_values(array_filter(
            explode("\n", rtrim((new RelativeFormat())->encode($cassette), "\n")),
            static fn (string $l): bool => $l !== '',
        ));

        $this->assertCount(5, $jsonlLines, '1 header + 4 event lines');
        $jsonlEvents = array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_slice($jsonlLines, 1),
        );

        foreach ($jsonlEvents as $i => $row) {
            $this->assertArrayHasKey('dt', $row);
            // Float-cast compare: Symfony's dumper emits the interval 0.0
            // as `0`, which reparses as int — the timeline value is what
            // must match across the two spellings, not the PHP scalar type.
            $this->assertSame((float) $yamlTree['events'][$i]['dt'], (float) $row['dt'], "event #{$i} dt spelling");
            $this->assertSame($yamlTree['events'][$i]['k'], $row['k']);
        }
    }

    public function testAbsoluteYamlStillDecodesForJsonlReaders(): void
    {
        // Cross-format absolute contract (regression): a JSONL-encoded
        // absolute tape decoded by YamlFormat keeps its timeline.
        $jsonl = new JsonlFormat();
        $contents = $jsonl->encode($this->absoluteCassette());

        $tree = [
            'header' => $this->absoluteHeader()->toWireArray(),
            'events' => array_map(
                static function (Event $event): array {
                    return ['t' => round($event->t, 3), 'k' => $event->kind->value] + $event->payload;
                },
                $jsonl->decode($contents)->events,
            ),
        ];

        $loaded = (new YamlFormat())->decode(Yaml::dump($tree, inline: 3, indent: 4));
        $expectedT = [0.0, 0.001, 0.450, 1.201];
        foreach ($expectedT as $i => $t) {
            $this->assertEqualsWithDelta($t, $loaded->events[$i]->t, 0.001);
        }
    }

    public function testFileWriteReadKeepsRelativeMode(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'candy-vcr-yaml-ts-');
        $this->tmpFiles[] = $path;

        $format = new YamlFormat();
        $format->write($this->relativeCassette(), $path);
        $loaded = $format->read($path);

        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $loaded->header->timestampMode);
        $this->assertEqualsWithDelta(1.201, $loaded->events[3]->t, 0.001);
    }

    public function testIntervalRoundingIsMillisecond(): void
    {
        $cassette = new Cassette($this->relativeHeader(), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
            new Event(t: 0.123456789, kind: EventKind::Output, payload: ['b' => 'b']),
            new Event(t: 0.223456789, kind: EventKind::Quit, payload: []),
        ]);

        $tree = Yaml::parse((new YamlFormat())->encode($cassette));
        $this->assertEqualsWithDelta(0.0, $tree['events'][0]['dt'], 0.0005);
        $this->assertEqualsWithDelta(0.123, $tree['events'][1]['dt'], 0.0005);
        $this->assertEqualsWithDelta(0.1, $tree['events'][2]['dt'], 0.0005);

        $loaded = (new YamlFormat())->decode((new YamlFormat())->encode($cassette));
        $this->assertEqualsWithDelta(0.123, $loaded->events[1]->t, 0.001);
        $this->assertEqualsWithDelta(0.223, $loaded->events[2]->t, 0.001);
    }

    /** @return list<Event> */
    private function events(): array
    {
        return [
            new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => "\x1b[2J\x1b[H"]),
            new Event(t: 0.450, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg', 'key' => 'q']]),
            new Event(t: 1.201, kind: EventKind::Quit, payload: []),
        ];
    }

    private function relativeCassette(): Cassette
    {
        return new Cassette($this->relativeHeader(), $this->events());
    }

    private function absoluteCassette(): Cassette
    {
        return new Cassette($this->absoluteHeader(), $this->events());
    }

    private function relativeHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-vcr@dev',
            timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
        );
    }

    private function absoluteHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-vcr@dev',
        );
    }
}
