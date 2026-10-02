<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\IgnoreRules;

/**
 * Audit F-T5 residual (R5): an ignore rule too costly to evaluate is resolved
 * toward HIDING, and {@see IgnoreRules::undecidablePatterns()} recorded it —
 * but nothing read that list, so Glob and Grep hid the path, counted it as
 * "gitignored", and gave the model no reason to doubt a rule it cannot see.
 * Both tools now append {@see IgnoreRules::undecidableNote()}.
 *
 * THE FIXTURE. A rule of 1,200 `*a` pairs and a closing `*c` is ~2,400 tokens;
 * against a 509-byte root-relative path that is past the 1,000,000 match
 * budget, so the rule is undecidable for the file, while its 254-byte parent
 * directory (~610k) is still decided — and does not match, having no `a`. So
 * exactly one path is hidden, and only because the rule could not be decided:
 * it would not really match (the file name ends in `y`, not `c`).
 */
final class UndecidableIgnoreRuleNoteTest extends TestCase
{
    private string $root = '';
    private string $pattern = '';
    private string $hidden = '';

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/crush-undecidable-note-' . uniqid('', true);
        mkdir($this->root, 0o777, true);

        $this->pattern = str_repeat('*a', 1200) . '*c';
        $dir = $this->root . '/' . str_repeat('x', 254);
        mkdir($dir);
        $this->hidden = $dir . '/' . str_repeat('y', 254);
        file_put_contents($this->hidden, "needle\n");
        file_put_contents($this->root . '/shown.txt', "needle\n");
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testGlobNamesTheUndecidableRuleThatHidAPath(): void
    {
        $this->write('.gitignore', $this->pattern . "\n");

        $content = (new Glob($this->root))->execute(['id' => 'g', 'pattern' => '**/*'])->content();

        self::assertStringContainsString($this->root . '/shown.txt', $content);
        self::assertStringNotContainsString($this->hidden, $content, 'the fixture no longer hides the path');
        self::assertStringContainsString(
            '... [gitignore: 1 ignore rule too costly to evaluate was resolved toward hiding',
            $content,
            'Glob hid a path through an undecidable rule and did not say so',
        );
        self::assertStringContainsString('.gitignore: ' . substr($this->pattern, 0, 40), $content);
        self::assertStringContainsString('Pass include_ignored: true to bypass .gitignore.]', $content);
        self::assertStringNotContainsString($this->pattern, $content, 'a 2,401-byte pattern must be clipped, not quoted');
    }

    public function testGrepNamesTheUndecidableRuleThatHidAHit(): void
    {
        $this->write('.gitignore', $this->pattern . "\n");

        $content = (new Grep($this->root))->execute([
            'id' => 'g',
            'pattern' => 'needle',
            'path' => $this->root,
            'description' => 'find the needle',
        ])->content();

        self::assertStringContainsString('shown.txt', $content);
        self::assertStringNotContainsString($this->hidden, $content, 'the fixture no longer hides the hit');
        self::assertStringContainsString(
            '... [gitignore: 1 ignore rule too costly to evaluate was resolved toward hiding',
            $content,
            'Grep hid a hit through an undecidable rule and did not say so',
        );
        self::assertStringNotContainsString($this->pattern, $content);
    }

    public function testIncludeIgnoredBypassesTheRuleAndTheNoteGoesWithIt(): void
    {
        // The hatch the note names must actually work, and once taken there is
        // nothing to explain.
        $this->write('.gitignore', $this->pattern . "\n");

        $content = (new Glob($this->root))
            ->execute(['id' => 'g', 'pattern' => '**/*', 'include_ignored' => true])
            ->content();

        self::assertStringContainsString($this->hidden, $content);
        self::assertStringNotContainsString('[gitignore:', $content);
    }

    public function testADecidableRuleBringsNoNote(): void
    {
        // The other polarity, through the same tools: an ordinary rule that
        // hides the same file is the project's decision and needs no excuse.
        $this->write('.gitignore', "*y\n");

        $glob = (new Glob($this->root))->execute(['id' => 'g', 'pattern' => '**/*'])->content();
        $grep = (new Grep($this->root))->execute([
            'id' => 'g',
            'pattern' => 'needle',
            'path' => $this->root,
            'description' => 'find the needle',
        ])->content();

        self::assertStringContainsString('[gitignored:', $glob);
        self::assertStringNotContainsString('[gitignore:', $glob);
        self::assertStringContainsString('[gitignored:', $grep);
        self::assertStringNotContainsString('[gitignore:', $grep);
    }

    public function testTheNoteIsBoundedInRulesAndBytesAndStripsControlBytes(): void
    {
        // Five distinct undecidable rules, one carrying an escape sequence and
        // a multibyte character straddling the clip point.
        $rules = [];
        for ($i = 0; $i < 4; $i++) {
            $rules[] = str_repeat('*a', 1000) . '*c' . $i;
        }
        $hostile = "\e[31m" . str_repeat('*a', 30) . '*é' . str_repeat('*a', 1000) . '*c';
        array_unshift($rules, $hostile);
        $this->write('.gitignore', implode("\n", $rules) . "\n");

        $ignore = IgnoreRules::new($this->root);
        $long = str_repeat('x', 300) . '/' . str_repeat('y', 300);
        self::assertTrue($ignore->ignores($this->root . '/' . $long . '/f.txt', false));
        self::assertCount(5, $ignore->undecidablePatterns());

        $note = (string) $ignore->undecidableNote();

        self::assertStringStartsWith('... [gitignore: 5 ignore rules too costly to evaluate were resolved toward hiding', $note);
        self::assertStringContainsString('; and 2 more.', $note);
        self::assertSame(
            IgnoreRules::UNDECIDABLE_NOTE_MAX_RULES,
            substr_count($note, '.gitignore: '),
        );
        self::assertStringNotContainsString("\e", $note, 'a pattern must not carry an escape sequence into the transcript');
        self::assertStringContainsString('?[31m', $note);
        self::assertSame(1, preg_match('//u', $note), 'the clip must land on a UTF-8 boundary');
        self::assertLessThan(
            IgnoreRules::UNDECIDABLE_NOTE_MAX_RULES * (IgnoreRules::UNDECIDABLE_NOTE_MAX_PATTERN_BYTES + 20) + 250,
            strlen($note),
            'the note is repository content and must stay bounded',
        );
        self::assertStringNotContainsString("\n", $note);
    }

    public function testNoUndecidableRuleMeansNoNote(): void
    {
        $this->write('.gitignore', "*.log\n");
        $ignore = IgnoreRules::new($this->root);
        $ignore->ignores($this->root . '/a.log', false);

        self::assertNull($ignore->undecidableNote());
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->root . '/' . $relative, $contents);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
