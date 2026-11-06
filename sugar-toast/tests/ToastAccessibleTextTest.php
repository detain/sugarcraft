<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Action, Position, Toast, ToastType};

/**
 * Pins the opt-in accessible text channel: severity
 * lived ONLY as icon colour+glyph (four identical-shaped boxes to a
 * colour-blind or text-extracting reader), and the "inline progress bar
 * (0-100%)" promise never printed a number. withAccessibleText() adds the
 * word and the percentage; DEFAULT OFF must stay byte-identical or the
 * whole golden corpus would churn under an accessibility flag.
 */
final class ToastAccessibleTextTest extends TestCase
{
    private function frame(Toast $toast, string $bg = '', int $cols = 60, int $rows = 12): string
    {
        return $toast->view($bg, $cols, $rows);
    }

    private function toast(bool $accessible): Toast
    {
        $base = Toast::new(50)->withPosition(Position::TopRight);
        return $accessible ? $base->withAccessibleText() : $base;
    }

    public function testDefaultFrameCarriesNoAccessibleChrome(): void
    {
        $out = $this->frame($this->toast(false)->alert(ToastType::Warning, 'alpha bravo'));
        foreach (['Warning', 'Info', 'Error', 'Success'] as $word) {
            $this->assertStringNotContainsString($word, $out);
        }
        // OFF is byte-identical to explicit withAccessibleText(false).
        $explicit = $this->frame($this->toast(false)->withAccessibleText(false)->alert(ToastType::Warning, 'alpha bravo'));
        $this->assertSame($out, $explicit);
        $this->assertFalse($this->toast(false)->accessibleText());
    }

    public function testSeverityWordAppearsWhenEnabled(): void
    {
        $out = $this->frame($this->toast(true)->alert(ToastType::Warning, 'alpha bravo'));
        $this->assertStringContainsString('Warning', $out);
        $error = $this->frame($this->toast(true)->alert(ToastType::Error, 'disk full'));
        $this->assertStringContainsString('Error', $error);
        // The word is plain cells: it must sit OUTSIDE the icon's SGR run —
        // strip ANSI and the header reads '⚠ Warning alpha' order-wise.
        $stripped = (string) \preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $out);
        $headerLine = '';
        foreach (\explode("\n", $stripped) as $line) {
            if (\str_contains($line, 'alpha')) {
                $headerLine = $line;
                break;
            }
        }
        $this->assertMatchesRegularExpression('/⚠.*Warning.*alpha/', $headerLine);
    }

    public function testProgressPrintsTheNumericPercentage(): void
    {
        $out = $this->frame($this->toast(true)->progressToast(ToastType::Info, 'downloading', 0.8));
        $this->assertStringContainsString('80%', $out);
        // The row stays border-closed and inside the box budget.
        foreach (\explode("\n", $out) as $line) {
            $row = (string) \preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $line);
            if (\str_contains($row, '80%')) {
                $this->assertMatchesRegularExpression('/^.*│.*│$/', $row);
                $this->assertStringContainsString('█', $row);
                $this->assertStringContainsString('░', $row);
            }
        }
        $zero = $this->frame($this->toast(true)->progressToast(ToastType::Info, 'x', 0.0));
        $this->assertStringContainsString('0%', $zero);
        $one = $this->frame($this->toast(true)->progressToast(ToastType::Info, 'x', 1.0));
        $this->assertStringContainsString('100%', $one);
        // OFF: no digits channel, only the block run (byte-identity law).
        $off = $this->frame($this->toast(false)->progressToast(ToastType::Info, 'downloading', 0.8));
        $this->assertStringNotContainsString('%', $off);
    }

    public function testNumberOnlySurvivesWhenItFitsHonest(): void
    {
        // At inner<=5 the '100%' label would only survive MANGLED, so the
        // honest degrade is the plain bar: no digits, no tearing, every box
        // row exactly the viewport width.
        $tight = $this->frame($this->toast(true)->progressToast(ToastType::Info, 'load', 1.0), '', 4, 12);
        foreach (\explode("\n", $tight) as $line) {
            $row = (string) \preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $line);
            $this->assertLessThanOrEqual(4, \SugarCraft\Core\Util\Width::string($row));
        }
        $this->assertStringNotContainsString('100%', $tight);
        // At 8 cols (inner 6) the number fits and lands beside a shrunken bar.
        $roomy = $this->frame($this->toast(true)->progressToast(ToastType::Info, 'load', 1.0), '', 8, 12);
        $this->assertStringContainsString('100%', $roomy);
        $this->assertStringContainsString('█', $roomy);
    }

    public function testActionGeometryStillAgreesUnderAccessibleChrome(): void
    {
        $toast = $this->toast(true)
            ->alert(ToastType::Warning, 'keep changes?', actions: [Action::new('Keep', static function () {})]);
        $rects = $toast->actionRects();
        $this->assertCount(1, $rects);
        $rect = $rects[0];
        $this->assertNotNull($toast->actionAt($rect['col'] + 1, $rect['row']));
        $this->assertSame('Keep', $toast->actionAt($rect['col'] + 1, $rect['row'])?->label);
    }
}
