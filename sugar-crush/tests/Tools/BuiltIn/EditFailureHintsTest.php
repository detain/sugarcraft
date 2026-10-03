<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\Edit\EditFailureHints;

/**
 * Audit 0.11: a failed Edit says where — every match's line on ambiguity, and on
 * a miss whether the edit already landed, whether old_string carries Read's
 * `N: ` prefixes, and the closest windows of the file.
 *
 * @see EditFailureHints
 */
final class EditFailureHintsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_edithints_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    /**
     * @return array{0: string, 1: bool, 2: string}
     */
    private function edit(string $contents, string $old, string $new, bool $replaceAll = false): array
    {
        $path = $this->dir . '/target.php';
        file_put_contents($path, $contents);
        $result = (new Edit())->execute([
            'file_path' => $path,
            'old_string' => $old,
            'new_string' => $new,
            'replace_all' => $replaceAll,
        ]);

        return [$result->content(), $result->isError(), (string) file_get_contents($path)];
    }

    public function testAnAmbiguousMatchNamesTheLineOfEveryMatch(): void
    {
        $contents = "a\nfoo();\nb\nc\nfoo();\nfoo();\n";

        [$content, $error, $after] = $this->edit($contents, 'foo();', 'bar();');

        self::assertTrue($error);
        self::assertSame($contents, $after, 'an ambiguous edit leaves the file untouched');
        self::assertStringContainsString('old_string is not unique (3 matches, starting at lines 2, 5, 6)', $content);
        self::assertStringContainsString('replace_all', $content);
        self::assertStringContainsString('file left unchanged', $content);
    }

    public function testTheMatchListIsBounded(): void
    {
        $message = EditFailureHints::ambiguous(str_repeat("x\n", 30), 'x', 30);

        self::assertStringContainsString('lines 1, 2, 3', $message);
        self::assertStringContainsString('20 and 10 more', $message);
    }

    public function testReplaceAllStillReplacesEveryMatch(): void
    {
        [, $error, $after] = $this->edit("foo\nfoo\n", 'foo', 'bar', true);

        self::assertFalse($error);
        self::assertSame("bar\nbar\n", $after);
    }

    public function testAMissSaysWhenNewStringIsAlreadyThere(): void
    {
        $contents = "<?php\n\nfunction renamed(): void {}\n";

        [$content, $error, $after] = $this->edit($contents, 'function original(): void {}', 'function renamed(): void {}');

        self::assertTrue($error);
        self::assertSame($contents, $after);
        self::assertStringStartsWith('Error: old_string not found in ', $content);
        self::assertStringContainsString('new_string is already in the file (starting at line 3)', $content);
        self::assertStringContainsString('may already have been applied', $content);
    }

    public function testAMissCopiedFromReadOutputNamesTheLineNumberPrefixes(): void
    {
        $contents = "<?php\n\$a = 1;\n\$b = 2;\n";

        [$content, $error] = $this->edit($contents, "2: \$a = 1;\n3: \$b = 2;", "\$a = 10;\n\$b = 20;");

        self::assertTrue($error);
        self::assertStringContainsString('"N: " line numbers', $content);
        self::assertStringContainsString('Without them it matches at line 2.', $content);
    }

    public function testAMissOffersTheClosestWindowsWithLineNumbers(): void
    {
        $contents = "<?php\n\nfunction alpha(int \$x): int\n{\n    return \$x + 1;\n}\n\nfunction beta(): void {}\n";

        [$content, $error] = $this->edit(
            $contents,
            "function alpha(int \$y): int\n{\n    return \$y + 1;\n}",
            'replacement',
        );

        self::assertTrue($error);
        self::assertStringContainsString('Did you mean lines 3-6 (', $content);
        self::assertStringContainsString("\n3: function alpha(int \$x): int\n4: {\n5:     return \$x + 1;\n6: }", $content);
    }

    public function testATypoOnEveryLineIsStillFoundThroughFuzzyAnchors(): void
    {
        $contents = "first line here\nsecond line here\nthird line here\nunrelated text entirely\n";

        [$content] = $this->edit($contents, "frist line here\nsecnod line here", 'x');

        self::assertStringContainsString('Did you mean lines 1-2 (', $content);
    }

    public function testNothingCloseSaysSoInsteadOfInventingAWindow(): void
    {
        [$content] = $this->edit("alpha\nbeta\n", 'completely different text here', 'x');

        self::assertStringNotContainsString('Did you mean', $content);
        self::assertStringContainsString('Nothing in the file is close to old_string', $content);
    }

    public function testWindowsAreBoundedInCountLinesAndWidth(): void
    {
        $block = implode("\n", array_map(static fn(int $i): string => "statement_number_{$i}();", range(1, 20)));
        $wide = str_repeat('w', 500);
        $contents = '';
        for ($copy = 0; $copy < 5; $copy++) {
            $contents .= $block . "\n" . $wide . $copy . "\n\n";
        }
        $old = str_replace('statement_number_', 'statement_numbr_', $block) . "\n" . $wide;

        $message = EditFailureHints::notFound($contents, $old, 'x', 'f.php');

        self::assertSame(EditFailureHints::MAX_WINDOWS, substr_count($message, 'Did you mean lines'));
        self::assertSame(EditFailureHints::MAX_WINDOWS, substr_count($message, '… 9 more lines'));
        foreach (explode("\n", $message) as $line) {
            self::assertLessThanOrEqual(EditFailureHints::MAX_LINE_BYTES + 16, strlen($line), 'every shown line is clipped');
        }
    }

    public function testLineAtIsOneBased(): void
    {
        self::assertSame(1, EditFailureHints::lineAt("a\nb\nc", 0));
        self::assertSame(3, EditFailureHints::lineAt("a\nb\nc", 4));
    }
}
