<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\System;

use SugarCraft\Dash\Components\System\LogViewer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class LogViewerTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testLogViewerImplementsSizer(): void
    {
        $viewer = LogViewer::new([]);
        $this->assertInstanceOf(Sizer::class, $viewer);
    }

    public function testLogViewerImplementsItem(): void
    {
        $viewer = LogViewer::new([]);
        $this->assertInstanceOf(Item::class, $viewer);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmptyForEmptyList(): void
    {
        $viewer = LogViewer::new([]);
        $rendered = $viewer->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Application started', 'severity' => LogViewer::Info],
        ]);
        $rendered = $viewer->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsMessage(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'User logged in', 'severity' => LogViewer::Info],
        ]);
        $rendered = $viewer->render();

        $this->assertStringContainsString('User logged in', $rendered);
    }

    public function testRenderWithSeverityPrefix(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Test message', 'severity' => LogViewer::Info],
        ]);
        $rendered = $viewer->render();

        $this->assertStringContainsString('[INF]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Severity levels
    // ═══════════════════════════════════════════════════════════════

    public function testRenderDebugSeverity(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Debug info', 'severity' => LogViewer::Debug],
        ]);
        $rendered = $viewer->render();

        $this->assertStringContainsString('[DBG]', $rendered);
        $this->assertStringContainsString('Debug info', $rendered);
    }

    public function testRenderInfoSeverity(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Info message', 'severity' => LogViewer::Info],
        ]);
        $rendered = $viewer->render();

        $this->assertStringContainsString('[INF]', $rendered);
        $this->assertStringContainsString('Info message', $rendered);
    }

    public function testRenderWarningSeverity(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Warning message', 'severity' => LogViewer::Warning],
        ]);
        $rendered = $viewer->render();

        $this->assertStringContainsString('[WRN]', $rendered);
        $this->assertStringContainsString('Warning message', $rendered);
    }

    public function testRenderErrorSeverity(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Error occurred', 'severity' => LogViewer::Error],
        ]);
        $rendered = $viewer->render();

        $this->assertStringContainsString('[ERR]', $rendered);
        $this->assertStringContainsString('Error occurred', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Timestamps
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithTimestamp(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Test', 'severity' => LogViewer::Info, 'timestamp' => '12:34:56'],
        ])->withShowTimestamps(true);
        $rendered = $viewer->render();

        $this->assertStringContainsString('[12:34:56]', $rendered);
    }

    public function testRenderWithoutTimestamp(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Test', 'severity' => LogViewer::Info, 'timestamp' => '12:34:56'],
        ])->withShowTimestamps(false);
        $rendered = $viewer->render();

        $this->assertStringNotContainsString('[12:34:56]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // fromMessages factory
    // ═══════════════════════════════════════════════════════════════

    public function testFromMessagesCreatesInfoEntries(): void
    {
        $viewer = LogViewer::fromMessages(['First message', 'Second message']);
        $rendered = $viewer->render();

        $this->assertStringContainsString('First message', $rendered);
        $this->assertStringContainsString('Second message', $rendered);
    }

    public function testFromMessagesWithMultipleEntries(): void
    {
        $viewer = LogViewer::fromMessages(['Msg1', 'Msg2', 'Msg3']);
        $count = $viewer->getEntryCount();

        $this->assertSame(3, $count);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multiple entries
    // ═══════════════════════════════════════════════════════════════

    public function testRenderMultipleEntries(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'First', 'severity' => LogViewer::Info],
            ['message' => 'Second', 'severity' => LogViewer::Warning],
            ['message' => 'Third', 'severity' => LogViewer::Error],
        ]);
        $rendered = $viewer->render();

        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Second', $rendered);
        $this->assertStringContainsString('Third', $rendered);
    }

    public function testGetEntryCount(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'First', 'severity' => LogViewer::Info],
            ['message' => 'Second', 'severity' => LogViewer::Warning],
        ]);

        $this->assertSame(2, $viewer->getEntryCount());
    }

    // ═══════════════════════════════════════════════════════════════
    // Scrolling
    // ═══════════════════════════════════════════════════════════════

    public function testScrollToBottomShowsLastEntry(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'First entry', 'severity' => LogViewer::Info],
            ['message' => 'Last entry', 'severity' => LogViewer::Info],
        ])->withVisibleLines(1);
        $rendered = $viewer->render();

        $this->assertStringContainsString('Last entry', $rendered);
    }

    public function testScrollToTopShowsFirstEntry(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'First entry', 'severity' => LogViewer::Info],
            ['message' => 'Last entry', 'severity' => LogViewer::Info],
        ])->withScrollToBottom(false)->withVisibleLines(1);
        $rendered = $viewer->render();

        $this->assertStringContainsString('First entry', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Test message', 'severity' => LogViewer::Info],
        ]);
        [$w, $h] = $viewer->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithMultipleEntries(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Message 1', 'severity' => LogViewer::Info],
            ['message' => 'Message 2', 'severity' => LogViewer::Info],
            ['message' => 'Message 3', 'severity' => LogViewer::Info],
        ]);
        [$w, $h] = $viewer->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(3, $h);
    }

    public function testGetInnerSizeRespectsVisibleLines(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Message 1', 'severity' => LogViewer::Info],
            ['message' => 'Message 2', 'severity' => LogViewer::Info],
            ['message' => 'Message 3', 'severity' => LogViewer::Info],
        ])->withVisibleLines(2);
        [$w, $h] = $viewer->getInnerSize();

        $this->assertSame(2, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithEntriesReturnsNewInstance(): void
    {
        $original = LogViewer::new([['message' => 'Test', 'severity' => LogViewer::Info]]);
        $updated = $original->withEntries([
            ['message' => 'New', 'severity' => LogViewer::Warning],
        ]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithEntryAddsEntry(): void
    {
        $original = LogViewer::new([['message' => 'First', 'severity' => LogViewer::Info]]);
        $updated = $original->withEntry(['message' => 'Second', 'severity' => LogViewer::Error]);

        $this->assertSame(1, $original->getEntryCount());
        $this->assertSame(2, $updated->getEntryCount());
    }

    public function testWithShowTimestampsReturnsNewInstance(): void
    {
        $original = LogViewer::new([['message' => 'Test', 'severity' => LogViewer::Info]]);
        $updated = $original->withShowTimestamps(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowSeverityPrefixReturnsNewInstance(): void
    {
        $original = LogViewer::new([['message' => 'Test', 'severity' => LogViewer::Info]]);
        $updated = $original->withShowSeverityPrefix(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithScrollToBottomReturnsNewInstance(): void
    {
        $original = LogViewer::new([['message' => 'Test', 'severity' => LogViewer::Info]]);
        $updated = $original->withScrollToBottom(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithVisibleLinesReturnsNewInstance(): void
    {
        $original = LogViewer::new([['message' => 'Test', 'severity' => LogViewer::Info]]);
        $updated = $original->withVisibleLines(5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithVisibleLinesClampsToMinimumOne(): void
    {
        $viewer = LogViewer::new([['message' => 'Test', 'severity' => LogViewer::Info]])->withVisibleLines(0);
        $lines = $this->invokePrivate('visibleLines', $viewer);

        $this->assertSame(1, $lines);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = LogViewer::new([['message' => 'Test', 'severity' => LogViewer::Info]]);
        $resized = $original->setSize(80, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // ANSI color handling
    // ═══════════════════════════════════════════════════════════════

    public function testRenderContainsAnsiCodesForColoredEntries(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Error occurred', 'severity' => LogViewer::Error],
        ]);
        $rendered = $viewer->render();

        // Error entries should have ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $viewer = LogViewer::new([
            ['message' => 'Error occurred', 'severity' => LogViewer::Error],
        ]);
        $rendered = $viewer->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helper methods
    // ═══════════════════════════════════════════════════════════════

    /**
     * Invoke a private property for testing.
     */
    private function invokePrivate(string $propName, LogViewer $viewer): mixed
    {
        $reflection = new \ReflectionClass($viewer);
        $prop = $reflection->getProperty($propName);
        $prop->setAccessible(true);
        return $prop->getValue($viewer);
    }
}
