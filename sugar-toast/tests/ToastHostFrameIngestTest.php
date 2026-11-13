<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Position, Toast, ToastType};

/**
 * Pins the host-frame escape ingestion.
 *
 * placeAnsiStringAt() used to recognise only CSI. A background row carrying
 * an OSC-8 hyperlink came back with the ESCs dropped by the control-byte
 * guard and the framing printed as LITERAL cells — "]8;;url \ CLICKY" —
 * corrupting rows the toast never touched, and destroying the link. (With
 * an empty queue view() returns the background byte-identical, so the
 * corruption appeared only when a toast was on screen.)
 *
 * The law now: string sequences (OSC/DCS/APC/PM/SOS) and two-character
 * escapes are consumed, never printed. On the BACKGROUND path an OSC-8
 * re-attaches a real Hyperlink to its run, so toAnsi() re-emits the frame
 * and the host's link survives the composite; on the alert-content path the
 * framing is dropped without honouring it — hostile text can neither forge
 * a clickable link nor spray escape bytes.
 */
final class ToastHostFrameIngestTest extends TestCase
{
    private const URL = 'http://example.invalid';

    /** @param non-empty-string $background */
    private function withToast(string $background): string
    {
        return Toast::new(40)
            ->withPosition(Position::BottomRight)
            ->alert(ToastType::Info, 'ingestion pin')
            ->view($background, 80, 12);
    }

    public function testHostOsc8LinkIsReEmittedNotPrintedAsGarbage(): void
    {
        $row = "\x1b]8;;" . self::URL . "\x1b\\CLICKY\x1b]8;;\x1b\\ plain tail";
        $out = $this->withToast($row . "\n" . \str_repeat("filler\n", 5));

        // The frame comes back as a real OSC-8 open + close, exactly once
        // each (cellbuffer's toAnsi spells the open spec-conformant
        // 'ESC]8;;url ESC\' since CB-01 790e4ef2c; the close is 'ESC]8;;ESC\').
        $this->assertSame(2, \substr_count($out, "\x1b]8;"), 'expected one link open + one close');
        $this->assertStringContainsString("\x1b]8;;" . self::URL . "\x1b\\CLICKY", $out);
        // No printable ']' framing may exist that is NOT ESC-introduced:
        // every ']8;' sequence on the wire is preceded by an ESC.
        $this->assertSame(
            \substr_count($out, "\x1b]8;"),
            \substr_count($out, ']8;'),
            'bare OSC framing leaked as printable garbage (the M1 corruption)'
        );
    }

    public function testLinkRowUnderTheBoxIsReplacedWholeNotHalfPrinted(): void
    {
        // TopLeft 40-col box at cols=48 genuinely covers the link run's
        // cells; a replaced link must vanish WHOLE (open AND close frames),
        // never re-emit around the box edges.
        $rows = [];
        for ($r = 0; $r < 12; $r++) {
            $rows[] = $r === 1
                ? "\x1b]8;;" . self::URL . "\x1b\\UNDER\x1b]8;;\x1b\\ box"
                : "filler{$r}";
        }
        $out = Toast::new(40)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'ingestion pin')
            ->view(\implode("\n", $rows), 48, 12);
        $this->assertStringNotContainsString(self::URL, $out, 'covered link must be replaced, not half-printed');
        $this->assertSame(0, \substr_count($out, "\x1b]8;"));
        $this->assertSame(0, \substr_count($out, ']8;'), 'no bare framing anywhere');
    }

    public function testNonLinkOscInTheBackgroundIsConsumedSilently(): void
    {
        $out = $this->withToast("\x1b]0;window title\x07TITLED row\n" . \str_repeat("filler\n", 5));
        $this->assertStringContainsString('TITLED row', $out);
        $this->assertStringNotContainsString('window title', $out, 'OSC payload must not survive as printable text');
        $this->assertStringNotContainsString("\x1b]0;", $out);
    }

    public function testUnterminatedOscSwallowsTheRemainderFailClosed(): void
    {
        // No BEL/ST terminator at all: the walker keeps consuming instead of
        // emitting the framing as garbage. Trailing text on that one row is
        // sacrificed — the documented fail-closed cost.
        $out = $this->withToast("start \x1b]8;;unterminated\x0a" . "next row\nfiller\n");
        $this->assertStringNotContainsString("\x1b]8;", $out);
        $this->assertStringNotContainsString("]8;", $out);
    }

    public function testTwoCharacterEscapesAreConsumedNotPrinted(): void
    {
        // ESC D (cursor-back) is a real host-frame construct: the ESC and its
        // mnemonic byte are consumed as a pair — the command never executes
        // and never prints — while surrounding printable text rides on.
        $out = $this->withToast("q\x1bDr\n" . \str_repeat("filler\n", 5));
        $this->assertStringContainsString('qr', $out, 'printable neighbours must survive');
        $this->assertStringNotContainsString('qDr', $out, 'the mnemonic byte must be consumed with the ESC');
    }

    public function testControlByteUrlIsRefusedAndTextKeepsPrinting(): void
    {
        $out = $this->withToast("\x1b]8;;http://bad\x01url\x1b\\TXT\x1b]8;;\x1b\\ tail\n" . \str_repeat("filler\n", 5));
        $this->assertStringContainsString('TXT', $out);
        // The poisoned link is dropped, never re-emitted onto the wire.
        $this->assertStringNotContainsString("\x1b]8;http://bad", $out);
    }

    public function testAlertContentNeverBuildsLinks(): void
    {
        // Message-channel duty: consume the framing, honour NOTHING.
        $toast = Toast::new(60)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Warning, "see \x1b]8;;" . self::URL . "\x1b\\CLICK\x1b]8;;\x1b\\ now");
        $out = $toast->view(\str_repeat("bg\n", 12), 80, 12);
        $this->assertStringNotContainsString("\x1b]8;", $out, 'alert text must not forge a live hyperlink');
        $this->assertStringNotContainsString(']8;', $out);
        $this->assertStringNotContainsString(self::URL, $out, 'forged URL bytes must not reach the wire');
        $this->assertStringContainsString('CLICK', $out, 'printable payload stays (containment is not text loss)');
    }

public function testComposedFrameRoundTripsItsLinkThroughTheNextRender(): void
    {
        // Delta hosts feed view()'s OUTPUT back in as the next background.
        // cellbuffer's spec-form open ('ESC]8;;url', CB-01 790e4ef2c) must
        // parse as an OPEN, not an empty-url CLOSE — the link survives.
        $frame = $this->withToast("\x1b]8;;" . self::URL . "\x1b\CLICKY\x1b]8;;\x1b\ tail\n" . \str_repeat("filler\n", 5));
        $again = Toast::new(40)
            ->withPosition(Position::BottomRight)
            ->alert(ToastType::Info, 'ingestion pin')
            ->view($frame, 80, 12);
        $this->assertStringContainsString("\x1b]8;;" . self::URL . "\x1b\\CLICKY", $again, 'link lost on the second render');
    }

    public function testEmptyQueueStillReturnsBackgroundByteIdentical(): void
    {
        // The M1 corruption only appeared with a toast on screen; the empty
        // queue fast path must stay untouched by the ingestion work.
        $bg = "\x1b]8;;" . self::URL . "\x1b\\CLICKY\x1b]8;;\x1b\\ rest\nplain\n";
        $out = Toast::new(60)->alert(ToastType::Info, 'gone')->cancelAlert(0)->clear()->view($bg, 80, 12);
        $this->assertSame($bg, $out);
    }
}
