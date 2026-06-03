<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * @see ToolResult
 */
final class ToolResultTest extends TestCase
{
    // =========================================================================
    // Creation Tests
    // =========================================================================

    public function testCanBeCreatedWithAllFields(): void
    {
        $toolCallId = 'call_123';
        $content = 'File contents here';
        $isError = false;
        $durationMs = 150;

        $result = new ToolResult($toolCallId, $content, $isError, $durationMs);

        $this->assertSame($toolCallId, $result->toolCallId());
        $this->assertSame($content, $result->content());
        $this->assertSame($isError, $result->isError());
        $this->assertSame($durationMs, $result->durationMs());
    }

    public function testIsErrorDefaultsToFalse(): void
    {
        $result = new ToolResult('call_1', 'Success message');

        $this->assertFalse($result->isError());
    }

    public function testDurationMsDefaultsToNull(): void
    {
        $result = new ToolResult('call_1', 'Content');

        $this->assertNull($result->durationMs());
    }

    public function testCanBeCreatedWithOnlyRequiredFields(): void
    {
        $result = new ToolResult('call_required', 'Minimal result');

        $this->assertSame('call_required', $result->toolCallId());
        $this->assertSame('Minimal result', $result->content());
        $this->assertFalse($result->isError());
        $this->assertNull($result->durationMs());
    }

    public function testCanBeCreatedWithIsErrorTrue(): void
    {
        $result = new ToolResult('call_err', 'Error message', true);

        $this->assertTrue($result->isError());
    }

    public function testCanBeCreatedWithIsErrorTrueAndDurationMs(): void
    {
        $result = new ToolResult('call_err_dur', 'Timeout error', true, 30000);

        $this->assertTrue($result->isError());
        $this->assertSame(30000, $result->durationMs());
    }

    public function testCanBeCreatedWithZeroDuration(): void
    {
        $result = new ToolResult('call_fast', 'Quick result', false, 0);

        $this->assertSame(0, $result->durationMs());
    }

    public function testCanBeCreatedWithLargeDuration(): void
    {
        $result = new ToolResult('call_slow', 'Slow operation', false, 3600000);

        $this->assertSame(3600000, $result->durationMs());
    }

    // =========================================================================
    // toArray Tests
    // =========================================================================

    public function testToArrayIncludesAllFields(): void
    {
        $result = new ToolResult('call_all', 'Full content', true, 250);
        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('tool_call_id', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('is_error', $array);
        $this->assertArrayHasKey('duration_ms', $array);
    }

    public function testToArrayReturnsCorrectValues(): void
    {
        $toolCallId = 'call_val';
        $content = 'Test content';
        $isError = true;
        $durationMs = 100;

        $result = new ToolResult($toolCallId, $content, $isError, $durationMs);
        $array = $result->toArray();

        $this->assertSame($toolCallId, $array['tool_call_id']);
        $this->assertSame($content, $array['content']);
        $this->assertSame($isError, $array['is_error']);
        $this->assertSame($durationMs, $array['duration_ms']);
    }

    public function testToArrayWithIsErrorFalse(): void
    {
        $result = new ToolResult('call_ok', 'Success', false, 50);
        $array = $result->toArray();

        $this->assertFalse($array['is_error']);
    }

    public function testToArrayWithIsErrorTrue(): void
    {
        $result = new ToolResult('call_fail', 'Failed', true, 10);
        $array = $result->toArray();

        $this->assertTrue($array['is_error']);
    }

    public function testToArrayWithNullDurationMs(): void
    {
        $result = new ToolResult('call_null_dur', 'No duration');
        $array = $result->toArray();

        $this->assertNull($array['duration_ms']);
    }

    public function testToArrayWithZeroDurationMs(): void
    {
        $result = new ToolResult('call_zero_dur', 'Instant', false, 0);
        $array = $result->toArray();

        $this->assertSame(0, $array['duration_ms']);
    }

    public function testToArrayContainsExactlyFourKeys(): void
    {
        $result = new ToolResult('call_4keys', 'Four keys', true, 999);
        $array = $result->toArray();

        $this->assertCount(4, $array);
    }

    public function testToArrayWithEmptyContent(): void
    {
        $result = new ToolResult('call_empty', '');
        $array = $result->toArray();

        $this->assertSame('', $array['content']);
    }

    public function testToArrayWithMultilineContent(): void
    {
        $content = "Line 1\nLine 2\nLine 3";
        $result = new ToolResult('call_multiline', $content);
        $array = $result->toArray();

        $this->assertSame($content, $array['content']);
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    public function testImmutability(): void
    {
        $a = new ToolResult('call_a', 'Content A');
        $b = new ToolResult('call_b', 'Content B', true, 100);

        $this->assertNotSame($a, $b);
        $this->assertSame('call_a', $a->toolCallId());
        $this->assertSame('call_b', $b->toolCallId());
    }

    public function testContentStringIsNotModifiedByCaller(): void
    {
        $originalContent = 'Original';
        $result = new ToolResult('call_1', $originalContent);

        $originalContent = 'Modified';
        $this->assertSame('Original', $result->content());
    }
}
