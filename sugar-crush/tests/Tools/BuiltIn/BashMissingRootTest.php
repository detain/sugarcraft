<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\PathJail;
use SugarCraft\Crush\Agents\PathJailConfig;
use SugarCraft\Crush\Tools\BuiltIn\Bash;

/**
 * Audit F-E3: when Bash's root is gone, NO part of the command runs.
 *
 * The old prefix was `cd ROOT && COMMAND`. `&&` binds tighter than `;`, so
 * `cd ROOT && true; pwd` is `(cd ROOT && true); pwd` — with ROOT deleted the
 * `pwd` still ran, in the PHP process's own cwd (the main checkout, for an
 * isolated teammate whose worktree was removed). The probe below is exactly
 * that shape, plus a marker that would only print if the shell carried on.
 *
 * @see Bash
 */
final class BashMissingRootTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sugarcrush_bash_gone_' . uniqid((string) getmypid(), true);
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testDeletedRootRunsNothingAfterASemicolon(): void
    {
        $bash = new Bash($this->root);
        rmdir($this->root);

        $result = $bash->execute(['id' => 'call_gone', 'command' => 'true; pwd; echo ESCAPED']);

        self::assertTrue($result->isError(), 'a vanished root must fail the call');
        self::assertStringNotContainsString('ESCAPED', $result->content());
        self::assertStringNotContainsString((string) getcwd(), $result->content());
        self::assertStringContainsString($this->root, $result->content(), "bash's cd error names the missing root");
    }

    public function testDeletedWorktreeJailRootRunsNothingAfterASemicolon(): void
    {
        $bash = new Bash(null, new PathJail($this->root, new PathJailConfig()));
        rmdir($this->root);

        $result = $bash->execute(['id' => 'call_gone_jail', 'command' => 'true; pwd; echo ESCAPED']);

        self::assertTrue($result->isError());
        self::assertStringNotContainsString('ESCAPED', $result->content());
        self::assertStringNotContainsString((string) getcwd(), $result->content());
    }

    public function testLiveRootStillRunsEveryListElementInsideIt(): void
    {
        $result = (new Bash($this->root))->execute(['id' => 'call_live', 'command' => 'false; pwd; echo REACHED']);

        self::assertFalse($result->isError(), 'the last command decides the exit status');
        self::assertSame(realpath($this->root) . "\nREACHED", trim($result->content()));
    }

    public function testEmptyCommandIsANoOpNotASyntaxError(): void
    {
        $result = (new Bash($this->root))->execute(['id' => 'call_empty', 'command' => '']);

        self::assertFalse($result->isError());
        self::assertSame('', trim($result->content()));
    }

    public function testCommandOpeningWithACommentDoesNotSwallowTheGuard(): void
    {
        $result = (new Bash($this->root))->execute(['id' => 'call_comment', 'command' => "# leading comment\npwd"]);

        self::assertFalse($result->isError());
        self::assertSame(realpath($this->root), trim($result->content()));
    }
}
