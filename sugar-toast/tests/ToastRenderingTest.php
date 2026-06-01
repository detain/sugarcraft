<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;
use SugarCraft\Toast\{Alert, Position, SymbolSet, Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class ToastRenderingTest extends TestCase
{
    private Toast $toast;

    protected function setUp(): void
    {
        $this->toast = Toast::new(50);
    }

    public function testAlertWithExpiryPath(): void
    {
        $t = $this->toast
            ->withDuration(10.0)
            ->alert(ToastType::Info, 'test message');

        $this->assertCount(1, $this->getQueue($t));
    }

    public function testProgressToastWithExpiryPath(): void
    {
        $t = $this->toast
            ->withDuration(5.0)
            ->progressToast(ToastType::Success, 'loading...', 0.5);

        $this->assertCount(1, $this->getQueue($t));
    }

    public function testToastTypeTryFromValid(): void
    {
        $type = ToastType::tryFrom('error');
        $this->assertSame(ToastType::Error, $type);
    }

    public function testToastTypeTryFromInvalid(): void
    {
        $type = ToastType::tryFrom('not_a_type');
        $this->assertNull($type);
    }

    public function testAlertWithStringType(): void
    {
        $t = $this->toast->alert('warning', 'a message');
        $this->assertCount(1, $this->getQueue($t));
    }

    public function testAlertWithInvalidStringTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->toast->alert('invalid_type', 'message');
    }

    public function testFillViewportFromStringBackground(): void
    {
        $buf = Buffer::new(50, 10);
        $lines = ["line one", "line two", "line three"];
        $result = $this->invokeFillViewportFromString($buf, $lines);

        $this->assertInstanceOf(Buffer::class, $result);
    }

    public function testRenderAlertToBuffer(): void
    {
        $alert = new Alert(ToastType::Info, 'Hello World');
        $buf = $this->invokeRenderAlertToBuffer($alert);

        $this->assertInstanceOf(Buffer::class, $buf);
        $this->assertGreaterThan(0, $buf->width());
        $this->assertGreaterThan(0, $buf->height());
    }

    public function testNextClusterOneByteUtf8(): void
    {
        $cluster = $this->invokeNextCluster('a', 0);
        $this->assertSame('a', $cluster);
    }

    public function testNextClusterTwoByteUtf8(): void
    {
        $cluster = $this->invokeNextCluster("\xc3\xa9", 0);
        $this->assertSame("\xc3\xa9", $cluster);
    }

    public function testNextClusterThreeByteUtf8(): void
    {
        $cluster = $this->invokeNextCluster("\xe4\xb8\x80", 0);
        $this->assertSame("\xe4\xb8\x80", $cluster);
    }

    public function testNextClusterFourByteUtf8(): void
    {
        $cluster = $this->invokeNextCluster("\xf0\x9f\x98\x80", 0);
        $this->assertSame("\xf0\x9f\x98\x80", $cluster);
    }

    public function testNextClusterGraphemeExtractFallback(): void
    {
        $cluster = $this->invokeNextCluster('abc', 0);
        $this->assertSame('a', $cluster);

        $cluster = $this->invokeNextCluster('abc', 1);
        $this->assertSame('b', $cluster);

        $cluster = $this->invokeNextCluster('abc', 2);
        $this->assertSame('c', $cluster);
    }

    public function testPlaceAnsiStringAtZeroWidthCell(): void
    {
        $buf = Buffer::new(20, 3);
        $ansiString = "\x1b[1mm\x1b[0m\xcc\x80";
        $result = $this->invokePlaceAnsiStringAt($buf, 0, 0, $ansiString);

        $this->assertInstanceOf(Buffer::class, $result);
    }

    public function testPlaceAnsiStringAtWideChar(): void
    {
        $buf = Buffer::new(20, 3);
        $result = $this->invokePlaceAnsiStringAt($buf, 0, 0, "\xe4\xb8\x80\xe4\xb8\x80\xe4\xb8\x80");

        $this->assertInstanceOf(Buffer::class, $result);
    }

    public function testSgrToBufferStyleBold(): void
    {
        $style = $this->invokeSgrToBufferStyle('1');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBold());
    }

    public function testSgrToBufferStyleForeground(): void
    {
        $style = $this->invokeSgrToBufferStyle('31');
        $this->assertNotNull($style);
        $this->assertNotNull($style->fg());
    }

    public function testSgrToBufferStyleBrightForeground(): void
    {
        $style = $this->invokeSgrToBufferStyle('91');
        $this->assertNotNull($style);
        $this->assertNotNull($style->fg());
    }

    public function testSgrToBufferStyleResetReturnsNull(): void
    {
        $style = $this->invokeSgrToBufferStyle('0');
        $this->assertNull($style);
    }

    public function testSgrToBufferStyleCombined(): void
    {
        $style = $this->invokeSgrToBufferStyle('1;31');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBold());
        $this->assertNotNull($style->fg());
    }

    public function testAnsiColorToRgbStandard(): void
    {
        $rgb = $this->invokeAnsiColorToRgb(0, false);
        $this->assertSame(0x000000, $rgb);
    }

    public function testAnsiColorToRgbBright(): void
    {
        $rgb = $this->invokeAnsiColorToRgb(0, true);
        $this->assertSame(0x606060, $rgb);
    }

    public function testAnsiColorToRgbOutOfRange(): void
    {
        $rgb = $this->invokeAnsiColorToRgb(99, false);
        $this->assertSame(0xc0c0c0, $rgb);
    }

    public function testGraphemeWidthCombiningMark(): void
    {
        $w = $this->invokeGraphemeWidth("\xcc\x80");
        $this->assertSame(0, $w);
    }

    public function testGraphemeWidthWideEastAsian(): void
    {
        $w = $this->invokeGraphemeWidth("\xe4\xb8\x80");
        $this->assertSame(2, $w);
    }

    public function testGraphemeWidthEmpty(): void
    {
        $w = $this->invokeGraphemeWidth('');
        $this->assertSame(0, $w);
    }

    public function testViewWithActiveAlertOnBackground(): void
    {
        $t = $this->toast
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'Hello world');

        $bg = \str_repeat("background line\n", 10);
        $result = $t->View($bg, 80, 10);

        $this->assertIsString($result);
        $this->assertStringContainsString('Hello world', $result);
    }

    private function invokeFillViewportFromString(Buffer $buf, array $lines): Buffer
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('fillViewportFromString');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $buf, $lines);
    }

    private function invokeRenderAlertToBuffer(Alert $alert): Buffer
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('renderAlertToBuffer');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $alert);
    }

    private function invokeNextCluster(string $s, int $i): string
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('nextCluster');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $s, $i);
    }

    private function invokePlaceAnsiStringAt(Buffer $buf, int $col, int $row, string $s): Buffer
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('placeAnsiStringAt');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $buf, $col, $row, $s);
    }

    private function invokeSgrToBufferStyle(string $sgr): ?Style
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('sgrToBufferStyle');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $sgr);
    }

    private function invokeAnsiColorToRgb(int $idx, bool $bright): int
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('ansiColorToRgb');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $idx, $bright);
    }

    private function invokeGraphemeWidth(string $g): int
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('graphemeWidth');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $g);
    }

    private function getQueue(Toast $t): array
    {
        $ref = (new \ReflectionClass($t))->getProperty('queue');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}
