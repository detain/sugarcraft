<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Stickers\Flex\FlexBox;
use SugarCraft\Stickers\Flex\FlexItem;
use SugarCraft\Stickers\Table\Column;

/**
 * Adversarial regression tests for the data-origin sanitizers in
 * {@see Column::sanitize()} and {@see FlexBox::sanitize()}.
 *
 * Both are now routed through the canonical C1-aware
 * {@see \SugarCraft\Core\Util\Sanitize::untrusted()}. Before that they used
 * hand-rolled `\x1b`-only regexes and were blind to the 8-bit C1 escape family
 * (`\x9b` CSI, `\x9d` OSC, `\x90` DCS, `\x9f` APC) and to unterminated
 * DCS/APC payloads — so a malicious cell/panel value could drive a cursor-move,
 * erase, window-title-set or sixel/Kitty graphics sequence without ever writing
 * an `\x1b`, and land verbatim on the terminal (see
 * docs/research/ansi-tmux-ansicode-audit.md #9).
 *
 * Column is driven through its public `format()` seam (which returns the
 * sanitized string) and FlexBox through `render()` with NO item style, so the
 * library itself emits no escape — any escape byte in the output would have to
 * be a smuggled one. Each case asserts the visible text survives AND that no
 * executable control byte survives.
 */
final class UntrustedSanitizerC1Test extends TestCase
{
    /**
     * Fail-closed invariant for the ASCII C1 vectors: after the strip, output
     * is printable ASCII plus the layout whitespace \t \n \r — no ESC, no C0
     * control, no DEL, and no lone 8-bit C1 byte (the vectors carry no valid
     * multi-byte UTF-8, so any surviving non-ASCII/control byte is a leak).
     */
    private function assertStrippedToAscii(string $out, string $case): void
    {
        $this->assertMatchesRegularExpression(
            '/^[\x20-\x7e\t\n\r]*$/D',
            $out,
            "$case leaked a non-printable byte: " . bin2hex($out)
        );
    }

    /**
     * Weaker invariant for genuinely multi-byte UTF-8 inputs: valid CJK
     * continuation bytes (0x80-0x9F inside a well-formed sequence) MUST survive,
     * so we only forbid ESC, C0 controls other than \t \n \r, and DEL.
     */
    private function assertNoAsciiControls(string $out, string $case): void
    {
        $this->assertSame(
            0,
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|\x1b/', $out),
            "$case leaked an executable ASCII control byte: " . bin2hex($out)
        );
    }

    /**
     * @return array<string, array{0:string,1:string}> case label => [input, surviving-text-must-contain]
     */
    private static function c1Vectors(): array
    {
        return [
            '8-bit CSI cursor-move'    => ["A\x9bH\x9b2Jdone", 'done'],
            '8-bit CSI truecolor'      => ["\x9b38;2;255;0;0mRED", 'RED'],
            '8-bit OSC title-set'      => ["A\x9d0;pwn\x07B", 'AB'],
            'nested OSC-in-CSI'        => ["A\x1b]0;\x1b[31mB", 'AB'],
            'nested 8-bit-wrapped OSC' => ["A\x9b\x1b]0;evil\x07B", 'AB'],
            'truncated DCS'            => ["A\x1bPtmux;echo pwned", 'A'],
            'truncated APC'            => ["A\x1b_Gkitty;sixelpayload", 'A'],
            'truncated 8-bit DCS'      => ["A\x90tmux;evil", 'A'],
            'truncated 8-bit APC'      => ["A\x9fGkitty;evil", 'A'],
            'truncated 8-bit SOS'      => ["A\x98evilbody", 'A'],
            'lone 8-bit ST'            => ["A\x9cB", 'AB'],
            'truncated 8-bit CSI'      => ["A\x9b31", 'A'],
        ];
    }

    public function testColumnFormatStripsEveryC1Vector(): void
    {
        $col = Column::make('X', 200);
        foreach (self::c1Vectors() as $case => [$input, $survivor]) {
            $out = $col->format($input, 0);
            $this->assertStrippedToAscii($out, "Column::format $case");
            if ($survivor !== '') {
                $this->assertStringContainsString($survivor, $out, "Column::format $case dropped visible text");
            }
        }
    }

    public function testColumnFormatSpecificC1CsiRemoved(): void
    {
        // `\x9b` (8-bit CSI) leading a cursor-move must be gone, not passed
        // through as the old `\x1b`-only regex let it.
        $out = Column::make('X', 200)->format("Report\x9bH\x9b2Jdone", 0);

        $this->assertSame('Reportdone', $out);
    }

    public function testFlexBoxRenderStripsEveryC1Vector(): void
    {
        foreach (self::c1Vectors() as $case => [$input, $survivor]) {
            // No withStyle() → the library adds no escape, so anything hostile
            // in the content must be stripped by sanitize().
            $box = FlexBox::row(FlexItem::new($input));
            $out = $box->render(200, 1);
            $this->assertStrippedToAscii($out, "FlexBox::render $case");
            if ($survivor !== '') {
                $this->assertStringContainsString(trim($survivor), trim($out), "FlexBox::render $case dropped visible text");
            }
        }
    }

    public function testColumnPreservesValidUtf8AndTabNewline(): void
    {
        // The canonical strip keeps well-formed UTF-8 (CJK continuation bytes
        // 0x80-0x9F are NOT treated as lone C1) and preserves \t and \n that the
        // layout code splits/pads on.
        $col = Column::make('X', 200);
        $out = $col->format("東京\tvalue\nline2", 0);

        $this->assertStringContainsString('東京', $out, 'CJK must not be corrupted');
        $this->assertStringContainsString("\t", $out, 'TAB preserved for layout');
        $this->assertStringContainsString("\n", $out, 'LF preserved for line split');
        $this->assertNoAsciiControls($out, 'Column UTF-8/tab/newline');
    }

    public function testFlexBoxPreservesValidUtf8(): void
    {
        $box = FlexBox::row(FlexItem::new('東京 normal'));
        $out = $box->render(200, 1);

        $this->assertStringContainsString('東京', $out);
        $this->assertNoAsciiControls($out, 'FlexBox UTF-8');
    }

    public function testColumnBenignContentUnchanged(): void
    {
        // No over-stripping: ordinary printable text round-trips exactly.
        $out = Column::make('X', 200)->format('plain value 123', 0);

        $this->assertSame('plain value 123', $out);
    }
}
