<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Console;
use SugarCraft\Dash\Grid\ConsoleEntry;
use SugarCraft\Dash\Grid\ConsoleStream;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testConsoleImplementsSizer(): void
    {
        $console = Console::new();
        $this->assertInstanceOf(Sizer::class, $console);
    }

    public function testConsoleImplementsItem(): void
    {
        $console = Console::new();
        $this->assertInstanceOf(Item::class, $console);
    }

    // ═══════════════════════════════════════════════════════════════
    // ConsoleStream enum
    // ═══════════════════════════════════════════════════════════════

    public function testConsoleStreamDefaultColor(): void
    {
        $this->assertSame('#F9FAFB', strtolower(ConsoleStream::Stdout->defaultColor()->toHex()));
        $this->assertSame('#F38BA8', strtolower(ConsoleStream::Stderr->defaultColor()->toHex()));
        $this->assertSame('#89B4FA', strtolower(ConsoleStream::Info->defaultColor()->toHex()));
        $this->assertSame('#A6E3A1', strtolower(ConsoleStream::Success->defaultColor()->toHex()));
        $this->assertSame('#F9E2AF', strtolower(ConsoleStream::Warning->defaultColor()->toHex()));
        $this->assertSame('#F38BA8', strtolower(ConsoleStream::Error->defaultColor()->toHex()));
        $this->assertSame('#6C7086', strtolower(ConsoleStream::Debug->defaultColor()->toHex()));
        $this->assertSame('#CDD6F4', strtolower(ConsoleStream::Raw->defaultColor()->toHex()));
    }

    public function testConsoleStreamPrefix(): void
    {
        $this->assertSame('', ConsoleStream::Stdout->prefix());
        $this->assertSame('', ConsoleStream::Stderr->prefix());
        $this->assertSame('[INFO]', ConsoleStream::Info->prefix());
        $this->assertSame('[OK]', ConsoleStream::Success->prefix());
        $this->assertSame('[WARN]', ConsoleStream::Warning->prefix());
        $this->assertSame('[ERROR]', ConsoleStream::Error->prefix());
        $this->assertSame('[DEBUG]', ConsoleStream::Debug->prefix());
        $this->assertSame('', ConsoleStream::Raw->prefix());
    }

    public function testConsoleStreamIsError(): void
    {
        $this->assertFalse(ConsoleStream::Stdout->isError());
        $this->assertTrue(ConsoleStream::Stderr->isError());
        $this->assertFalse(ConsoleStream::Info->isError());
        $this->assertFalse(ConsoleStream::Success->isError());
        $this->assertTrue(ConsoleStream::Warning->isError());
        $this->assertTrue(ConsoleStream::Error->isError());
        $this->assertFalse(ConsoleStream::Debug->isError());
        $this->assertFalse(ConsoleStream::Raw->isError());
    }

    // ═══════════════════════════════════════════════════════════════
    // ConsoleEntry creation
    // ═══════════════════════════════════════════════════════════════

    public function testConsoleEntryCreation(): void
    {
        $entry = new ConsoleEntry('Test message', ConsoleStream::Info, Color::hex('#FF0000'));

        $this->assertSame('Test message', $entry->message);
        $this->assertSame(ConsoleStream::Info, $entry->stream);
        $this->assertSame('#FF0000', strtolower($entry->color?->toHex() ?? ''));
    }

    public function testConsoleEntryCreateFactory(): void
    {
        $entry = ConsoleEntry::create('Test', ConsoleStream::Error);

        $this->assertSame('Test', $entry->message);
        $this->assertSame(ConsoleStream::Error, $entry->stream);
    }

    public function testConsoleEntryInfoShortcut(): void
    {
        $entry = ConsoleEntry::info('Info message');

        $this->assertSame('Info message', $entry->message);
        $this->assertSame(ConsoleStream::Info, $entry->stream);
    }

    public function testConsoleEntrySuccessShortcut(): void
    {
        $entry = ConsoleEntry::success('Success message');

        $this->assertSame('Success message', $entry->message);
        $this->assertSame(ConsoleStream::Success, $entry->stream);
    }

    public function testConsoleEntryWarningShortcut(): void
    {
        $entry = ConsoleEntry::warning('Warning message');

        $this->assertSame('Warning message', $entry->message);
        $this->assertSame(ConsoleStream::Warning, $entry->stream);
    }

    public function testConsoleEntryErrorShortcut(): void
    {
        $entry = ConsoleEntry::error('Error message');

        $this->assertSame('Error message', $entry->message);
        $this->assertSame(ConsoleStream::Error, $entry->stream);
    }

    public function testConsoleEntryDebugShortcut(): void
    {
        $entry = ConsoleEntry::debug('Debug message');

        $this->assertSame('Debug message', $entry->message);
        $this->assertSame(ConsoleStream::Debug, $entry->stream);
    }

    public function testConsoleEntryRawShortcut(): void
    {
        $entry = ConsoleEntry::raw('Raw message');

        $this->assertSame('Raw message', $entry->message);
        $this->assertSame(ConsoleStream::Raw, $entry->stream);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyConsoleReturnsEmpty(): void
    {
        $console = Console::new();
        $this->assertSame('', $console->render());
    }

    public function testRenderWithEntryReturnsNonEmpty(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info('Test message'))
            ->setSize(80, 10);
        $rendered = $console->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsMessage(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info('Hello World'))
            ->setSize(80, 10);
        $rendered = $console->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderContainsPrefix(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info('Test'))
            ->setSize(80, 10);
        $rendered = $console->render();

        $this->assertStringContainsString('[INFO]', $rendered);
    }

    public function testRenderAddsAnsiColorCodes(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info('Test'))
            ->setSize(80, 10);
        $rendered = $console->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Timestamp display
    // ═══════════════════════════════════════════════════════════════

    public function testTimestampsOffByDefault(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info('Test'))
            ->setSize(80, 10);
        $rendered = $console->render();

        // No timestamp spaces should be visible
        $this->assertStringNotContainsString('  ', $rendered);
    }

    public function testShowTimestamps(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info('Test'))
            ->withTimestamps(true)
            ->setSize(80, 10);
        $rendered = $console->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Prefix display
    // ═══════════════════════════════════════════════════════════════

    public function testShowPrefixDefaultTrue(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info('Test'))
            ->setSize(80, 10);
        $rendered = $console->render();

        $this->assertStringContainsString('[INFO]', $rendered);
    }

    public function testHidePrefix(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info('Test'))
            ->withShowPrefix(false)
            ->setSize(80, 10);
        $rendered = $console->render();

        $this->assertStringNotContainsString('[INFO]', $rendered);
        $this->assertStringContainsString('Test', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Filtering by stream level
    // ═══════════════════════════════════════════════════════════════

    public function testMinStreamFilter(): void
    {
        $console = Console::new()
            ->withEntries([
                ConsoleEntry::debug('Debug msg'),
                ConsoleEntry::info('Info msg'),
                ConsoleEntry::warning('Warn msg'),
                ConsoleEntry::error('Error msg'),
            ])
            ->withMinStream(ConsoleStream::Warning)
            ->setSize(80, 10);

        $rendered = $console->render();

        $this->assertStringContainsString('Warn msg', $rendered);
        $this->assertStringContainsString('Error msg', $rendered);
        $this->assertStringNotContainsString('Debug msg', $rendered);
        $this->assertStringNotContainsString('Info msg', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsSizer(): void
    {
        $console = Console::new();
        $result = $console->setSize(80, 20);

        $this->assertInstanceOf(Sizer::class, $result);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $console = Console::new();
        $resized = $console->setSize(80, 20);

        $this->assertNotSame($console, $resized);
    }

    public function testGetInnerSizeWithEntries(): void
    {
        $console = Console::new()
            ->withEntries([
                ConsoleEntry::info('Line 1'),
                ConsoleEntry::info('Line 2'),
                ConsoleEntry::info('Line 3'),
            ]);

        [$w, $h] = $console->getInnerSize();

        $this->assertSame(80, $w);
        $this->assertSame(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Word wrap
    // ═══════════════════════════════════════════════════════════════

    public function testWordWrapEnabled(): void
    {
        $longMessage = str_repeat('word ', 20);
        $console = Console::new()
            ->withEntry(ConsoleEntry::info($longMessage))
            ->withWordWrap(true)
            ->setSize(30, 20);
        $rendered = $console->render();

        // Should have newlines
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testWordWrapDisabled(): void
    {
        $longMessage = str_repeat('word ', 20);
        $console = Console::new()
            ->withEntry(ConsoleEntry::info($longMessage))
            ->withWordWrap(false)
            ->setSize(30, 20);
        $rendered = $console->render();

        // Without wrap, content should be truncated
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithEntriesReturnsNewInstance(): void
    {
        $console = Console::new();
        $updated = $console->withEntries([ConsoleEntry::info('Test')]);

        $this->assertNotSame($console, $updated);
    }

    public function testWithEntryReturnsNewInstance(): void
    {
        $console = Console::new();
        $updated = $console->withEntry(ConsoleEntry::info('Test'));

        $this->assertNotSame($console, $updated);
    }

    public function testWithMaxEntriesReturnsNewInstance(): void
    {
        $console = Console::new()
            ->withEntries([
                ConsoleEntry::info('A'),
                ConsoleEntry::info('B'),
            ]);
        $updated = $console->withMaxEntries(1);

        $this->assertNotSame($console, $updated);
    }

    public function testWithTimestampsReturnsNewInstance(): void
    {
        $console = Console::new();
        $updated = $console->withTimestamps(true);

        $this->assertNotSame($console, $updated);
    }

    public function testWithWordWrapReturnsNewInstance(): void
    {
        $console = Console::new();
        $updated = $console->withWordWrap(false);

        $this->assertNotSame($console, $updated);
    }

    public function testWithMinStreamReturnsNewInstance(): void
    {
        $console = Console::new();
        $updated = $console->withMinStream(ConsoleStream::Warning);

        $this->assertNotSame($console, $updated);
    }

    public function testWithShowPrefixReturnsNewInstance(): void
    {
        $console = Console::new();
        $updated = $console->withShowPrefix(false);

        $this->assertNotSame($console, $updated);
    }

    public function testWithTimestampWidthReturnsNewInstance(): void
    {
        $console = Console::new();
        $updated = $console->withTimestampWidth(20);

        $this->assertNotSame($console, $updated);
    }

    public function testWithTimestampColorReturnsNewInstance(): void
    {
        $console = Console::new();
        $updated = $console->withTimestampColor(Color::hex('#FF0000'));

        $this->assertNotSame($console, $updated);
    }

    public function testWithPrefixColorReturnsNewInstance(): void
    {
        $console = Console::new();
        $updated = $console->withPrefixColor(Color::hex('#FF0000'));

        $this->assertNotSame($console, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Console::new factory
    // ═══════════════════════════════════════════════════════════════

    public function testConsoleNewFactory(): void
    {
        $console = Console::new();

        $this->assertSame('', $console->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithZeroWidthReturnsEmpty(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info('Test'))
            ->setSize(0, 10);

        $this->assertSame('', $console->render());
    }

    public function testRenderWithZeroHeightReturnsEmpty(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info('Test'))
            ->setSize(80, 0);

        $this->assertSame('', $console->render());
    }

    public function testEmptyMessageRender(): void
    {
        $console = Console::new()
            ->withEntry(ConsoleEntry::info(''))
            ->setSize(80, 10);
        $rendered = $console->render();

        // Should still render with prefix
        $this->assertNotSame('', $rendered);
    }

    public function testAllStreamsRender(): void
    {
        $console = Console::new()
            ->withEntries([
                ConsoleEntry::debug('Debug'),
                ConsoleEntry::info('Info'),
                ConsoleEntry::success('Success'),
                ConsoleEntry::warning('Warning'),
                ConsoleEntry::error('Error'),
            ])
            ->withShowPrefix(true)
            ->setSize(80, 10);
        $rendered = $console->render();

        $this->assertStringContainsString('Debug', $rendered);
        $this->assertStringContainsString('Info', $rendered);
        $this->assertStringContainsString('Success', $rendered);
        $this->assertStringContainsString('Warning', $rendered);
        $this->assertStringContainsString('Error', $rendered);
    }
}
