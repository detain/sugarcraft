<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Toast;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Toast\Level;
use SugarCraft\Dash\Components\Toast\Notification;
use SugarCraft\Dash\Components\Toast\NotificationQueue;
use SugarCraft\Dash\Components\Toast\Toast;
use SugarCraft\Toast\ToastType;

/**
 * Adapter tests for the sugar-toast migration: Level -> ToastType mapping,
 * per-level palette, the queue bridge, and Notification DTO stability.
 */
final class ToastAdapterTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Level -> ToastType mapping
    // ═══════════════════════════════════════════════════════════════

    /**
     * @return iterable<string, array{0: Level, 1: ToastType}>
     */
    public static function provideLevelTypePairs(): iterable
    {
        yield 'info'    => [Level::Info, ToastType::Info];
        yield 'warning' => [Level::Warning, ToastType::Warning];
        yield 'error'   => [Level::Error, ToastType::Error];
        yield 'success' => [Level::Success, ToastType::Success];
    }

    /**
     * @dataProvider provideLevelTypePairs
     */
    public function testTypeForLevelMapsEachLevel(Level $level, ToastType $expected): void
    {
        $this->assertSame($expected, Toast::typeForLevel($level));
        // The two enums share string values, so the mapping is value-stable.
        $this->assertSame($level->value, $expected->value);
    }

    // ═══════════════════════════════════════════════════════════════
    // fromNotification — icon + per-level palette
    // ═══════════════════════════════════════════════════════════════

    /**
     * @return iterable<string, array{0: Level, 1: string, 2: string}>
     */
    public static function provideLevelRendering(): iterable
    {
        // level => [engine icon, per-level border truecolor SGR fragment]
        yield 'info'    => [Level::Info, 'ℹ', '38;2;59;130;246'];
        yield 'warning' => [Level::Warning, '⚠', '38;2;245;158;11'];
        yield 'error'   => [Level::Error, '✖', '38;2;239;68;68'];
        yield 'success' => [Level::Success, '✔', '38;2;34;197;94'];
    }

    /**
     * @dataProvider provideLevelRendering
     */
    public function testFromNotificationMapsLevelIconAndColor(Level $level, string $icon, string $borderSgr): void
    {
        $toast = Toast::fromNotification(new Notification('payload', $level));
        $rendered = $toast->render();

        $this->assertStringContainsString('payload', $rendered);
        $this->assertStringContainsString($icon, $rendered, "expected {$level->value} icon");
        $this->assertStringContainsString($borderSgr, $rendered, "expected {$level->value} border colour");
    }

    public function testFromNotificationPreservesTitle(): void
    {
        $toast = Toast::fromNotification(Notification::warning('body text', 'Heads up'));
        $rendered = $toast->render();

        $this->assertStringContainsString('Heads up', $rendered);
        $this->assertStringContainsString('body text', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // fromQueue bridge
    // ═══════════════════════════════════════════════════════════════

    public function testFromQueueReturnsNullWhenEmpty(): void
    {
        $this->assertNull(Toast::fromQueue(NotificationQueue::new()));
    }

    public function testFromQueueRendersCurrentHead(): void
    {
        $queue = NotificationQueue::new()
            ->push(Notification::error('first fault'))
            ->push(Notification::info('later note'));

        $toast = Toast::fromQueue($queue);

        $this->assertInstanceOf(Toast::class, $toast);
        $rendered = $toast->render();
        // current() is the oldest active item (FIFO head).
        $this->assertStringContainsString('first fault', $rendered);
        $this->assertStringContainsString('✖', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Notification DTO shape (must stay byte-stable)
    // ═══════════════════════════════════════════════════════════════

    public function testNotificationDtoShapeUnchanged(): void
    {
        $n = new Notification('hello', Level::Error, 'Title');
        $this->assertSame('hello', $n->message);
        $this->assertSame(Level::Error, $n->level);
        $this->assertSame('Title', $n->title);
    }

    public function testNotificationDefaultsInfoLevelAndNullTitle(): void
    {
        $n = new Notification('only message');
        $this->assertSame(Level::Info, $n->level);
        $this->assertNull($n->title);
    }

    /**
     * @return iterable<string, array{0: callable(): Notification, 1: Level}>
     */
    public static function provideNotificationFactories(): iterable
    {
        yield 'info'    => [static fn (): Notification => Notification::info('m'), Level::Info];
        yield 'warning' => [static fn (): Notification => Notification::warning('m'), Level::Warning];
        yield 'error'   => [static fn (): Notification => Notification::error('m'), Level::Error];
        yield 'success' => [static fn (): Notification => Notification::success('m'), Level::Success];
    }

    /**
     * @dataProvider provideNotificationFactories
     *
     * @param callable(): Notification $make
     */
    public function testNotificationFactoriesSetLevel(callable $make, Level $expected): void
    {
        $n = $make();
        $this->assertSame('m', $n->message);
        $this->assertSame($expected, $n->level);
        $this->assertNull($n->title);
    }
}
