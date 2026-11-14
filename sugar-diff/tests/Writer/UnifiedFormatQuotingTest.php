<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests\Writer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;
use SugarCraft\Diff\Reader\ScanRowKind;
use SugarCraft\Diff\Reader\UnifiedScan;
use SugarCraft\Diff\Writer\UnifiedFormat;

/**
 * Path labels are interpolated into a line-oriented protocol, so control
 * bytes, `"` and `\` in them ship C-quoted (git `core.quotePath` style):
 * a filename can never forge a `+++` row, drop a raw CSI sequence, or
 * desynchronise the count-tracking scan. Clean labels pass through
 * byte-identical — that half of the contract is pinned too.
 */
final class UnifiedFormatQuotingTest extends TestCase
{
    public function test_a_label_carrying_a_newline_cannot_forge_its_own_header_row(): void
    {
        $patch = Diff::compute("a\n", "b\n")->unified("x\n+++ b/forged");

        // One physical row per header, quoted, forgery inert.
        self::assertSame(
            '--- "a/x\n+++ b/forged"' . "\n"
            . '+++ "b/x\n+++ b/forged"' . "\n"
            . "@@ -1 +1 @@\n-a\n+b\n",
            $patch,
        );
        self::assertSame(1, preg_match_all('/^\+\+\+ /m', $patch));
    }

    public function test_escape_bytes_ship_as_octal_not_raw_control_codes(): void
    {
        $patch = Diff::compute("a\n", "b\n")->unified("\x1b[31mred");

        self::assertStringContainsString('--- "a/\033[31mred"', $patch);
        self::assertStringNotContainsString("\x1b", $patch);
    }

    public function test_the_standard_c_escapes_are_used_where_git_uses_them(): void
    {
        $patch = Diff::compute("a\n", "b\n")->unified("t\tab\x08c\x0bd\x0ce\r");

        self::assertStringContainsString('--- "a/t\tab\bc\vd\fe\r"', $patch);
    }

    public function test_delete_and_bell_take_the_octal_leg(): void
    {
        $patch = Diff::compute("a\n", "b\n")->unified("\x7f\x07bell");

        self::assertStringContainsString('--- "a/\177\007bell"', $patch);
    }

    public function test_quote_and_backslash_escape_within_the_quoted_form(): void
    {
        $patch = Diff::compute("a\n", "b\n")->unified('q"u\ote');

        self::assertStringContainsString('--- "a/q\"u\\\\ote"', $patch);
    }

    public function test_clean_labels_are_byte_identical_to_the_unquoted_form(): void
    {
        $patch = Diff::compute("a\n", "b\n")->unified('src/Thing.php', 'src/Other.php');

        self::assertStringStartsWith("--- a/src/Thing.php\n+++ b/src/Other.php\n", $patch);
    }

    public function test_multibyte_paths_are_not_quoted_by_default(): void
    {
        // Bytes >= 0x80 cannot carry a control code; git quotePath's job is
        // the protocol line, not ASCII-only display.
        $patch = Diff::compute("a\n", "b\n")->unified('café/naïve.txt');

        self::assertStringContainsString("--- a/café/naïve.txt\n", $patch);
    }

    public function test_a_quoted_header_still_scans_as_a_file_header(): void
    {
        $patch = Diff::compute("a\n", "b\n")->unified("x\n+++ b/forged");

        $rows = UnifiedScan::lines($patch);
        self::assertSame(ScanRowKind::FileHeader, $rows[0]->kind);
        self::assertSame(ScanRowKind::FileHeader, $rows[1]->kind);
        self::assertSame(ScanRowKind::HunkHeader, $rows[2]->kind);
        // The forged text stayed inside ONE physical row: five rows total,
        // exactly the count the writer emitted.
        self::assertCount(5, $rows);
    }

    public function test_dev_null_labels_are_clean_and_stay_unquoted(): void
    {
        $diff = Diff::compute("", "a\n", DiffOptions::new()->withDevNullOnEmptySide());

        self::assertStringStartsWith("--- /dev/null\n+++ b/f\n", $diff->unified('f'));
    }

    public function test_quoted_labels_carry_the_prefix_inside_the_quotes_like_live_git(): void
    {
        // sugar-diff-026 probe, git 2.43.0: a hostile quote in the name emits
        //   --- "a/w\"ird.txt"
        //   +++ "b/w\"ird.txt"
        // — the prefix is glued on BEFORE quoting, so the port emits the
        // identical bytes (this is conformance, not a deviation).
        $patch = Diff::compute("one\n", "two\n")->unified('w"ird.txt');
        $rows = explode("\n", $patch);

        self::assertSame('--- "a/w\"ird.txt"', $rows[0]);
        self::assertSame('+++ "b/w\"ird.txt"', $rows[1]);
    }
}
