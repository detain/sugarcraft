<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\ScriptUi\Command\JoinCommand;
use SugarCraft\ScriptUi\Command\StyleCommand;
use SugarCraft\ScriptUi\Command\TableCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * scriptui-24 (finding 019) — the three render commands (table, style,
 * join) emit third-party content, so their data channel carries the house
 * untrusted-display policy at intake. These probes plant escape bytes and
 * assert what actually leaves each exit: policy-clean by default, verbatim
 * only under the explicit --raw opt-out.
 */
final class UntrustedRenderIntakeTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scriptui-24-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testTableCellsArePolicyCleanAndRawUnderOptOut(): void
    {
        $file = $this->tmpDir . '/planted.csv';
        file_put_contents($file, "name,note\n" . "\"a\x1b[31mred\x1b[0m\",b\x07ell\x9bx\n");

        $tester = new CommandTester(new TableCommand());
        $tester->execute(['--file' => $file]);
        $display = $tester->getDisplay();

        self::assertStringNotContainsString("\x1b", $display);
        self::assertStringNotContainsString("\x07", $display);
        self::assertStringNotContainsString("\x9b", $display);
        self::assertStringNotContainsString('[31m', $display, 'sequence body must not survive as residue');
        self::assertStringContainsString('ared', $display);
        // \x9b is the 8-bit CSI introducer: it consumes the following byte
        // ('x') as a parameter as part of the whole-sequence strip.
        self::assertStringContainsString('bell', $display);
        self::assertStringNotContainsString('bellx', $display);

        $rawTester = new CommandTester(new TableCommand());
        $rawTester->execute(['--file' => $file, '--raw' => true]);
        $rawDisplay = $rawTester->getDisplay();

        self::assertStringContainsString("\x1b[31mred\x1b[0m", $rawDisplay, '--raw ships the data bytes verbatim');
        self::assertStringContainsString("\x07", $rawDisplay);
    }

    public function testStyleTextIsPolicyCleanAndRawUnderOptOut(): void
    {
        $planted = "a\x1b]0;title-spoof\x07b\x1b[31mred\x1b[0m";

        $tester = new CommandTester(new StyleCommand());
        $tester->execute(['text' => [$planted]]);
        $display = $tester->getDisplay();

        self::assertStringNotContainsString("\x1b", $display);
        self::assertStringNotContainsString("\x07", $display);
        self::assertSame('abred' . "\n", $display);

        $rawTester = new CommandTester(new StyleCommand());
        $rawTester->execute(['text' => [$planted], '--raw' => true]);

        self::assertSame($planted . "\n", $rawTester->getDisplay());
    }

    public function testJoinPartsArePolicyCleanAndRawUnderOptOut(): void
    {
        $parts = ["x\x1b[1;2;3Hyz", "q\x9cr"];

        $tester = new CommandTester(new JoinCommand());
        $tester->execute(['parts' => $parts]);
        $display = $tester->getDisplay();

        self::assertStringNotContainsString("\x1b", $display);
        self::assertStringNotContainsString("\x9c", $display);
        self::assertSame($parts === [] ? '' : implode("\n", array_map([Sanitize::class, 'untrustedForDisplay'], $parts)) . "\n", $display);

        $rawTester = new CommandTester(new JoinCommand());
        $rawTester->execute(['parts' => $parts, '--raw' => true]);

        self::assertSame(implode("\n", $parts) . "\n", $rawTester->getDisplay());
    }

    public function testPolicyIsIdentityOnCleanText(): void
    {
        // The doctrine must not touch honest bytes: UTF-8 rides the default
        // path unchanged (single line, so Style's block padding cannot skew
        // the byte comparison).
        $honest = "grüße 🚦 plain";

        $tester = new CommandTester(new StyleCommand());
        $tester->execute(['text' => [$honest]]);

        self::assertSame($honest . "\n", $tester->getDisplay());

        // Newlines survive the policy (CR family folds to LF, LF rides).
        $multilineTester = new CommandTester(new StyleCommand());
        $multilineTester->execute(['text' => ["a\r\nb\nc"]]);
        self::assertStringContainsString("a\nb", $multilineTester->getDisplay());
    }
}
