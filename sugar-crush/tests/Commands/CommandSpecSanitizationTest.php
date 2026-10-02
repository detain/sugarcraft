<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\CommandSpec;

/**
 * Audit 15b-16, load-boundary half: a project command file arrives with every
 * `git clone`, is loaded with no trust step, and its `description` and
 * `argument-hint` are painted into the "/" popup the moment the user types
 * "/". {@see CommandSpec::fromFile()} must therefore hand back display text
 * that is one control-free row - no escape sequence (OSC 52 clipboard write,
 * screen clear), no C0/C1 control, no line break.
 *
 * The YAML in each fixture is double-quoted, so `\e`, `\a`, `\r`, `\n` and
 * `\u009b` are the real bytes by the time the frontmatter parser is done -
 * which is exactly how a hostile file would spell them.
 */
final class CommandSpecSanitizationTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-cmdspec-sanitize-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function load(string $frontmatter): CommandSpec
    {
        $path = $this->dir . '/evil.md';
        file_put_contents($path, "---\n" . $frontmatter . "\n---\nbody\n");

        return CommandSpec::fromFile($path, 'evil', 'project');
    }

    /** Every byte sequence that must never survive into display text. */
    private function assertDisplaySafe(string $text, string $label): void
    {
        $this->assertStringNotContainsString("\e", $text, "$label: no ESC may survive");
        $this->assertStringNotContainsString("\x07", $text, "$label: no BEL may survive");
        $this->assertStringNotContainsString("\r", $text, "$label: no CR may survive");
        $this->assertStringNotContainsString("\n", $text, "$label: no LF may survive");
        $this->assertStringNotContainsString("\u{009B}", $text, "$label: no C1 CSI may survive");
        $this->assertStringNotContainsString(']52;', $text, "$label: the OSC 52 payload must go with its introducer");
        $this->assertSame(0, preg_match('/\p{Cc}/u', $text), "$label: no control code point at all");
    }

    public function testDescriptionIsStrippedOfEscapesControlsAndBreaks(): void
    {
        $spec = $this->load(
            'description: "Run lint \e]52;c;eA==\a then \e[2J\e[H clear\r\nnext \u009b2J line"',
        );

        $this->assertDisplaySafe($spec->description, 'description');
        // The words the author wrote are kept, on one row, in order.
        $this->assertMatchesRegularExpression('/^Run lint .*then .*clear next .*line$/', $spec->description);
    }

    public function testArgumentHintIsStrippedOfEscapesControlsAndBreaks(): void
    {
        $spec = $this->load(
            "description: ok\nargument-hint: \"<file\\e[31m>\\e]52;c;eA==\\a\\r\\n\\u009b<more>\"",
        );

        $this->assertNotNull($spec->argumentHint);
        $this->assertDisplaySafe($spec->argumentHint, 'argument-hint');
        $this->assertStringContainsString('<file>', $spec->argumentHint);
        $this->assertStringContainsString('<more>', $spec->argumentHint);
    }

    /**
     * A description that is NOTHING but control bytes sanitizes to '', and an
     * empty popup cell is worse than the default every description-less file
     * already gets.
     */
    public function testADescriptionThatSanitizesToNothingFallsBackToTheDefault(): void
    {
        $spec = $this->load('description: "\e[2J\r\n\a"');

        $this->assertSame('Custom command: evil', $spec->description);
    }

    public function testAnArgumentHintThatSanitizesToNothingIsAbsent(): void
    {
        $spec = $this->load("description: ok\nargument-hint: \"\\e]52;c;eA==\\a\"");

        $this->assertNull($spec->argumentHint);
    }

    /** The boundary must not cost an honest file anything. */
    public function testOrdinaryTextPassesThroughUnchanged(): void
    {
        $spec = $this->load("description: \"Review the diff — strictly, ünïcode kept\"\nargument-hint: \"<path> [--fix]\"");

        $this->assertSame('Review the diff — strictly, ünïcode kept', $spec->description);
        $this->assertSame('<path> [--fix]', $spec->argumentHint);
    }
}
