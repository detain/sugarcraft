<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\IgnoreRules;

/**
 * S-T06: IgnoreRules is an adapter over sugar-ignore (whose suite owns the
 * gitignore semantics). These pins hold what the adapter itself adds: the
 * lazy per-directory ignore-file feed and the undecidable-rule memory that
 * must survive every engine rebuild that feed causes.
 */
final class IgnoreRulesAdapterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ignore-adapter-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/sub/deep', 0o777, true);
        mkdir($this->root . '/.git/info', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ([
            '/sub/deep/.gitignore', '/sub/.gitignore', '/.gitignore', '/.git/info/exclude',
        ] as $file) {
            @unlink($this->root . $file);
        }
        @rmdir($this->root . '/sub/deep');
        @rmdir($this->root . '/sub');
        @rmdir($this->root . '/.git/info');
        @rmdir($this->root . '/.git');
        @rmdir($this->root);
    }

    public function testUndecidableRulesSurviveTheRebuildsALaterNestedFileCauses(): void
    {
        $pattern = str_repeat('*a', 1000) . '*c';
        file_put_contents($this->root . '/.gitignore', $pattern . "\n");
        file_put_contents($this->root . '/sub/.gitignore', "*.tmp\n");
        $rules = IgnoreRules::new($this->root);

        $long = str_repeat('x', 300) . '/' . str_repeat('y', 300);
        $this->assertTrue($rules->ignores($this->root . '/' . $long . '/one.txt', false));
        $this->assertSame(['.gitignore: ' . $pattern], $rules->undecidablePatterns());

        // Reading sub/.gitignore rebuilds the engine; the earlier finding must not be forgotten.
        $this->assertTrue($rules->ignores($this->root . '/sub/x.tmp', false));
        $this->assertSame(['.gitignore: ' . $pattern], $rules->undecidablePatterns());
        $this->assertStringContainsString('.gitignore: ', (string) $rules->undecidableNote());
    }

    public function testInfoExcludeRanksBelowEveryGitignoreAndDeepFilesOverrideShallow(): void
    {
        file_put_contents($this->root . '/.git/info/exclude', "*.out\n");
        file_put_contents($this->root . '/sub/.gitignore', "!keep.out\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/x.out', false));
        $this->assertTrue($rules->ignores($this->root . '/sub/other.out', false));
        $this->assertFalse($rules->ignores($this->root . '/sub/keep.out', false));
    }

    public function testAHonouredFlagOffAnswersNothingIgnoredWithoutReadingAnything(): void
    {
        file_put_contents($this->root . '/.gitignore', "*\n");

        $this->assertFalse(IgnoreRules::new($this->root)->withGitignore(false)->ignores($this->root . '/a.txt', false));
    }
}
