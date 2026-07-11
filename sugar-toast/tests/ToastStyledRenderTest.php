<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Core\Util\Color;
use SugarCraft\Toast\{Alert, Overflow, Position, Toast, ToastType};
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the additive per-alert bg/fg/border colour styling on the
 * Toast renderer (W18 foundation for the sugar-dash toast migration).
 *
 * The plain (colour-less) render path MUST stay byte-identical to the
 * pre-styling behaviour — proven by comparing a push()'d bare Alert to the
 * existing alert() path.
 */
final class ToastStyledRenderTest extends TestCase
{
    private const BG     = '#1E3A5F'; // 30, 58, 95
    private const FG     = '#E5E7EB'; // 229, 231, 235
    private const BORDER = '#3B82F6'; // 59, 130, 246

    private function bg(): string
    {
        return \str_repeat("line\n", 12);
    }

    private function styledAlert(): Alert
    {
        return (new Alert(ToastType::Info, 'Styled message'))
            ->withBackgroundColor(Color::hex(self::BG))
            ->withForegroundColor(Color::hex(self::FG))
            ->withBorderColor(Color::hex(self::BORDER));
    }

    // ─── Styled render emits truecolor SGR ──────────────────────────────

    public function testStyledAlertEmitsBackgroundFillSgr(): void
    {
        $out = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->push($this->styledAlert())
            ->View($this->bg(), 80, 12);

        // #1E3A5F background → SGR 48;2;30;58;95
        $this->assertStringContainsString('48;2;30;58;95', $out);
    }

    public function testStyledAlertEmitsBorderColourSgr(): void
    {
        $out = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->push($this->styledAlert())
            ->View($this->bg(), 80, 12);

        // #3B82F6 border → SGR 38;2;59;130;246 on the box glyphs
        $this->assertStringContainsString('38;2;59;130;246', $out);
    }

    public function testStyledAlertEmitsForegroundTextSgr(): void
    {
        $out = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->push($this->styledAlert())
            ->View($this->bg(), 80, 12);

        // #E5E7EB text → SGR 38;2;229;231;235 on the message text
        $this->assertStringContainsString('38;2;229;231;235', $out);
    }

    public function testStyledAlertKeepsIconSeverityColour(): void
    {
        // Info icon renders red-free but coloured (SGR 34 → truecolor);
        // the icon must retain its own colour, not be overwritten by fg.
        // candy-core ansiColorToRgb maps 34 (blue) → 0,0,128 → 38;2;0;0;128.
        $out = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->push($this->styledAlert())
            ->View($this->bg(), 80, 12);

        $this->assertStringContainsString('38;2;0;0;128', $out);
    }

    // ─── Unstyled render stays byte-identical ───────────────────────────

    public function testUnstyledPushRendersByteIdenticalToAlertPath(): void
    {
        $viaPush = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->push(new Alert(ToastType::Info, 'Plain message'))
            ->View($this->bg(), 80, 12);

        $viaAlert = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->info('Plain message')
            ->View($this->bg(), 80, 12);

        $this->assertSame($viaAlert, $viaPush);
    }

    public function testUnstyledRenderEmitsNoBackgroundFill(): void
    {
        $out = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->push(new Alert(ToastType::Info, 'Plain message'))
            ->View($this->bg(), 80, 12);

        // No background colour set ⇒ no 48;2;… (bg) SGR anywhere.
        $this->assertStringNotContainsString('48;2;', $out);
    }

    // ─── Alert colour withers: immutability + propagation ───────────────

    public function testWithBackgroundColorIsImmutable(): void
    {
        $original = new Alert(ToastType::Info, 'msg');
        $styled = $original->withBackgroundColor(Color::hex('#112233'));

        $this->assertNotSame($original, $styled);
        $this->assertNull($original->backgroundColor);
        $this->assertInstanceOf(Color::class, $styled->backgroundColor);
        $this->assertSame('#112233', $styled->backgroundColor->toHex());
        $this->assertSame('msg', $styled->message);
    }

    public function testWithForegroundColorIsImmutable(): void
    {
        $original = new Alert(ToastType::Info, 'msg');
        $styled = $original->withForegroundColor(Color::hex('#445566'));

        $this->assertNotSame($original, $styled);
        $this->assertNull($original->foregroundColor);
        $this->assertSame('#445566', $styled->foregroundColor->toHex());
    }

    public function testWithBorderColorIsImmutable(): void
    {
        $original = new Alert(ToastType::Info, 'msg');
        $styled = $original->withBorderColor(Color::hex('#778899'));

        $this->assertNotSame($original, $styled);
        $this->assertNull($original->borderColor);
        $this->assertSame('#778899', $styled->borderColor->toHex());
    }

    public function testColourWithersDoNotClobberEachOther(): void
    {
        $alert = (new Alert(ToastType::Info, 'msg'))
            ->withBackgroundColor(Color::hex('#010101'))
            ->withForegroundColor(Color::hex('#020202'))
            ->withBorderColor(Color::hex('#030303'));

        $this->assertSame('#010101', $alert->backgroundColor->toHex());
        $this->assertSame('#020202', $alert->foregroundColor->toHex());
        $this->assertSame('#030303', $alert->borderColor->toHex());
    }

    public function testExistingWithersPreserveColours(): void
    {
        // withProgress/withActions/withExpiry must carry the new colour
        // fields forward — regression guard for the propagation edits.
        $alert = (new Alert(ToastType::Info, 'msg'))
            ->withBackgroundColor(Color::hex('#0A0B0C'))
            ->withProgress(0.5)
            ->withActions([])
            ->withExpiry(10.0);

        $this->assertNotNull($alert->backgroundColor);
        $this->assertSame('#0a0b0c', $alert->backgroundColor->toHex());
        $this->assertSame(0.5, $alert->progress);
        $this->assertNotNull($alert->expiresAt);
    }

    public function testNullColourWitherClearsFill(): void
    {
        $alert = (new Alert(ToastType::Info, 'msg'))
            ->withBackgroundColor(Color::hex('#0A0B0C'))
            ->withBackgroundColor(null);

        $this->assertNull($alert->backgroundColor);
    }

    // ─── push() honours queue policy ────────────────────────────────────

    public function testPushHonoursConfiguredDuration(): void
    {
        $t = Toast::new(50)
            ->withDuration(5.0)
            ->push(new Alert(ToastType::Info, 'msg'));

        $queue = $this->queueOf($t);
        $this->assertCount(1, $queue);
        $this->assertNotNull($queue[0]->expiresAt);
    }

    public function testPushAppliesDropOldestOverflow(): void
    {
        $t = Toast::new(50)
            ->withMaxConcurrent(2)
            ->withOverflow(Overflow::DropOldest)
            ->push(new Alert(ToastType::Info, 'one'))
            ->push(new Alert(ToastType::Info, 'two'))
            ->push(new Alert(ToastType::Info, 'three'));

        $queue = $this->queueOf($t);
        $this->assertCount(2, $queue);
        $this->assertSame('two', $queue[0]->message);
        $this->assertSame('three', $queue[1]->message);
    }

    /** @return list<Alert> */
    private function queueOf(Toast $t): array
    {
        $ref = (new \ReflectionClass($t))->getProperty('queue');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}
