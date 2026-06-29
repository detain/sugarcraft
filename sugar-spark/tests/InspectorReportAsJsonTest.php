<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use SugarCraft\Spark\Inspector;
use SugarCraft\Spark\SequenceSegment;
use SugarCraft\Spark\TextSegment;
use PHPUnit\Framework\TestCase;

final class InspectorReportAsJsonTest extends TestCase
{
    public function testPlainTextReturnsJsonArrayWithOneTextEntry(): void
    {
        $json = Inspector::reportAsJson('hello world');
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('text', $decoded[0]['type']);
        $this->assertSame('hello world', $decoded[0]['content']);
        $this->assertSame('hello world', $decoded[0]['description']);
    }

    public function testSequenceSegmentHasTypeSequence(): void
    {
        $json = Inspector::reportAsJson("\x1b[0m");
        $decoded = json_decode($json, true);
        $this->assertCount(1, $decoded);
        $this->assertSame('sequence', $decoded[0]['type']);
        $this->assertSame("\x1b[0m", $decoded[0]['content']);
        $this->assertStringContainsString('SGR reset', $decoded[0]['description']);
    }

    public function testMixedTextAndSequences(): void
    {
        $json = Inspector::reportAsJson("\x1b[1mbold\x1b[0m");
        $decoded = json_decode($json, true);
        $this->assertCount(3, $decoded);

        // Sequence: ESC[1m (bold on)
        $this->assertSame('sequence', $decoded[0]['type']);
        $this->assertSame("\x1b[1m", $decoded[0]['content']);
        $this->assertStringContainsString('bold', $decoded[0]['description']);

        // Text: "bold"
        $this->assertSame('text', $decoded[1]['type']);
        $this->assertSame('bold', $decoded[1]['content']);

        // Sequence: ESC[0m (reset)
        $this->assertSame('sequence', $decoded[2]['type']);
        $this->assertSame("\x1b[0m", $decoded[2]['content']);
        $this->assertStringContainsString('reset', $decoded[2]['description']);
    }

    public function testReturnsValidJson(): void
    {
        $json = Inspector::reportAsJson('simple');
        $this->assertNotFalse(json_decode($json, true));
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function testEmptyStringReturnsEmptyArray(): void
    {
        $json = Inspector::reportAsJson('');
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(0, $decoded);
    }

    public function testForegroundRedSequence(): void
    {
        $json = Inspector::reportAsJson("\x1b[31mred\x1b[0m");
        $decoded = json_decode($json, true);

        // First sequence: foreground red
        $this->assertSame('sequence', $decoded[0]['type']);
        $this->assertStringContainsString('foreground red', $decoded[0]['description']);

        // Text
        $this->assertSame('text', $decoded[1]['type']);
        $this->assertSame('red', $decoded[1]['content']);

        // Reset
        $this->assertSame('sequence', $decoded[2]['type']);
        $this->assertStringContainsString('reset', $decoded[2]['description']);
    }

    public function testJsonStructureMatchesContract(): void
    {
        $json = Inspector::reportAsJson('x');
        $decoded = json_decode($json, true);
        $entry = $decoded[0];

        $this->assertArrayHasKey('type', $entry);
        $this->assertArrayHasKey('content', $entry);
        $this->assertArrayHasKey('description', $entry);
        $this->assertContains($entry['type'], ['text', 'sequence']);
        $this->assertIsString($entry['content']);
        $this->assertIsString($entry['description']);
    }

    /**
     * Regression: binary / invalid-UTF-8 in OSC title must not crash reportAsJson().
     * Before the fix json_encode returned false (TypeError against : string).
     */
    public function testBinaryOscTitleDoesNotCrash(): void
    {
        // 0xff 0xfe are invalid UTF-8 continuation bytes.
        $json = Inspector::reportAsJson("\x1b]0;\xff\xfe\x07");
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('sequence', $decoded[0]['type']);
        // The invalid bytes should be substituted; the label still describes the OSC.
        $this->assertStringContainsString('set window title', $decoded[0]['description']);
    }
}
