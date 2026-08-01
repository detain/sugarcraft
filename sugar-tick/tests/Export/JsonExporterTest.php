<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests\Export;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tick\Export\JsonExporter;
use SugarCraft\Tick\Heartbeat;

final class JsonExporterTest extends TestCase
{
    private JsonExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new JsonExporter();
    }

    public function testHeaders(): void
    {
        $this->assertSame(['time', 'project', 'language', 'file', 'duration', 'tags'], $this->exporter->headers());
    }

    public function testRowsEmptyList(): void
    {
        $this->assertSame([], $this->exporter->rows([]));
    }

    public function testRowsSingleHeartbeat(): void
    {
        $hb = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $rows = $this->exporter->rows([$hb]);
        $this->assertCount(1, $rows);
        $this->assertSame([1700000000, 'demo', 'php', 'a.php', 60, []], $rows[0]);
    }

    public function testRowsWithTags(): void
    {
        $hb = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60, tags: ['feature', 'refactoring']);
        $rows = $this->exporter->rows([$hb]);
        $this->assertSame([1700000000, 'demo', 'php', 'a.php', 60, ['feature', 'refactoring']], $rows[0]);
    }

    public function testEncodeProducesPrettyJson(): void
    {
        $hbs = [
            new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60, tags: ['feature']),
        ];

        $json = $this->exporter->encode($hbs);

        // JSON_PRETTY_PRINT adds formatting
        $this->assertStringContainsString("\n", $json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
    }

    public function testFormat(): void
    {
        $this->assertSame('json', $this->exporter->format());
    }

    public function testContentType(): void
    {
        $this->assertSame('application/json', $this->exporter->contentType());
    }

    public function testEncodeMultipleHeartbeats(): void
    {
        $hbs = [
            new Heartbeat(time: 1700000000, project: 'p1', language: 'php', file: 'a.php', duration: 60, tags: ['tag1']),
            new Heartbeat(time: 1700000060, project: 'p2', language: 'go', file: 'b.go', duration: 120, tags: ['tag2', 'tag3']),
        ];

        $json = $this->exporter->encode($hbs);
        $decoded = json_decode($json, true);

        $this->assertCount(2, $decoded);
        $this->assertSame('p1', $decoded[0]['project']);
        $this->assertSame('p2', $decoded[1]['project']);
    }

    public function testEncodeProducesObjects(): void
    {
        // Mirrors JsonExporter::encode() producing array of objects keyed by headers()
        $hb = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $json = $this->exporter->encode([$hb]);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('demo', $decoded[0]['project']);
        $this->assertSame('php', $decoded[0]['language']);
        $this->assertSame('a.php', $decoded[0]['file']);
        $this->assertSame(60, $decoded[0]['duration']);
    }
}
