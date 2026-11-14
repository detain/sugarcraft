<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;

/**
 * Verb classification and budget arithmetic (candy-git-091, candy-git-121) -
 * pure units, no spawn.
 */
final class GitBudgetTest extends TestCase
{
    public function testVerbOfSkipsLeadingGlobalFlags(): void
    {
        $this->assertSame('status', Git::verbOf(['--no-optional-locks', 'status', '--porcelain=v1', '-b']));
        $this->assertSame('diff', Git::verbOf(['--no-optional-locks', 'diff', '--no-color', '--', 'p']));
        $this->assertSame('checkout', Git::verbOf(['checkout', '-b', 'x']));
        $this->assertSame('', Git::verbOf([]));
        $this->assertSame('', Git::verbOf(['--version']));
    }

    public function testMutatingClassificationNamesTheStateRewriters(): void
    {
        foreach (['add', 'checkout', 'commit', 'reset', 'restore', 'stash', 'worktree', 'merge', 'revert'] as $verb) {
            $this->assertTrue(Git::isMutatingVerb($verb), $verb . ' rewrites state');
        }
        foreach (['status', 'diff', 'log', 'show', 'rev-parse'] as $verb) {
            $this->assertFalse(Git::isMutatingVerb($verb), $verb . ' is a read');
        }
    }

    public function testBudgetForSplitsReadsFromMutators(): void
    {
        $git = new Git('/tmp', 3.0, 9.0);

        $this->assertSame(3.0, $git->budgetFor(['--no-optional-locks', 'status', '--porcelain=v1', '-b']));
        $this->assertSame(3.0, $git->budgetFor(['--no-optional-locks', 'diff', '--no-color', '--', 'p']));
        $this->assertSame(9.0, $git->budgetFor(['checkout', '-b', 'feature']));
        $this->assertSame(9.0, $git->budgetFor(['add', '-A']));
    }

    public function testTheMutatorTimeoutMessagePointsAtRepair(): void
    {
        $read = Git::timeoutMessage(false, 30.0);
        $this->assertStringContainsString('command timed out', $read);
        $this->assertStringContainsString('30s', $read);
        $this->assertStringNotContainsString('{seconds}', $read);

        $mutator = Git::timeoutMessage(true, 120.0);
        $this->assertStringContainsString('mutator timed out', $mutator);
        $this->assertStringContainsString('index.lock', $mutator, 'the caution names the repair');
        $this->assertStringNotContainsString('{seconds}', $mutator);
    }

    public function testTheReadLanesCarryTheOptionalLocksFlag(): void
    {
        // candy-git-091: status and diff must ask git not to take the
        // optional index lock; the guard matcher skips leading flags via
        // verbOf(), so the prefix cannot blind the filter/merge guards.
        $src = (string) file_get_contents((new \ReflectionClass(Git::class))->getFileName());
        $this->assertStringContainsString("['--no-optional-locks', 'status',", $src);
        $this->assertStringContainsString("['--no-optional-locks', 'diff',", $src);
        $this->assertSame('status', Git::verbOf(['--no-optional-locks', 'status', '--porcelain=v1', '-b']));
        $this->assertSame('diff', Git::verbOf(['--no-optional-locks', 'diff', '--no-color', '--no-ext-diff', '--', 'p']));
    }
}
