<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Alert, Position, SymbolSet, Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class ToastTest extends TestCase
{
    public function testNew(): void
    {
        $t = Toast::new(50);
        $this->assertInstanceOf(Toast::class, $t);
    }

    public function testAlertTypes(): void
    {
        $this->assertSame('error',   ToastType::Error->value);
        $this->assertSame('warning', ToastType::Warning->value);
        $this->assertSame('info',    ToastType::Info->value);
        $this->assertSame('success', ToastType::Success->value);
    }

    public function testPositionOffsets(): void
    {
        // X offsets
        $this->assertSame(0,                    Position::TopLeft->xOffset(20, 80));
        $this->assertSame(30,                   Position::TopCenter->xOffset(20, 80));
        $this->assertSame(60,                   Position::TopRight->xOffset(20, 80));

        // Y offsets
        $this->assertSame(0,   Position::TopLeft->yOffset(3, 24));
        $this->assertSame(21,  Position::BottomLeft->yOffset(3, 24));
    }

    public function testToastTypeColors(): void
    {
        $this->assertSame('31', ToastType::Error->color());
        $this->assertSame('33', ToastType::Warning->color());
        $this->assertSame('34', ToastType::Info->color());
        $this->assertSame('32', ToastType::Success->color());
    }

    public function testToastTypeIcons(): void
    {
        foreach (ToastType::cases() as $type) {
            $this->assertNotEmpty($type->icon(SymbolSet::Unicode));
            $this->assertNotEmpty($type->icon(SymbolSet::Ascii));
        }
    }

    /**
     * Regression test: every NerdFont icon must be exactly one valid codepoint.
     * The pre-fix Error glyph was mojibake (U+FFFD + 佬 = 2 codepoints).
     *
     * @see https://github.com/charmbracelet/bubbleup
     */
    public function testNerdIconsAreSingleValidCodepoints(): void
    {
        foreach (ToastType::cases() as $type) {
            $icon = $type->nerdIcon();

            $this->assertNotEmpty($icon, "{$type->name} nerdIcon() must not be empty");
            $this->assertSame(
                1,
                mb_strlen($icon, 'UTF-8'),
                "{$type->name} nerdIcon() must be exactly 1 codepoint, got " . mb_strlen($icon, 'UTF-8')
            );
            $this->assertFalse(
                str_contains($icon, "\xef\xbf\xbd"),
                "{$type->name} nerdIcon() must not contain U+FFFD (replacement character)"
            );
            $this->assertTrue(
                mb_check_encoding($icon, 'UTF-8'),
                "{$type->name} nerdIcon() must be valid UTF-8"
            );
        }
    }

    public function testAddAlertReturnsNewInstance(): void
    {
        $a = Toast::new(50);
        $b = $a->alert(ToastType::Success, 'Done');

        $this->assertNotSame($a, $b);
    }

    public function testSuccessWarningError(): void
    {
        $t = Toast::new(50)
            ->success('It worked!')
            ->warning('Low memory')
            ->error('Disk full');

        $this->assertCount(3, $this->getQueue($t));
    }

    public function testDismissReturnsDismissingToast(): void
    {
        $t = Toast::new(50)->success('x');
        $d = $t->dismiss();
        $this->assertNotSame($t, $d);
    }

    public function testDismissedViewReturnsBackground(): void
    {
        $t = Toast::new(50)->success('x')->dismiss();
        $bg = "background\ncontent";
        $this->assertSame($bg, $t->View($bg));
    }

    public function testViewCompositesAlertOnBackground(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'Hello');

        $bg = \str_repeat("background line\n", 10);
        $result = $t->View($bg, 80, 10);

        $this->assertIsString($result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('background', $result);
    }

    public function testClearRemovesAllAlerts(): void
    {
        $t = Toast::new(50)->success('a')->error('b')->clear();
        $bg = "x";
        $this->assertSame($bg, $t->View($bg));
    }

    public function testFluentSetters(): void
    {
        $t = Toast::new(50)
            ->withMaxWidth(80)
            ->withMinWidth(20)
            ->withPosition(Position::TopRight)
            ->withSymbolSet(SymbolSet::Ascii)
            ->withDuration(5.0);

        $this->assertInstanceOf(Toast::class, $t);
    }

    public function testAlertExpiry(): void
    {
        $alert = new Alert(ToastType::Info, 'test', \microtime(true) - 1);
        $this->assertTrue($alert->isExpired());

        $alert2 = new Alert(ToastType::Info, 'test', \microtime(true) + 3600);
        $this->assertFalse($alert2->isExpired());
    }

    public function testAlertWithExpiry(): void
    {
        $alert = (new Alert(ToastType::Info, 'msg'))->withExpiry(10.0);
        $this->assertNotNull($alert->expiresAt);
        $this->assertFalse($alert->isExpired());
    }

    public function testPruneExpired(): void
    {
        $t = Toast::new(50)
            ->alert(ToastType::Success, 'keep')
            ->alert(ToastType::Error, 'expired', \microtime(true) - 1);

        $pruned = $t->pruneExpired();
        $this->assertCount(1, $this->getQueue($pruned));
    }

    public function testViewBottomRight(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::BottomRight)
            ->alert(ToastType::Success, 'bottom right');

        $bg = \str_repeat("line\n", 10);
        $result = $t->View($bg, 80, 10);

        $this->assertStringContainsString('bottom right', $result);
    }

    public function testWordWrapLongMessage(): void
    {
        $t = Toast::new(30)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, str_repeat('word ', 30));

        $bg = \str_repeat("line\n", 20);
        $result = $t->View($bg, 80, 20);

        $this->assertIsString($result);
        $this->assertStringContainsString('word', $result);
    }

    // Helper to access private queue
    private function getQueue(Toast $t): array
    {
        $ref = (new \ReflectionClass($t))->getProperty('queue');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}
