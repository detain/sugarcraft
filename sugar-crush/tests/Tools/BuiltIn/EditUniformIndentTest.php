<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\Edit\EditMatcher;

/**
 * Audit 0.11: an old_string that differs from the file only by ONE indentation
 * shift is applied with new_string shifted the same way, and the result says
 * so. Anything less uniform falls through to the miss diagnostics.
 *
 * @see EditMatcher
 */
final class EditUniformIndentTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_editindent_' . bin2hex(random_bytes(6));
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
    private function edit(string $contents, string $old, string $new): array
    {
        $path = $this->dir . '/target.php';
        file_put_contents($path, $contents);
        $result = (new Edit())->execute(['file_path' => $path, 'old_string' => $old, 'new_string' => $new]);

        return [$result->content(), $result->isError(), (string) file_get_contents($path)];
    }

    public function testAnUnderIndentedBlockIsAppliedAtTheFilesIndentation(): void
    {
        $contents = "class A\n{\n    public function f()\n    {\n        return 1;\n    }\n}\n";

        [$content, $error, $after] = $this->edit(
            $contents,
            "public function f()\n{\n    return 1;\n}",
            "public function f()\n{\n    return 2;\n}",
        );

        self::assertFalse($error, $content);
        self::assertSame("class A\n{\n    public function f()\n    {\n        return 2;\n    }\n}\n", $after);
        self::assertStringContainsString('re-indenting it from no indentation to 4 spaces', $content);
    }

    public function testAnOverIndentedBlockIsShiftedLeft(): void
    {
        $contents = "if (x) {\n  y();\n  z();\n}\n";

        [$content, $error, $after] = $this->edit($contents, "      y();\n      z();", "      y();\n\n      w();");

        self::assertFalse($error, $content);
        self::assertSame("if (x) {\n  y();\n\n  w();\n}\n", $after);
        self::assertStringContainsString('from 6 spaces to 2 spaces', $content);
    }

    public function testTabsAreTheFilesIndentationWhenTheFileUsesTabs(): void
    {
        [$content, $error, $after] = $this->edit("{\n\tone();\n\ttwo();\n}\n", "one();\ntwo();", "one();\nthree();");

        self::assertFalse($error, $content);
        self::assertSame("{\n\tone();\n\tthree();\n}\n", $after);
        self::assertStringContainsString('to 1 tab', $content);
    }

    public function testBlankLinesMatchWhateverWhitespaceTheFileHasOnThem(): void
    {
        [, $error, $after] = $this->edit("  a();\n  \n  b();\n", "a();\n\nb();", "a();\n\nc();");

        self::assertFalse($error);
        self::assertSame("  a();\n\n  c();\n", $after);
    }

    public function testAnExactMatchNeedsNoNote(): void
    {
        [$content, $error] = $this->edit("  a();\n", '  a();', '  b();');

        self::assertFalse($error);
        self::assertStringNotContainsString('re-indenting', $content);
    }

    public function testTwoShiftedCandidatesAreAmbiguousAndNothingIsWritten(): void
    {
        $contents = "  a();\n  b();\n    a();\n    b();\n";

        [$content, $error, $after] = $this->edit($contents, " a();\n b();", " x();\n y();");

        self::assertTrue($error);
        self::assertSame($contents, $after);
        self::assertStringContainsString('old_string not found', $content);
    }

    public function testANonUniformShiftIsNotGuessed(): void
    {
        // The file's second line is indented one more than old_string's relative layout.
        $contents = "  a();\n      b();\n";

        [$content, $error, $after] = $this->edit($contents, "a();\n  b();", "a();\n  c();");

        self::assertTrue($error);
        self::assertSame($contents, $after);
        self::assertStringContainsString('Did you mean lines 1-2', $content);
    }

    public function testANewStringShallowerThanTheBlockIsNotReindented(): void
    {
        $contents = "{\n    a();\n    z();\n}\n";

        [, $error, $after] = $this->edit($contents, "  a();\n  z();", "  a();\nb();");

        self::assertTrue($error, 'b() sits shallower than the block, so no single shift places it');
        self::assertSame($contents, $after);
    }

    public function testADigitAfterTheIndentIsNotReadAsABackReference(): void
    {
        $match = EditMatcher::new()->match("x\n    1 + 1;\n    2;\n", "1 + 1;\n2;", "3;\n4;");

        self::assertNotNull($match);
        self::assertSame(EditMatcher::STAGE_UNIFORM_INDENT, $match->stage);
        self::assertSame("    1 + 1;\n    2;", $match->oldString);
        self::assertSame("    3;\n    4;", $match->newString);
    }

    public function testExactWinsAndReportsEveryOccurrence(): void
    {
        $match = EditMatcher::new()->match("a\na\n", 'a', 'b');

        self::assertNotNull($match);
        self::assertTrue($match->isExact());
        self::assertSame(2, $match->count);
        self::assertNull($match->note);
    }
}
