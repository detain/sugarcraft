<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\RepoMap\CtagsSymbolExtractor;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\BuiltIn\RepoMapTool;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;

/**
 * Roadmap 5.5-4: the `RepoMap` tool over the symbol extractors, the tag
 * cache, the symbol graph and the budgeted renderer.
 */
final class RepoMapToolTest extends TestCase
{
    private string $base;
    private string $repo;
    private string $cache;

    protected function setUp(): void
    {
        $this->base = \sys_get_temp_dir() . '/sc_repomap_' . \bin2hex(\random_bytes(6));
        $this->repo = $this->base . '/repo';
        $this->cache = $this->base . '/cache/tags.sqlite';
        \mkdir($this->repo . '/src', 0o700, true);
        \mkdir($this->repo . '/ignored', 0o700, true);
        \mkdir($this->base . '/outside', 0o700, true);

        $this->write('src/Core.php', "<?php\nfinal class Core\n{\n    public function run(): void {}\n}\n");
        $this->write('src/Alpha.php', "<?php\nfinal class Alpha\n{\n    public function go(Core \$c): void { \$c->run(); }\n}\n");
        $this->write('src/Beta.php', "<?php\nfinal class Beta\n{\n    public function go(Core \$c): void { \$c->run(); }\n}\n");
        $this->write('ignored/Hidden.php', "<?php\nfinal class HiddenByGitignore {}\n");
        $this->write('.gitignore', "ignored/\n");
        $this->write('tool.py', "class PyThing:\n    def act(self):\n        return Core()\n");
        \file_put_contents($this->base . '/outside/Secret.php', "<?php\nfinal class OutsideSecret {}\n");
        \symlink($this->base . '/outside/Secret.php', $this->repo . '/src/Linked.php');

        $this->git('init', '-q');
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    public function testTheCatalogDeclaresRepoMapARead(): void
    {
        self::assertSame(ToolPermissionClass::Read, ToolCatalog::permissionOf(RepoMapTool::NAME));

        foreach ([PermissionMode::Default, PermissionMode::Plan, PermissionMode::DontAsk] as $mode) {
            self::assertSame(
                PermissionDecision::Allow,
                (new PermissionGate($mode, [], new SafetyClassifier()))->evaluate(new ToolCall(RepoMapTool::NAME, [])),
                $mode->value . ' must run a read unasked',
            );
        }
    }

    public function testTheMapOutlinesTheReferencedDefinitionsUnderTheirFile(): void
    {
        $result = $this->tool()->execute(['max_tokens' => 1024]);

        self::assertFalse($result->isError(), $result->content());
        $content = $result->content();
        self::assertStringContainsString("src/Core.php:\n⋮\n│final class Core", $content);
        self::assertStringContainsString('│    public function run(): void {}', $content);
        self::assertStringStartsWith('Repo map of 3 code files (budget 1024 tokens); PHP only', $content);
    }

    public function testGitignoredFilesAndSymlinksOutOfTheTreeAreNeverMapped(): void
    {
        $content = $this->tool()->execute(['max_tokens' => 8192])->content();

        self::assertStringNotContainsString('HiddenByGitignore', $content);
        self::assertStringNotContainsString('ignored/', $content);
        self::assertStringNotContainsString('OutsideSecret', $content);
        self::assertStringNotContainsString('Linked.php', $content);
    }

    public function testFocusFilesAreLeftOutAndRankTheirNeighbourhood(): void
    {
        $content = $this->tool()->execute(['focus_files' => ['./src/Alpha.php']])->content();

        self::assertStringNotContainsString('src/Alpha.php', $content);
        self::assertStringContainsString('src/Core.php:', $content);
    }

    public function testWithoutCtagsOnlyPhpIsMappedAndTheResultSaysSo(): void
    {
        $content = $this->tool(ctags: $this->base . '/no-ctags-here')->execute([])->content();

        self::assertStringNotContainsString('tool.py', $content);
        self::assertStringContainsString('PHP only (install Universal Ctags', $content);
    }

    public function testWithCtagsOtherLanguagesJoinTheGraph(): void
    {
        $json = \json_encode([
            '_type' => 'tag', 'name' => 'PyThing', 'path' => \realpath($this->repo) . '/tool.py', 'line' => 1, 'kind' => 'class',
        ]);
        $content = $this->tool(ctags: $this->fakeCtags((string) $json))->execute(['max_tokens' => 8192])->content();

        self::assertStringContainsString("tool.py:\n│class PyThing:", $content);
        self::assertStringContainsString('non-PHP files via Universal Ctags', $content);
    }

    public function testTheBudgetIsClampedAndHonoured(): void
    {
        $small = $this->tool()->execute(['max_tokens' => 1])->content();

        self::assertStringContainsString('(budget ' . RepoMapTool::MIN_MAX_TOKENS . ' tokens)', $small);
        self::assertStringContainsString('(budget ' . RepoMapTool::MAX_MAX_TOKENS . ' tokens)', $this->tool()->execute(['max_tokens' => 10 ** 9])->content());
    }

    public function testTheTagCacheLivesWhereItWasToldAndIsReusedOnTheNextCall(): void
    {
        $first = $this->tool()->execute([])->content();
        self::assertFileExists($this->cache);

        self::assertSame($first, $this->tool()->execute([])->content());
    }

    public function testADirectoryThatIsNotAGitCheckoutIsRefusedWithAWayForward(): void
    {
        $plain = $this->base . '/plain';
        \mkdir($plain);
        $result = (new RepoMapTool($plain, CtagsSymbolExtractor::new()->withBinary($this->base . '/none'), $this->cache))->execute([]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('git ls-files', $result->content());
        self::assertStringContainsString('Glob and Grep', $result->content());
    }

    public function testAnUnrootedToolRefuses(): void
    {
        self::assertTrue((new RepoMapTool())->execute([])->isError());
    }

    public function testTheHeartbeatIsStruckWhileItWorks(): void
    {
        $beats = 0;
        $this->tool()->executeWithHeartbeat([], static function () use (&$beats): void {
            $beats++;
        });

        self::assertGreaterThanOrEqual(1, $beats);
    }

    // ---- fixtures --------------------------------------------------------

    private function tool(?string $ctags = null): RepoMapTool
    {
        return new RepoMapTool(
            $this->repo,
            CtagsSymbolExtractor::new()->withBinary($ctags ?? $this->base . '/absent-ctags'),
            $this->cache,
        );
    }

    private function write(string $rel, string $body): void
    {
        \file_put_contents($this->repo . '/' . $rel, $body);
    }

    private function git(string ...$args): void
    {
        $cmd = 'git -C ' . \escapeshellarg($this->repo) . ' ' . \implode(' ', \array_map('escapeshellarg', $args)) . ' 2>&1';
        \exec($cmd, $out, $code);
        self::assertSame(0, $code, \implode("\n", $out));
    }

    private function fakeCtags(string $json): string
    {
        $path = $this->base . '/fake-ctags';
        \file_put_contents($path, '#!' . \PHP_BINARY . "\n<?php\n"
            . 'if (($argv[1] ?? "") === "--version") { echo "Universal Ctags 6.0.0\n  Optional compiled features: +json\n"; exit(0); }' . "\n"
            . 'echo ' . \var_export($json, true) . ";\n");
        \chmod($path, 0o700);

        return $path;
    }

    private function remove(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);

            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }
}
