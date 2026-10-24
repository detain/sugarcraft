<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Action, Alert, Position, Toast, ToastType};
use PHPUnit\Framework\TestCase;

/**
 * Pins the documented trust boundary (README "Security & trust boundary",
 * docs/TRUST-BOUNDARY.md §1): alert text is app-supplied, and the cell-grid
 * re-encode means view()'s output can carry ONLY the escape sequences the
 * renderer itself builds — a hostile message can never smuggle raw OSC,
 * BEL, screen-clears, or sugar-mouse zone sentinels onto the wire.
 *
 * Inline SGR in a message remains an honoured styling feature (re-encoded
 * through the grid), so the negative pins here are deliberately scoped to
 * the non-CSI classes: OSC strings, BEL, and bare ESC payloads.
 */
final class ToastTrustBoundaryTest extends TestCase
{
    /**
     * Message shaped like a hostile payload: OSC-8 hyperlink framing (with a
     * BEL terminator variant), an erase-screen CSI, a bare-ESC 7-bit escape,
     * and forged sugar-mouse U+E000/U+E001 zone sentinels around a label.
     */
    private const HOSTILE = "\x1b]8;;http://evil.invalid\x1b\\CLICK"
        . "\x1b[2J"
        . "\x1b]0;title\x07TITLED"
        . "\x1bE";

    private function render(string $message): string
    {
        $toast = Toast::new(60)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Warning, $message);

        return $toast->view(\str_repeat("bg\n", 12), 80, 12);
    }

    public function testNoOscStringEverReachesTheOutput(): void
    {
        $out = $this->render(self::HOSTILE);
        $this->assertStringNotContainsString("\x1b]", $out, 'OSC introducer must never survive the grid re-encode');
        $this->assertStringNotContainsString("\x07", $out, 'BEL must never survive the grid re-encode');
    }

    public function testEveryEscapeIsCsiThatTheRendererBuilt(): void
    {
        $out = $this->render(self::HOSTILE);
        // Walk every ESC byte: the following byte must be '[' (CSI). Any other
        // introducer (']' OSC, 'P' DCS, 'E' NEL, 7-bit feeds) breaks the law.
        for ($i = 0; $i < \strlen($out); $i++) {
            if ($out[$i] !== "\x1b") {
                continue;
            }
            $this->assertSame(
                '[',
                $out[$i + 1] ?? '',
                "Escaped byte at offset {$i} is not a CSI introducer"
            );
        }
    }

    public function testForgedScreenClearIsNeutralisedToStyledCells(): void
    {
        $out = $this->render(self::HOSTILE);
        $this->assertStringNotContainsString("\x1b[2J", $out);
        // The message's printable payload still rides through — containment
        // must not be silent text loss (fail-loud data stays, escapes die).
        $this->assertStringContainsString('CLICK', $out);
    }

    public function testZoneSentinelsPassThroughAsPrintableText(): void
    {
        // Measured truth (README trust section): the cell grid treats the
        // U+E000/U+E001 zone sentinels as ordinary printable glyphs — they
        // REACH the composed frame. Hosts parsing marked frames must sweep
        // untrusted text with Sanitize::untrustedForMarkedFrames() upstream.
        // This pin reddens if the renderer ever starts dropping them (a
        // behavior change worth a documented flip, not a silent drift).
        $out = $this->render("pre \x{e000}ZONE\x{e001} post");
        $this->assertStringContainsString("\x{e000}", $out);
        $this->assertStringContainsString("\x{e001}", $out);
        $this->assertStringContainsString('ZONE', $out);
    }

    public function testControlBytesInActionLabelsAreContainedToo(): void
    {
        $action = Action::new("X\x1b]100;y\x07", static function () {});
        $toast = Toast::new(60)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'labels are content too', actions: [$action]);

        $out = $toast->view(\str_repeat("bg\n", 12), 80, 12);
        $this->assertStringNotContainsString("\x1b]", $out);
        $this->assertStringNotContainsString("\x07", $out);
    }

    public function testInlineSgrIsHonouredAsStylingNotStripped(): void
    {
        // Positive control on the boundary's other side: bold markup in a
        // message must change the rendered bytes (re-encoded through the
        // grid), proving containment is a re-encode, not a strip-everything
        // sanitizer.
        $styled = $this->render("\x1b[1mPlain\x1b[0m");
        $bare = $this->render('Plain');
        $this->assertNotSame($styled, $bare);
        $this->assertStringContainsString('Plain', $styled);
    }

    public function testPrebuiltAlertPathCarriesTheSameContainment(): void
    {
        // push() takes a pre-built Alert — the boundary must hold there too
        // (same render path, different door).
        $alert = (new Alert(ToastType::Error, self::HOSTILE));
        $out = Toast::new(60)
            ->withPosition(Position::TopLeft)
            ->push($alert)
            ->view(\str_repeat("bg\n", 12), 80, 12);

        $this->assertStringNotContainsString("\x1b]", $out);
        $this->assertStringNotContainsString("\x07", $out);
    }
}
