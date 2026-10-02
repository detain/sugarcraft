<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\LspTool;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\WebFetch;
use SugarCraft\Crush\Tools\BuiltIn\Write;
use SugarCraft\Crush\Tools\Tool;

/**
 * Audit F-J5 residual: Glob, Grep and Lsp gained an optional worktree jail,
 * but `EngineBackend::withWorktreeRoot()` — the one place that learns a
 * sub-agent's worktree — only registered the Bash escape hook. The tool list
 * it holds was built on the MAIN checkout, so every path-resolving tool kept
 * answering from (and writing to) the main checkout. These pin that the
 * worktree reaches the tools themselves, on a copy, leaving the receiver's
 * tools exactly as they were.
 */
final class EngineBackendWorktreeJailTest extends TestCase
{
    private string $base;
    private string $main;
    private string $worktree;

    protected function setUp(): void
    {
        $this->base = (string) realpath(sys_get_temp_dir()) . '/sc_eb_wt_' . bin2hex(random_bytes(4));
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

    public function testEveryPathResolvingToolIsConfinedToTheWorktree(): void
    {
        $tools = $this->mainCheckoutTools();
        $backend = EngineBackend::new(new EchoProvider(), 'm')->withTools($tools);

        $jailed = $backend->withWorktreeRoot($this->worktree)->tools();

        $this->assertCount(count($tools), $jailed);
        foreach ($jailed as $i => $tool) {
            // Named, not asked of the interface: a backend that re-jailed
            // nothing must not pass by treating every tool as exempt.
            if ($tools[$i] instanceof WebFetch) {
                $this->assertSame($tools[$i], $tool, 'a tool that resolves no workspace path is kept as it is');
                continue;
            }
            $this->assertNotSame($tools[$i], $tool, $tool->name() . ' must be a copy, not the shared instance');
            $this->assertSame($this->worktree, self::jailOf($tool)?->root(), $tool->name() . ' was not re-jailed');
        }
    }

    public function testTheReceiversToolsKeepTheirOwnConfinement(): void
    {
        $tools = $this->mainCheckoutTools();
        $parent = EngineBackend::new(new EchoProvider(), 'm')->withTools($tools);

        $parent->withWorktreeRoot($this->worktree);

        foreach ($parent->tools() as $i => $tool) {
            $this->assertSame($tools[$i], $tool);
            if ($tool instanceof AcceptsWorktreeJail) {
                $this->assertNull(self::jailOf($tool), $tool->name() . ' gained the sub-agent\'s jail in the parent');
            }
        }
    }

    public function testAReJailedToolCarriesEveryOtherCollaborator(): void
    {
        foreach ($this->mainCheckoutTools() as $tool) {
            if (!$tool instanceof AcceptsWorktreeJail) {
                continue;
            }
            $before = (fn(): array => get_object_vars($this))->call($tool);
            $after = (fn(): array => get_object_vars($this))->call(
                $tool->withWorktreeJail(new AgentPathJail($this->worktree, new \SugarCraft\Crush\Agents\PathJailConfig())),
            );
            unset($before['worktreeJail'], $after['worktreeJail']);

            $this->assertSame($before, $after, $tool->name() . ' dropped a field while re-jailing');
        }
    }

    public function testJailedGlobAndGrepAnswerFromTheWorktree(): void
    {
        $backend = EngineBackend::new(new EchoProvider(), 'm')
            ->withTools([new Glob($this->main), new Grep($this->main)])
            ->withWorktreeRoot($this->worktree);
        [$glob, $grep] = $backend->tools();

        $listed = $glob->execute(['pattern' => '*.txt', 'path' => '.', 'description' => 'list']);
        $this->assertFalse($listed->isError(), $listed->content());
        $this->assertStringContainsString('worktree-only.txt', $listed->content());
        $this->assertStringNotContainsString('main-only.txt', $listed->content());

        $found = $grep->execute(['pattern' => 'needle', 'path' => '.']);
        $this->assertFalse($found->isError(), $found->content());
        $this->assertStringContainsString('worktree-only.txt', $found->content());
        $this->assertStringNotContainsString('main-only.txt', $found->content());

        $refused = $grep->execute(['pattern' => 'needle', 'path' => $this->main . '/main-only.txt']);
        $this->assertTrue($refused->isError(), 'a main-checkout path outside the worktree must be refused');
    }

    /**
     * `withoutHooks()` opts out of the HOOK chain; it is not a request to
     * search the wrong tree, so the tools are confined all the same.
     */
    public function testToolsAreJailedEvenWithHooksDisabled(): void
    {
        $backend = EngineBackend::new(new EchoProvider(), 'm')
            ->withTools([new Glob($this->main)])
            ->withoutHooks()
            ->withWorktreeRoot($this->worktree);

        $this->assertSame($this->worktree, self::jailOf($backend->tools()[0])?->root());
    }

    /**
     * The census: a built-in that takes a `worktreeJail` at construction must
     * also accept one afterwards, or withWorktreeRoot() silently skips it —
     * exactly how Glob, Grep and Lsp were left on the main checkout.
     */
    public function testEveryBuiltInWithAWorktreeJailParameterAcceptsOneAfterConstruction(): void
    {
        $dir = dirname(__DIR__, 2) . '/src/Tools/BuiltIn';
        $takers = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $class = 'SugarCraft\\Crush\\Tools\\BuiltIn\\' . basename($file, '.php');
            $ctor = (new \ReflectionClass($class))->getConstructor();
            foreach ($ctor?->getParameters() ?? [] as $param) {
                if ($param->getName() === 'worktreeJail') {
                    $takers[] = basename($file, '.php');
                    $this->assertTrue(
                        is_subclass_of($class, AcceptsWorktreeJail::class),
                        "{$class} takes a worktreeJail but does not implement AcceptsWorktreeJail",
                    );
                }
            }
        }

        sort($takers);
        $this->assertSame(['Bash', 'Edit', 'Glob', 'Grep', 'LspTool', 'Read', 'Write'], $takers);
    }

    /**
     * @return list<Tool>
     */
    private function mainCheckoutTools(): array
    {
        return [
            new Glob($this->main),
            new Grep($this->main),
            new LspTool(null, $this->main),
            new Read($this->main),
            new Edit($this->main),
            new Write($this->main),
            new Bash($this->main),
            new WebFetch(),
        ];
    }

    private static function jailOf(Tool $tool): ?AgentPathJail
    {
        return (new \ReflectionProperty($tool, 'worktreeJail'))->getValue($tool);
    }
}
