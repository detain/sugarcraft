<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Log;
use SugarCraft\Dash\Grid\LogLevel;
use SugarCraft\Dash\Grid\LogEntry;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testLogImplementsSizer(): void
    {
        $log = Log::new();
        $this->assertInstanceOf(Sizer::class, $log);
    }

    public function testLogImplementsItem(): void
    {
        $log = Log::new();
        $this->assertInstanceOf(Item::class, $log);
    }

    // ═══════════════════════════════════════════════════════════════
    // LogLevel enum
    // ═══════════════════════════════════════════════════════════════

    public function testLogLevelDefaultColor(): void
    {
        $this->assertSame(strtolower('#6C7086'), strtolower(LogLevel::Debug->defaultColor()->toHex()));
        $this->assertSame(strtolower('#89B4FA'), strtolower(LogLevel::Info->defaultColor()->toHex()));
        $this->assertSame(strtolower('#F9E2AF'), strtolower(LogLevel::Warn->defaultColor()->toHex()));
        $this->assertSame(strtolower('#F38BA8'), strtolower(LogLevel::Error->defaultColor()->toHex()));
        $this->assertSame(strtolower('#EBA0AC'), strtolower(LogLevel::Fatal->defaultColor()->toHex()));
    }

    public function testLogLevelSortOrder(): void
    {
        $this->assertSame(0, LogLevel::Debug->sortOrder());
        $this->assertSame(1, LogLevel::Info->sortOrder());
        $this->assertSame(2, LogLevel::Warn->sortOrder());
        $this->assertSame(3, LogLevel::Error->sortOrder());
        $this->assertSame(4, LogLevel::Fatal->sortOrder());
    }

    public function testLogLevelSeverityOrdering(): void
    {
        // Higher sortOrder = more severe
        $this->assertGreaterThan(LogLevel::Debug->sortOrder(), LogLevel::Info->sortOrder());
        $this->assertGreaterThan(LogLevel::Info->sortOrder(), LogLevel::Warn->sortOrder());
        $this->assertGreaterThan(LogLevel::Warn->sortOrder(), LogLevel::Error->sortOrder());
        $this->assertGreaterThan(LogLevel::Error->sortOrder(), LogLevel::Fatal->sortOrder());
    }

    // ═══════════════════════════════════════════════════════════════
    // LogEntry creation
    // ═══════════════════════════════════════════════════════════════

    public function testLogEntryCreation(): void
    {
        $entry = new LogEntry('2024-01-15 10:30:00', LogLevel::Info, 'Test message');

        $this->assertSame('2024-01-15 10:30:00', $entry->timestamp);
        $this->assertSame(LogLevel::Info, $entry->level);
        $this->assertSame('Test message', $entry->message);
    }

    public function testLogEntryCreateFactory(): void
    {
        $entry = LogEntry::create('Test message', LogLevel::Error, '2024-01-15 10:30:00');

        $this->assertSame('2024-01-15 10:30:00', $entry->timestamp);
        $this->assertSame(LogLevel::Error, $entry->level);
        $this->assertSame('Test message', $entry->message);
    }

    public function testLogEntryCreateWithDefaultLevel(): void
    {
        $entry = LogEntry::create('Test message');

        $this->assertSame(LogLevel::Info, $entry->level);
    }

    public function testLogEntryCreateWithAutoTimestamp(): void
    {
        $before = date('Y-m-d H:i:s');
        $entry = LogEntry::create('Test message');
        $after = date('Y-m-d H:i:s');

        $this->assertGreaterThanOrEqual($before, $entry->timestamp);
        $this->assertLessThanOrEqual($after, $entry->timestamp);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyLogReturnsEmpty(): void
    {
        $log = Log::new();
        $this->assertSame('', $log->render());
    }

    public function testRenderWithEntryReturnsNonEmpty(): void
    {
        $log = Log::new()->withEntry(
            LogEntry::create('Test message', LogLevel::Info, '2024-01-15 10:30:00')
        )->setSize(80, 10);
        $rendered = $log->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTimestamp(): void
    {
        $log = Log::new()->withEntry(
            LogEntry::create('Test message', LogLevel::Info, '2024-01-15 10:30:00')
        )->setSize(80, 10);
        $rendered = $log->render();

        $this->assertStringContainsString('2024-01-15 10:30:00', $rendered);
    }

    public function testRenderContainsLevel(): void
    {
        $log = Log::new()->withEntry(
            LogEntry::create('Test message', LogLevel::Error, '2024-01-15 10:30:00')
        )->setSize(80, 10);
        $rendered = $log->render();

        $this->assertStringContainsString('ERROR', $rendered);
    }

    public function testRenderContainsMessage(): void
    {
        $log = Log::new()->withEntry(
            LogEntry::create('Hello World', LogLevel::Info, '2024-01-15 10:30:00')
        )->setSize(80, 10);
        $rendered = $log->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderAddsAnsiColorCodes(): void
    {
        $log = Log::new()->withEntry(
            LogEntry::create('Test message', LogLevel::Error, '2024-01-15 10:30:00')
        )->setSize(80, 10);
        $rendered = $log->render();

        // Should contain ANSI color codes for level coloring
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testRenderMultipleEntries(): void
    {
        $log = Log::new()->withEntries([
            LogEntry::create('First', LogLevel::Debug, '2024-01-15 10:00:00'),
            LogEntry::create('Second', LogLevel::Info, '2024-01-15 10:01:00'),
            LogEntry::create('Third', LogLevel::Warn, '2024-01-15 10:02:00'),
        ])->setSize(80, 10);
        $rendered = $log->render();

        // All entries should be present
        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Second', $rendered);
        $this->assertStringContainsString('Third', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Filtering by minimum level
    // ═══════════════════════════════════════════════════════════════

    public function testMinLevelFilterExcludesLowerLevels(): void
    {
        $log = Log::new()->withEntries([
            LogEntry::create('Debug msg', LogLevel::Debug, '2024-01-15 10:00:00'),
            LogEntry::create('Info msg', LogLevel::Info, '2024-01-15 10:01:00'),
            LogEntry::create('Warn msg', LogLevel::Warn, '2024-01-15 10:02:00'),
            LogEntry::create('Error msg', LogLevel::Error, '2024-01-15 10:03:00'),
        ])->withMinLevel(LogLevel::Warn)->setSize(80, 10);

        $rendered = $log->render();

        // Should contain Warn and Error
        $this->assertStringContainsString('Warn msg', $rendered);
        $this->assertStringContainsString('Error msg', $rendered);
        // Should NOT contain Debug and Info
        $this->assertStringNotContainsString('Debug msg', $rendered);
        $this->assertStringNotContainsString('Info msg', $rendered);
    }

    public function testMinLevelInfoShowsInfoAndAbove(): void
    {
        $log = Log::new()->withEntries([
            LogEntry::create('Debug msg', LogLevel::Debug, '2024-01-15 10:00:00'),
            LogEntry::create('Info msg', LogLevel::Info, '2024-01-15 10:01:00'),
            LogEntry::create('Warn msg', LogLevel::Warn, '2024-01-15 10:02:00'),
        ])->withMinLevel(LogLevel::Info)->setSize(80, 10);

        $rendered = $log->render();

        $this->assertStringContainsString('Info msg', $rendered);
        $this->assertStringContainsString('Warn msg', $rendered);
        $this->assertStringNotContainsString('Debug msg', $rendered);
    }

    public function testMinLevelNullShowsAll(): void
    {
        $log = Log::new()->withEntries([
            LogEntry::create('Debug msg', LogLevel::Debug, '2024-01-15 10:00:00'),
            LogEntry::create('Info msg', LogLevel::Info, '2024-01-15 10:01:00'),
        ])->withMinLevel(null)->setSize(80, 10);

        $rendered = $log->render();

        $this->assertStringContainsString('Debug msg', $rendered);
        $this->assertStringContainsString('Info msg', $rendered);
    }

    public function testMinLevelConstructorParameter(): void
    {
        $log = (new Log(
            entries: [
                LogEntry::create('Debug msg', LogLevel::Debug, '2024-01-15 10:00:00'),
                LogEntry::create('Error msg', LogLevel::Error, '2024-01-15 10:01:00'),
            ],
            minLevel: LogLevel::Error
        ))->setSize(80, 10);

        $rendered = $log->render();

        $this->assertStringContainsString('Error msg', $rendered);
        $this->assertStringNotContainsString('Debug msg', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Max entries limiting
    // ═══════════════════════════════════════════════════════════════

    public function testMaxEntriesLimitsOutput(): void
    {
        $entries = [];
        for ($i = 0; $i < 10; $i++) {
            $entries[] = LogEntry::create("Message $i", LogLevel::Info, "2024-01-15 10:$i:00");
        }

        $log = Log::new()->withEntries($entries)->withMaxEntries(3)->setSize(80, 10);

        // Should only contain 3 entries
        $this->assertLessThanOrEqual(3, substr_count($log->render(), 'Message'));
    }

    public function testMaxEntriesConstructorParameter(): void
    {
        $entries = [];
        for ($i = 0; $i < 5; $i++) {
            $entries[] = LogEntry::create("Message $i", LogLevel::Info, "2024-01-15 10:0$i:00");
        }

        $log = (new Log(entries: $entries, maxEntries: 2))->setSize(80, 10);

        $rendered = $log->render();
        $this->assertLessThanOrEqual(2, substr_count($rendered, 'Message'));
    }

    public function testMaxEntriesNullShowsAll(): void
    {
        $entries = [
            LogEntry::create('One', LogLevel::Info, '2024-01-15 10:00:00'),
            LogEntry::create('Two', LogLevel::Info, '2024-01-15 10:01:00'),
            LogEntry::create('Three', LogLevel::Info, '2024-01-15 10:02:00'),
        ];

        $log = Log::new()->withEntries($entries)->withMaxEntries(null)->setSize(80, 10);

        $rendered = $log->render();
        $this->assertStringContainsString('One', $rendered);
        $this->assertStringContainsString('Two', $rendered);
        $this->assertStringContainsString('Three', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Timestamp display
    // ═══════════════════════════════════════════════════════════════

    public function testTimestampsShownByDefault(): void
    {
        $log = Log::new()->withEntry(
            LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:30:00')
        )->setSize(80, 10);

        $this->assertStringContainsString('2024-01-15 10:30:00', $log->render());
    }

    public function testHideTimestamps(): void
    {
        $log = Log::new()->withEntry(
            LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:30:00')
        )->withTimestamps(false)->setSize(80, 10);

        $rendered = $log->render();

        $this->assertStringNotContainsString('2024-01-15 10:30:00', $rendered);
        $this->assertStringContainsString('Test', $rendered);
    }

    public function testTimestampsConstructorParameter(): void
    {
        $log = (new Log(
            entries: [LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:30:00')],
            showTimestamps: false
        ))->setSize(80, 10);

        $this->assertStringNotContainsString('2024-01-15 10:30:00', $log->render());
    }

    public function testTimestampColorAddsAnsiCodes(): void
    {
        $log = Log::new()
            ->withEntry(LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:30:00'))
            ->withTimestampColor(Color::ansi(13))
            ->setSize(80, 10);

        $rendered = $log->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testCustomTimestampWidth(): void
    {
        $log = Log::new()
            ->withEntry(LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:30:00'))
            ->withTimestampWidth(25)
            ->setSize(80, 10);

        $rendered = $log->render();

        // Should still contain the timestamp
        $this->assertStringContainsString('2024-01-15 10:30:00', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Word wrap behavior
    // ═══════════════════════════════════════════════════════════════

    public function testWordWrapEnabledByDefault(): void
    {
        $longMessage = str_repeat('a ', 50); // 100 chars with spaces
        $log = Log::new()
            ->withEntry(LogEntry::create($longMessage, LogLevel::Info, '2024-01-15 10:30:00'))
            ->setSize(30, 10);

        $rendered = $log->render();

        // With word wrap, should have newlines
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testWordWrapDisabled(): void
    {
        $longMessage = str_repeat('a ', 50);
        $log = Log::new()
            ->withEntry(LogEntry::create($longMessage, LogLevel::Info, '2024-01-15 10:30:00'))
            ->withWordWrap(false)
            ->setSize(30, 10);

        $rendered = $log->render();

        // Without word wrap, should NOT have newlines in message
        $lines = explode("\n", $rendered);
        // The message line should be truncated, not wrapped
        $this->assertLessThanOrEqual(2, count($lines));
    }

    public function testShortMessageNotWrapped(): void
    {
        $shortMessage = 'Short message';
        $log = Log::new()
            ->withEntry(LogEntry::create($shortMessage, LogLevel::Info, '2024-01-15 10:30:00'))
            ->setSize(80, 10);

        $rendered = $log->render();

        // Short message should appear on single line
        $this->assertStringNotContainsString("\n", $rendered);
        $this->assertStringContainsString('Short message', $rendered);
    }

    public function testWordWrapRespectsWidth(): void
    {
        $message = 'Hello world this is a test';
        $log = Log::new()
            ->withEntry(LogEntry::create($message, LogLevel::Info, '2024-01-15 10:30:00'))
            ->withWordWrap(true)
            ->setSize(40, 10);

        $rendered = $log->render();
        $lines = explode("\n", $rendered);

        // Each line should be <= reasonable length (content width is less than setSize width due to prefix)
        foreach ($lines as $line) {
            // Strip ANSI codes for accurate length check
            $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            $this->assertLessThanOrEqual(60, mb_strlen($stripped, 'UTF-8'));
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Entry sorting (newest first)
    // ═══════════════════════════════════════════════════════════════

    public function testEntriesSortedNewestFirst(): void
    {
        $log = Log::new()->withEntries([
            LogEntry::create('Older', LogLevel::Info, '2024-01-15 10:00:00'),
            LogEntry::create('Newer', LogLevel::Info, '2024-01-15 12:00:00'),
            LogEntry::create('Middle', LogLevel::Info, '2024-01-15 11:00:00'),
        ])->setSize(80, 10);

        $rendered = $log->render();
        $newestPos = strpos($rendered, 'Newer');
        $middlePos = strpos($rendered, 'Middle');
        $olderPos = strpos($rendered, 'Older');

        // Newer should appear first (lowest position)
        $this->assertNotFalse($newestPos);
        $this->assertNotFalse($middlePos);
        $this->assertNotFalse($olderPos);
        $this->assertLessThan($middlePos, $newestPos);
        $this->assertLessThan($olderPos, $middlePos);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithEntryReturnsNewInstance(): void
    {
        $original = Log::new();
        $updated = $original->withEntry(LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:00:00'));

        $this->assertNotSame($original, $updated);
        $this->assertSame('', $original->render());
        $this->assertNotSame('', $updated->setSize(80, 10)->render());
    }

    public function testWithEntriesReturnsNewInstance(): void
    {
        $original = Log::new();
        $updated = $original->withEntries([
            LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:00:00'),
        ]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMaxEntriesReturnsNewInstance(): void
    {
        $original = Log::new()->withEntries([
            LogEntry::create('A', LogLevel::Info, '2024-01-15 10:00:00'),
            LogEntry::create('B', LogLevel::Info, '2024-01-15 10:01:00'),
        ]);
        $updated = $original->withMaxEntries(1);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMinLevelReturnsNewInstance(): void
    {
        $original = Log::new()->withEntries([
            LogEntry::create('Test', LogLevel::Debug, '2024-01-15 10:00:00'),
        ]);
        $updated = $original->withMinLevel(LogLevel::Error);

        $this->assertNotSame($original, $updated);
    }

    public function testWithTimestampsReturnsNewInstance(): void
    {
        $original = Log::new()->withEntry(
            LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:00:00')
        );
        $updated = $original->withTimestamps(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithWordWrapReturnsNewInstance(): void
    {
        $original = Log::new();
        $updated = $original->withWordWrap(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithTimestampWidthReturnsNewInstance(): void
    {
        $original = Log::new();
        $updated = $original->withTimestampWidth(25);

        $this->assertNotSame($original, $updated);
    }

    public function testWithTimestampColorReturnsNewInstance(): void
    {
        $original = Log::new();
        $updated = $original->withTimestampColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Log::new();
        $resized = $original->setSize(80, 20);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeReturnsSizer(): void
    {
        $log = Log::new();
        $result = $log->setSize(80, 20);

        $this->assertInstanceOf(Sizer::class, $result);
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $log = Log::new()->withEntries([
            LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:00:00'),
            LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:01:00'),
            LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:02:00'),
        ]);

        [$w, $h] = $log->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(3, $h);
    }

    public function testGetInnerSizeWithSizeSet(): void
    {
        $log = Log::new()
            ->withEntries([LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:00:00')])
            ->setSize(60, 5);

        [$w, $h] = $log->getInnerSize();

        $this->assertSame(60, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithZeroWidthReturnsEmpty(): void
    {
        $log = Log::new()
            ->withEntry(LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:00:00'))
            ->setSize(0, 10);

        $this->assertSame('', $log->render());
    }

    public function testRenderWithZeroHeightReturnsEmpty(): void
    {
        $log = Log::new()
            ->withEntry(LogEntry::create('Test', LogLevel::Info, '2024-01-15 10:00:00'))
            ->setSize(80, 0);

        $this->assertSame('', $log->render());
    }

    public function testAllLogLevelsRender(): void
    {
        $entries = [
            LogEntry::create('Debug', LogLevel::Debug, '2024-01-15 10:00:00'),
            LogEntry::create('Info', LogLevel::Info, '2024-01-15 10:01:00'),
            LogEntry::create('Warn', LogLevel::Warn, '2024-01-15 10:02:00'),
            LogEntry::create('Error', LogLevel::Error, '2024-01-15 10:03:00'),
            LogEntry::create('Fatal', LogLevel::Fatal, '2024-01-15 10:04:00'),
        ];

        $log = Log::new()->withEntries($entries)->setSize(80, 10);
        $rendered = $log->render();

        $this->assertStringContainsString('DEBUG', $rendered);
        $this->assertStringContainsString('INFO', $rendered);
        $this->assertStringContainsString('WARN', $rendered);
        $this->assertStringContainsString('ERROR', $rendered);
        $this->assertStringContainsString('FATAL', $rendered);
    }

    public function testEmptyMessageRender(): void
    {
        $log = Log::new()->withEntry(
            LogEntry::create('', LogLevel::Info, '2024-01-15 10:00:00')
        )->setSize(80, 10);
        $rendered = $log->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSpecialCharactersInMessage(): void
    {
        $log = Log::new()->withEntry(
            LogEntry::create('Special: <>&"\'αβγδ', LogLevel::Info, '2024-01-15 10:00:00')
        )->setSize(80, 10);

        $rendered = $log->render();

        $this->assertStringContainsString('αβγδ', $rendered);
    }

    public function testLogNewFactory(): void
    {
        $log = Log::new();

        $this->assertSame('', $log->render());
        $this->assertTrue($log->getInnerSize()[1] === 0);
    }

    public function testHeightConstrainsOutput(): void
    {
        $entries = [];
        for ($i = 0; $i < 5; $i++) {
            $entries[] = LogEntry::create("Message $i", LogLevel::Info, "2024-01-15 10:0$i:00");
        }

        $log = Log::new()->withEntries($entries)->setSize(80, 2);
        $rendered = $log->render();

        // Should only show 2 entries due to height constraint
        $this->assertLessThanOrEqual(2, substr_count($rendered, 'Message'));
    }
}
