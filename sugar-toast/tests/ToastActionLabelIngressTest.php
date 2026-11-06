<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Action, Position, Toast, ToastType};

/**
 * Pins the label ingress normalisation.
 *
 * Before this law held, a TAB inside an action label was charged one cell by
 * graphemeWidth() and emitted VERBATIM onto the wire (hex proof was
 * `5b 61 09 62 5d` for label "a\tb") — a raw C0 control desyncing every later
 * column to the terminal's tab stops. Newlines in a label gained the box a
 * row the stack geometry never budgeted. Inline SGR, by contrast, was
 * DROPPED in labels while the message channel honoured it — an undocumented
 * asymmetry that silently un-reddened a red "Delete" button.
 *
 * The contract now: labels are parsed at Action construction into printable
 * text + CSI-SGR styling, nothing else, and the renderer truncates them
 * ANSI-aware. Assertions are byte-level (raw 0x09/0x0A absence), not
 * substring folklore.
 */
final class ToastActionLabelIngressTest extends TestCase
{
    /** @param list<Action> $actions */
    private function render(array $actions): string
    {
        return Toast::new(60)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'labels are content too', actions: $actions)
            ->view(\str_repeat("bg\n", 12), 80, 12);
    }

    private static function sgrStrip(string $s): string
    {
        return (string) \preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $s);
    }

    public function testTabInLabelNeverReachesTheWire(): void
    {
        $out = $this->render([Action::new("a\tb", static function () {})]);
        $this->assertStringNotContainsString("\x09", $out, 'raw TAB byte on the output wire');
        // The label's printable content survives, tab simply gone.
        $this->assertStringContainsString('[ab]', self::sgrStrip($out));
        // Geometry: no row may exceed the viewport (the old tab leak measured
        // 83 cells "wide" on an 80-col frame because the terminal expanded
        // the raw tab past the cell budget).
        foreach (\explode("\n", $out) as $line) {
            $this->assertLessThanOrEqual(
                80,
                \SugarCraft\Core\Util\Width::string(self::sgrStrip($line)),
                'frame row overflowed the viewport width'
            );
        }
    }

    public function testTabLeakIsGoneOnBothChannels(): void
    {
        // Message channel positive control: the wrap splits on whitespace, so
        // a TAB in a message must not reach the wire either.
        $toast = Toast::new(60)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, "load\ting\tbox");
        $out = $toast->view(\str_repeat("bg\n", 12), 80, 12);
        $this->assertStringNotContainsString("\x09", $out);
    }

    public function testNewlineInLabelDoesNotTearTheBoxFrame(): void
    {
        $torn = $this->render([Action::new("a\nb", static function () {})]);
        $plain = $this->render([Action::new("ab", static function () {})]);
        // Normalisation folds the label to "ab", so the frame is byte-identical
        // to the unannotated one — no extra row, no lost border, no sentinel
        // breakage (qa M4 / security MINOR-1, both fixed at ingress).
        $this->assertSame($plain, $torn);
    }

    public function testInlineSgrInLabelIsHonouredLikeTheMessageChannel(): void
    {
        // m7: previously Action("\x1b[31mRED\x1b[0m") rendered with NO style
        // byte while the same styling in a message was honoured.
        $styled = $this->render([Action::new("\x1b[31mDelete\x1b[0m", static function () {})]);
        $bare = $this->render([Action::new('Delete', static function () {})]);
        $this->assertStringContainsString('Delete', self::sgrStrip($styled));
        $this->assertNotSame($bare, $styled, 'label SGR must change rendered bytes');
        $this->assertGreaterThan(
            \substr_count($bare, '38;2;'),
            \substr_count($styled, '38;2;'),
            'the red label must re-encode to truecolor through the grid, like a message would'
        );
    }

    public function testOscInLabelIsConsumedAndNeverPrinted(): void
    {
        $out = $this->render([Action::new("\x1b]8;;http://evil.invalid\x1b\\CLICKY", static function () {})]);
        $this->assertStringNotContainsString("\x1b]", $out, 'OSC introducer survived label ingress');
        $this->assertStringNotContainsString(']8;;', $out, 'OSC framing leaked as printable garbage');
        $this->assertStringNotContainsString('evil.invalid', $out, 'OSC payload must not be printed');
        // The M13 sentinel pair still passes through untouched (PUA is not a
        // control construct — documented printable channel).
        $sentinel = $this->render([Action::new("\u{e000}GO\u{e001}", static function () {})]);
        $this->assertSame(1, \substr_count($sentinel, "\xEE\x80\x80"));
        $this->assertSame(1, \substr_count($sentinel, "\xEE\x80\x81"));
    }

    public function testLoneEscIsStrippedFromLabels(): void
    {
        $out = $this->render([Action::new("a\x1bb", static function () {})]);
        $this->assertStringContainsString('[ab]', self::sgrStrip($out));
        // Same law as the trust-boundary walk: every ESC byte that survives
        // to the wire must be a CSI the renderer built itself (followed by
        // '['). A smuggled bare ESC would show up as ESC + [ab]-text.
        for ($i = 0; $i < \strlen($out); $i++) {
            if ($out[$i] !== "\x1b") {
                continue;
            }
            $this->assertSame('[', $out[$i + 1] ?? '', "bare ESC byte at offset {$i}");
        }
    }

    /**
     * n2: the psalm `non-empty-string` promise made executable — the wire
     * must never carry an empty "[]" button.
     */
    public function testLabelsThatNormaliseToNothingAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Action::new('', static function () {});
    }

    public function testTabOnlyLabelIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Action::new("\t \t", static function () {});
    }

    public function testSgrOnlyLabelIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Action::new("\x1b[31m\x1b[0m", static function () {});
    }

    public function testAlreadyCleanLabelsAreUntouched(): void
    {
        // The common path must be identity, or every golden in the suite
        // would churn under this "fix".
        foreach (['Retry', 'Undo', 'Keep both', 'Über', '取消'] as $clean) {
            $this->assertSame($clean, Action::new($clean, static function () {})->label);
        }
    }
}
