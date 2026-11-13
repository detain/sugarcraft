<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\QuickPick\HelpBar;
use SugarCraft\QuickPick\QuickPick;
use SugarCraft\QuickPick\StatusBar;
use SugarCraft\QuickPick\Support\UntrustedText;

/**
 * Trust-boundary pins for the escape-injection fix (review C1).
 *
 * Every string this component renders — item values (which may be read back
 * from disk by FileHistory), the prompt, the filter echo, help-bar legends,
 * status-bar messages — passes through {@see UntrustedText::guard()} before
 * it can reach a terminal. These tests plant the hostile shapes one by one
 * and hex-pin that none of them survive into the rendered frame.
 */
final class UntrustedTextTest extends TestCase
{
    // ------------------------------------------------------------------
    // Guard helper
    // ------------------------------------------------------------------

    public function testGuardIsByteIdentityOnOrdinaryText(): void
    {
        foreach (['apple', 'Éclair.txt', '日本語', 'a/b/c.php', 'x 100%'] as $benign) {
            self::assertSame($benign, UntrustedText::guard($benign));
        }
    }

    public function testGuardIsIdempotent(): void
    {
        $hostile = "a\x1b[2J\x07b\x00\u{e000}forged\u{e001}\u{202e}\xff\xfe";
        $once = UntrustedText::guard($hostile);
        self::assertSame($once, UntrustedText::guard($once));
    }

    public function testGuardDeletesCsiSequencesWholesale(): void
    {
        self::assertSame('ab', UntrustedText::guard("a\x1b[2Jb"));
    }

    public function testGuardDeletesHyperlinkAndTitleOscSequences(): void
    {
        self::assertSame('pay', UntrustedText::guard("p\x1b]8;;http://evil.example\x07a\x1b]8;;\x07y"));
        self::assertSame('x', UntrustedText::guard("x\x1b]0;owned-window-title\x07"));
    }

    public function testGuardSweepsC0NulAndBelt(): void
    {
        $guarded = UntrustedText::guard("a\x00b\x07c");
        self::assertStringNotContainsString("\x00", $guarded);
        self::assertStringNotContainsString("\x07", $guarded);
        self::assertStringContainsString('abc', $guarded);
    }

    public function testGuardDeletesForgedZoneSentinels(): void
    {
        // U+E000/U+E001 mouse-zone sentinels — the bytes sugar-mosaic and
        // crush consume as frame markers must never pass through a list value.
        $guarded = UntrustedText::guard("a\u{e000}clickjacked\u{e001}b");
        self::assertStringNotContainsString("\u{e000}", $guarded);
        self::assertStringNotContainsString("\u{e001}", $guarded);
        self::assertStringContainsString('a', $guarded);
        self::assertStringContainsString('b', $guarded);
    }

    public function testGuardMarksRtlOverrideInvisibles(): void
    {
        $guarded = UntrustedText::guard("a\u{202e}cba");
        self::assertStringNotContainsString("\u{202e}", $guarded);
        // The override is shown as a printable marker, not silently removed.
        self::assertStringContainsString('cba', $guarded);
    }

    public function testGuardRepairsInvalidUtf8ToReplacementCharacter(): void
    {
        $guarded = UntrustedText::guard("a\xff\xfeb");
        self::assertStringNotContainsString("\xff", $guarded);
        self::assertStringNotContainsString("\xfe", $guarded);
        self::assertStringContainsString("\u{fffd}", $guarded);
        self::assertSame('ab', \preg_replace('/\x{fffd}+/u', '', $guarded));
    }

    public function testGuardFlattensTabsAndNewlines(): void
    {
        // Overlay rows are fixed-width single lines; a surviving TAB or LF
        // desynchronises the whole composite no matter who injected it.
        self::assertSame('a b c d e', UntrustedText::guard("a\r\nb\rc\nd\te"));
    }

    // ------------------------------------------------------------------
    // -072 memoization: one transform per distinct input, identical bytes
    // ------------------------------------------------------------------

    /** @return array<string, string> the live memo table */
    private static function memoTable(): array
    {
        $property = new \ReflectionProperty(UntrustedText::class, 'memo');
        $property->setAccessible(true);
        /** @var array<string, string> $value */
        $value = $property->getValue();
        return $value;
    }

    /** Number of full transforms executed so far in this process. */
    private static function transformPasses(): int
    {
        $property = new \ReflectionProperty(UntrustedText::class, 'transformPasses');
        $property->setAccessible(true);
        return (int) $property->getValue();
    }

    public function testGuardMemoizesRepeatedInputToOnePass(): void
    {
        $dirty  = "h\x1b[2Jost";
        $before = self::transformPasses();
        $first  = UntrustedText::guard($dirty);
        self::assertSame($before + 1, self::transformPasses(), 'a distinct dirty input runs exactly one sweep');
        $second = UntrustedText::guard($dirty);
        self::assertSame($first, $second, 'memo hit returns identical bytes');
        self::assertSame($before + 1, self::transformPasses(), 'the repeat ran no second sweep');
        self::assertStringNotContainsString("\x1b", self::memoTable()[$dirty] ?? '', 'and the memo holds the guarded answer');
        self::assertSame('host', $first);
    }

    public function testGuardFastPathSkipsTheSweepForPureAscii(): void
    {
        $before = self::transformPasses();
        self::assertSame('ascii-clean-9000', UntrustedText::guard('ascii-clean-9000'));
        self::assertSame($before, self::transformPasses(), 'a printable-ASCII-only input never reaches the sweep');
        // And a cold dirty key does sweep exactly once.
        UntrustedText::guard("cold\x07key");
        self::assertSame($before + 1, self::transformPasses(), 'non-ASCII input reaches the sweep on a cold key');
    }

    public function testGuardMemoNeverCollidesAcrossDistinctInputs(): void
    {
        $clean  = UntrustedText::guard('apple');
        $dirty  = UntrustedText::guard("a\x1bpple");
        $broken = UntrustedText::guard("apple\xff");
        self::assertSame('apple', $clean);
        self::assertNotSame($clean, $broken, 'the hostile shape transforms; the key namespace is the exact input');
        self::assertStringNotContainsString("\x1b", $dirty);
        self::assertStringNotContainsString("\xff", $broken);
        // Each input keeps its own answer under repetition.
        self::assertSame($clean, UntrustedText::guard('apple'));
        self::assertSame($dirty, UntrustedText::guard("a\x1bpple"));
        self::assertSame($broken, UntrustedText::guard("apple\xff"));
    }

    public function testGuardFastPathKeepsTheDirtyArmsTransforming(): void
    {
        // The fast path must be a pure-printable-ASCII promise, not a
        // blanket skip: every hostile family still transforms.
        self::assertSame('ab', UntrustedText::guard("a\x1b[2Jb"));
        self::assertStringNotContainsString("\x00", UntrustedText::guard("a\x00b"));
        self::assertStringNotContainsString("\x07", UntrustedText::guard("a\x07b"));
        self::assertStringNotContainsString("\u{e000}", UntrustedText::guard("a\u{e000}b"));
        self::assertStringNotContainsString("\u{202e}", UntrustedText::guard("a\u{202e}b"));
        self::assertStringNotContainsString("\x9b", UntrustedText::guard("a\x9b0m"));
        self::assertStringContainsString("\u{fffd}", UntrustedText::guard("a\xff\xfeb"));
        // Benign non-ASCII keeps byte identity through the memo slow path.
        self::assertSame('Éclair.txt', UntrustedText::guard('Éclair.txt'));
        self::assertSame('日本語', UntrustedText::guard('日本語'));
    }

    // ------------------------------------------------------------------
    // End-to-end: hostile strings through view()
    // ------------------------------------------------------------------

    public function testViewItemValuesCannotReachTerminalAsControls(): void
    {
        $items = [
            "esc-erase\x1b[2J",
            "hyperlink\x1b]8;;http://evil.example\x07text\x1b]8;;\x07",
            "osctitle\x1b]0;owned\x07",
            "dcs\x1bP1\$qevil\x1b\\",
            "c1-8bit\x9b0m",
            "nul\x00bel\x07",
            "sentinels\u{e000}faked\u{e001}",
            "rtl\u{202e}hidden",
            "badutf\xff\xfe",
            'plain-apple',
        ];

        $frame = QuickPick::new($items)->withPrompt('')->withWindowHeight(12)->show()
            ->view(str_repeat('.', 80));

        self::assertStringNotContainsString("\x1b[2J", $frame);
        self::assertStringNotContainsString("\x1b]8;;", $frame);
        self::assertStringNotContainsString("\x1b]0;", $frame);
        self::assertStringNotContainsString("\x1bP", $frame);
        self::assertStringNotContainsString("\x9b", $frame);
        self::assertStringNotContainsString("\x00", $frame);
        self::assertStringNotContainsString("\x07", $frame);
        self::assertStringNotContainsString("\u{e000}", $frame);
        self::assertStringNotContainsString("\u{e001}", $frame);
        self::assertStringNotContainsString("\u{202e}", $frame);
        self::assertStringNotContainsString("\xff", $frame);
        // Ordinary payload survives: the frame still says what it listed.
        self::assertStringContainsString('plain-apple', $frame);
        self::assertStringContainsString('esc-erase', $frame);
    }

    public function testViewStaysEscapeFreeWhenTypeInjectsControls(): void
    {
        $quickPick = QuickPick::new(['apple'])->withPrompt('> ')->show();
        $quickPick->type("a\x1b[2J");

        $frame = $quickPick->view(str_repeat('.', 80));

        self::assertStringNotContainsString("\x1b", $frame);
        // The benign prefix of the typed filter still echoes.
        self::assertStringContainsString('a', $frame);
    }

    public function testViewPromptEchoCannotCarryControls(): void
    {
        $frame = QuickPick::new(['apple'])->withPrompt("pick\x1b]0;pwned\x07> ")->show()
            ->view(str_repeat('.', 80));

        self::assertStringNotContainsString("\x1b", $frame);
        self::assertStringContainsString('pick', $frame);
    }

    public function testHostFormatterStylingSurvivesTheGuard(): void
    {
        // The guard runs BEFORE the formatter: consumer SGR is host styling,
        // applied on top of already-clean text, and must reach the frame.
        $formatter = static fn(string $value, bool $isSelected, int $number = 0): string
            => "\x1b[1m" . $value . "\x1b[0m";

        $frame = QuickPick::new(['apple'], $formatter)->show()->view(str_repeat('.', 80));

        self::assertStringContainsString("\x1b[1mapple\x1b[0m", $frame);
    }

    public function testValuesWithNewlinesStayOnASingleFrameRow(): void
    {
        $frame = QuickPick::new(["two\nlines", "tab\there"])->withWindowHeight(4)->withWindowWidth(24)
            ->show()->view(str_repeat('.', 40));

        // 'two' and 'lines' never occupy separate rows, and no raw newline
        // from the value splits the frame.
        self::assertStringContainsString('two lines', $frame);
        self::assertStringContainsString('tab here', $frame);
    }

    // ------------------------------------------------------------------
    // HelpBar / StatusBar ingress
    // ------------------------------------------------------------------

    public function testHelpBarLegendTextIsGuardedAtIngress(): void
    {
        $bar = new HelpBar([
            "\x1b[31mred-key\x07" => "desc\x1b]8;;http://evil\x07ected",
            'plain-key' => 'plain-desc',
        ]);
        $rendered = $bar->render();

        self::assertStringNotContainsString("\x1b", $rendered);
        self::assertStringNotContainsString("\x07", $rendered);
        // The bar's own structure (separator glyph) is untouched.
        self::assertStringContainsString('│', $rendered);
        self::assertStringContainsString('red-key', $rendered);
        self::assertStringContainsString('plain-key', $rendered);
    }

    public function testStatusBarMessageAndSegmentsAreGuardedAtIngress(): void
    {
        $bar = (new StatusBar("done\u{202e}\x07"))
            ->withSegment("na\x1b[2Jme", "va\x00l");
        $rendered = $bar->render();

        self::assertStringNotContainsString("\x1b", $rendered);
        self::assertStringNotContainsString("\x00", $rendered);
        self::assertStringNotContainsString("\u{202e}", $rendered);
        self::assertStringContainsString('done', $rendered);
        self::assertStringContainsString('name', $rendered);
        self::assertStringContainsString('val', $rendered);
    }

    public function testStatusBarPlainRoundTripIsUnchanged(): void
    {
        $bar = (new StatusBar('selected 3 files'))->withSegment('mode', 'fuzzy');

        self::assertSame('selected 3 files', $bar->message());
        self::assertSame(['mode' => 'fuzzy'], $bar->segments());
    }
}
