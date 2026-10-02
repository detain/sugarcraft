<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Agents\PathJailConfig;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;

/**
 * Audit F-J5 (lead 8): Bash, Read, Edit and Write accept a sub-agent's
 * worktree jail, and Glob and Grep did not, so a sub-agent built on the main
 * root would search the MAIN checkout instead of its own worktree. Each tool
 * here is constructed exactly that way — main root plus worktree jail — and
 * must answer from the worktree only.
 */
final class WorktreeJailedSearchToolsTest extends TestCase
{
    private string $base;
    private string $main;
    private string $worktree;

    protected function setUp(): void
    {
        $this->base = (string) realpath(sys_get_temp_dir()) . '/sc_wt_search_' . bin2hex(random_bytes(4));
        $this->main = $this->base . '/main';
        $this->worktree = $this->base . '/main/.worktrees/agent';
        mkdir($this->worktree, 0o755, true);
        file_put_contents($this->main . '/main-only.txt', "needle in the main checkout\n");
        file_put_contents($this->worktree . '/worktree-only.txt', "needle in the worktree\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->worktree . '/worktree-only.txt');
        @unlink($this->main . '/main-only.txt');
        @rmdir($this->worktree);
        @rmdir($this->main . '/.worktrees');
        @rmdir($this->main);
        @rmdir($this->base);
    }

    public function testGlobListsTheWorktreeNotTheMainCheckout(): void
    {
        $tool = new Glob($this->main, worktreeJail: $this->jail());

        $result = $tool->execute(['pattern' => '*.txt', 'path' => '.', 'description' => 'list']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('worktree-only.txt', $result->content());
        $this->assertStringNotContainsString('main-only.txt', $result->content());
    }

    public function testGlobRefusesAMainCheckoutPathOutsideTheWorktree(): void
    {
        $tool = new Glob($this->main, worktreeJail: $this->jail());

        $result = $tool->execute(['pattern' => '*.txt', 'path' => $this->main, 'description' => 'list']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside workspace root', $result->content());
    }

    public function testGrepSearchesTheWorktreeNotTheMainCheckout(): void
    {
        $tool = new Grep($this->main, worktreeJail: $this->jail());

        $result = $tool->execute(['pattern' => 'needle', 'path' => '.', 'description' => 'search']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('needle in the worktree', $result->content());
        $this->assertStringNotContainsString('needle in the main checkout', $result->content());
    }

    public function testGrepRefusesAMainCheckoutPathOutsideTheWorktree(): void
    {
        $tool = new Grep($this->main, worktreeJail: $this->jail());

        $result = $tool->execute(['pattern' => 'needle', 'path' => $this->main, 'description' => 'search']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside workspace root', $result->content());
    }

    public function testWithoutAJailTheWorkspaceRootStillApplies(): void
    {
        $result = (new Grep($this->main))->execute(['pattern' => 'needle', 'path' => '.', 'description' => 'search']);

        $this->assertStringContainsString('needle in the main checkout', $result->content());
    }

    private function jail(): AgentPathJail
    {
        return new AgentPathJail($this->worktree, new PathJailConfig());
    }
}
