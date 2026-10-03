<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 3.I-1 at the tool boundary: the matcher chain's verdicts as the
 * model receives them, and `edits[]` — several replacements in one call,
 * each applied to the text the previous ones produced, written all or nothing.
 */
final class EditMultiEditTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_multiedit_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->path = $this->dir . '/target.php';
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

    public function testEditsApplyInOrderEachToThePreviousResult(): void
    {
        file_put_contents($this->path, "alpha\nbeta\ngamma\n");

        $result = $this->edit('alpha', 'ALPHA', [
            ['old_string' => 'ALPHA', 'new_string' => 'first'],
            ['old_string' => "beta\ngamma", 'new_string' => 'rest'],
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame("first\nrest\n", file_get_contents($this->path));
        self::assertStringContainsString('(3 edits applied)', $result->content());
        self::assertNotNull($result->diff(), 'one diff for the whole call');
    }

    public function testAFailingEditWritesNothingAndNamesWhichEditFailed(): void
    {
        $original = "one\ntwo\nthree\n";
        file_put_contents($this->path, $original);

        $result = $this->edit('one', 'ONE', [
            ['old_string' => 'two', 'new_string' => 'TWO'],
            ['old_string' => 'not in the file', 'new_string' => 'x'],
        ]);

        self::assertTrue($result->isError());
        self::assertSame($original, file_get_contents($this->path), 'edits 1 and 2 were written although edit 3 failed');
        self::assertStringStartsWith('Error in edit 3 of 3 (none of the 3 edits was written; line numbers below are in the text as edits 1-2 left it): old_string not found', $result->content());
    }

    public function testTheFirstEditFailingSaysSoWithoutALineNumberCaveat(): void
    {
        file_put_contents($this->path, "x\n");

        $result = $this->edit('nope', 'y', [['old_string' => 'x', 'new_string' => 'z']]);

        self::assertTrue($result->isError());
        self::assertStringStartsWith('Error in edit 1 of 2 (none of the 2 edits was written): old_string not found', $result->content());
    }

    public function testEachFuzzyEditReportsTheStageThatMatchedIt(): void
    {
        file_put_contents($this->path, "if (\$a) {\n        run();\n}\nlog(\$a,   \$b);\n");

        $result = $this->edit("if (\$a) {\n    run();\n}", "if (\$a) {\n    walk();\n}", [
            ['old_string' => 'log($a, $b)', 'new_string' => 'log($b, $a)'],
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame("if (\$a) {\n    walk();\n}\nlog(\$b, \$a);\n", file_get_contents($this->path));
        self::assertStringContainsString('(edit 1: line-trimmed match:', $result->content());
        self::assertStringContainsString('(edit 2: whitespace-normalised match:', $result->content());
    }

    public function testASingleFuzzyEditNamesItsStageWithoutAnEditNumber(): void
    {
        file_put_contents($this->path, "say \u{201C}hi\u{201D};\n");

        $result = $this->edit('say "hi";', 'say "bye";');

        self::assertFalse($result->isError(), $result->content());
        self::assertSame("say \"bye\";\n", file_get_contents($this->path));
        self::assertStringContainsString('(unicode-normalised match:', $result->content());
        self::assertStringNotContainsString('edits applied', $result->content());
    }

    public function testReplaceAllWorksInsideEditsForExactMatchesOnly(): void
    {
        file_put_contents($this->path, "a a a\n  b();\n    b();\n");

        $exact = $this->edit('a a a', 'z', [['old_string' => 'b();', 'new_string' => 'c();', 'replace_all' => true]]);
        self::assertFalse($exact->isError(), $exact->content());
        self::assertSame("z\n  c();\n    c();\n", file_get_contents($this->path));

        $before = (string) file_get_contents($this->path);
        $fuzzy = $this->edit('z', 'y', [['old_string' => "\tc();\n", 'new_string' => "\td();\n", 'replace_all' => true]]);
        self::assertTrue($fuzzy->isError(), 'replace_all must not license a fuzzy stage to edit several places');
        self::assertSame($before, file_get_contents($this->path));
        self::assertStringContainsString('replace_all included', $fuzzy->content());
    }

    public function testADisproportionateMatchIsRefusedAndTheFileLeftAlone(): void
    {
        $original = implode("\n", ['begin', 'l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7', 'x1', 'x2', 'x3', 'end', '']);
        file_put_contents($this->path, $original);

        $result = $this->edit(implode("\n", ['begin', 'l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7', 'end']), 'gone');

        self::assertTrue($result->isError());
        self::assertSame($original, file_get_contents($this->path));
        self::assertStringStartsWith('Error: old_string not found as written in ' . $this->path . '; by its first and last lines', $result->content());
        self::assertStringEndsWith('file left unchanged', $result->content());
    }

    public function testAMalformedEditsListIsRejectedBeforeTheFileIsTouched(): void
    {
        file_put_contents($this->path, "keep\n");

        foreach ([
            'not a list' => [['old_string' => 'keep', 'new_string' => 'x'], 'edits must be a list', ['k' => 1]],
            'missing new_string' => [[['old_string' => 'keep']], 'edit 2 needs string old_string and new_string', null],
            'empty old_string' => [[['old_string' => '', 'new_string' => 'x']], "edit 2's old_string cannot be empty", null],
        ] as $case => [$edits, $message, $override]) {
            $result = (new Edit($this->dir))->execute([
                'file_path' => $this->path,
                'old_string' => 'keep',
                'new_string' => 'changed',
                'edits' => $override ?? $edits,
            ]);

            self::assertTrue($result->isError(), $case);
            self::assertStringContainsString($message, $result->content(), $case);
            self::assertSame("keep\n", file_get_contents($this->path), $case);
        }
    }

    public function testTheSchemaOffersEditsWithoutLooseningTheTopLevelPair(): void
    {
        $schema = (new Edit())->inputSchema();

        self::assertSame('array', $schema['properties']['edits']['type']);
        self::assertSame(['old_string', 'new_string'], $schema['properties']['edits']['items']['required']);
        self::assertContains('old_string', $schema['required']);
        self::assertContains('new_string', $schema['required']);
        self::assertNotContains('edits', $schema['required']);
    }

    /**
     * @param list<array<string, mixed>>|null $edits
     */
    private function edit(string $old, string $new, ?array $edits = null): ToolResult
    {
        $args = ['file_path' => $this->path, 'old_string' => $old, 'new_string' => $new, 'description' => 'x'];
        if ($edits !== null) {
            $args['edits'] = $edits;
        }

        return (new Edit($this->dir))->execute($args);
    }
}
