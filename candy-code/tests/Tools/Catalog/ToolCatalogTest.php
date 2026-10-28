<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Catalog;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\BuiltIn\BoardPostTool;
use SugarCraft\Code\Tools\BuiltIn\BoardReadTool;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\BuiltIn\McpListTool;
use SugarCraft\Code\Tools\BuiltIn\ScheduleTool;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\CatalogEntry;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\Tool;

/**
 * The built-in tool catalog: discovery from `src/Tools/BuiltIn/`, the
 * per-tool declarations and the refusals that keep an undeclared tool from
 * being silently unreachable.
 */
final class ToolCatalogTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (glob($this->tmp . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tmp);
        }
    }

    private static function context(string $root): ToolBuildContext
    {
        $skills = new SkillRegistry();

        return new ToolBuildContext(
            root: $root,
            loader: new InstructionFileLoader($root),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
        );
    }

    public function testEveryConcreteToolInTheBuiltInDirectoryIsCatalogued(): void
    {
        $files = glob(\dirname(__DIR__, 3) . '/src/Tools/BuiltIn/*.php') ?: [];
        $classes = [];
        foreach ($files as $file) {
            $classes[] = 'SugarCraft\\Code\\Tools\\BuiltIn\\' . basename($file, '.php');
        }
        sort($classes);

        $catalogued = array_map(static fn (CatalogEntry $e): string => $e->class, ToolCatalog::entries());
        sort($catalogued);

        self::assertSame($classes, $catalogued);
    }

    public function testTheDeclaredNameIsTheNameTheToolAnswers(): void
    {
        $root = sys_get_temp_dir();
        $context = self::context($root);
        $built = ToolCatalog::build($context);

        // MCP-7 minted the catalog's first CONDITIONAL builds: the dispatcher
        // pair exists only when a frozen shape actually deferred a server.
        // The roster stays complete — every declared name answers, and every
        // skipped name is skipped by its own fromCatalog(), never by the walk.
        $declared = array_values(array_filter(
            ToolCatalog::built(),
            static function (CatalogEntry $e) use ($context): bool {
                /** @var class-string<BuildsFromCatalog> $class */
                $class = $e->class;

                return $class::fromCatalog($context) !== null;
            },
        ));

        self::assertSame(
            array_map(static fn (CatalogEntry $e): string => $e->name, $declared),
            array_map(static fn (Tool $t): string => $t->name(), $built),
        );
        foreach ($built as $i => $tool) {
            self::assertInstanceOf($declared[$i]->class, $tool);
        }
    }

    /**
     * The pair's half of the conditional-build premise: a context frozen with
     * a LAZY shape gains exactly mcp_list and mcp_call, in that order, after
     * the last ordinary tool; a shape that deferred nothing gains neither.
     */
    public function testTheDispatcherPairRidesOnlyAFrozenLazyShape(): void
    {
        $deferred = new McpTool(
            name: 'heavy',
            description: 'a tool whose schema the floor defers',
            inputSchema: ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            serverName: 'srv',
        );
        $client = new McpClient('/nonexistent/mcp-7-catalog-probe/.mcp.json');
        $lazy = McpToolShape::derive($client, [$deferred], [], 1);
        self::assertTrue($lazy->lazy);

        $names = $this->buildNames(self::withShape($lazy));
        self::assertContains(McpListTool::NAME, $names);
        self::assertContains(McpCallTool::NAME, $names);
        self::assertSame(
            // C-AGT-5.a truth-flip: Schedule (position 41) landed unconditionally
            // AFTER the pair, so the pair no longer occupies the literal tail of
            // the roster — it lands last among the CONDITIONALLY-BUILT pair plus
            // nothing else, and behind every unconditional tool. The pin keeps
            // its meaning (the pair never sneaks in before its trigger) by
            // naming the one unconditional tool that now rides after it.
            [McpListTool::NAME, McpCallTool::NAME, ScheduleTool::NAME],
            \array_slice($names, -3),
            'the dispatcher pair lands in roster order 39 then 40, ahead only of the newest unconditional tool',
        );

        $untriggered = McpToolShape::derive($client, [$deferred], [], 100000);
        self::assertFalse($untriggered->lazy);
        $names = $this->buildNames(self::withShape($untriggered));
        self::assertNotContains(McpListTool::NAME, $names);
        self::assertNotContains(McpCallTool::NAME, $names);
    }

    /**
     * @return list<string>
     */
    private function buildNames(ToolBuildContext $context): array
    {
        return array_map(static fn (Tool $t): string => $t->name(), ToolCatalog::build($context));
    }

    private function withShape(McpToolShape $shape): ToolBuildContext
    {
        $root = sys_get_temp_dir();

        return new ToolBuildContext(
            root: $root,
            loader: new InstructionFileLoader($root),
            skills: new SkillRegistry(),
            skillNudge: SkillPathNudge::new(new SkillRegistry()),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
            mcpShape: $shape,
        );
    }

    public function testTheWireOrderTheModelLearnedIsKept(): void
    {
        self::assertSame(
            ['Bash', 'Read', 'Edit', 'Glob', 'Grep', 'Write', 'WebFetch', 'WebSearch', 'doctor', 'Skill', 'Lsp'],
            \array_slice(array_map(static fn (CatalogEntry $e): string => $e->name, ToolCatalog::built()), 0, 11),
        );
    }

    public function testTaskIsClassifiedButNotBuilt(): void
    {
        self::assertSame(
            [BoardPostTool::class, BoardReadTool::class, TaskTool::class],
            array_map(static fn (CatalogEntry $e): string => $e->class, ToolCatalog::externallyWired()),
        );
        self::assertSame(ToolPermissionClass::Write, ToolCatalog::permissionOf(TaskTool::NAME));
        self::assertNotContains(TaskTool::NAME, array_map(static fn (Tool $t): string => $t->name(), ToolCatalog::build(self::context(sys_get_temp_dir()))));
    }

    /**
     * Roadmap 4.5: the shared-board tools are classified (so the gate judges
     * a batch member's calls by class) but on no launch at all — only a
     * member's run is handed them.
     */
    public function testTheBoardToolsAreClassifiedButOnNoLaunch(): void
    {
        self::assertSame(ToolPermissionClass::Read, ToolCatalog::permissionOf(BoardReadTool::NAME));
        self::assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf(BoardPostTool::NAME));

        $launched = array_map(static fn (CatalogEntry $e): string => $e->name, ToolCatalog::onLaunch());
        self::assertNotContains(BoardReadTool::NAME, $launched);
        self::assertNotContains(BoardPostTool::NAME, $launched);
        self::assertContains(TaskTool::NAME, $launched);
        self::assertCount(\count(ToolCatalog::entries()) - 2, $launched);

        $built = array_map(static fn (Tool $t): string => $t->name(), ToolCatalog::build(self::context(sys_get_temp_dir())));
        self::assertNotContains(BoardReadTool::NAME, $built);
        self::assertNotContains(BoardPostTool::NAME, $built);
    }

    /**
     * The classes the gate's hand-kept lists held before the catalog: a new
     * tool may join a class, but none of these may silently change class.
     *
     * `Skill` moved Ask -> Read on the skills QA pass, IN THE OPEN: a Level-2
     * load is one escaped text file into context, exactly what `Read` does
     * unasked, and the Ask class made it modal under `default` and denied
     * under `dont-ask` — inverting the progressive-disclosure design. See
     * PermissionGate::isReadOnlyTool()'s doc-block for the full ruling.
     */
    public function testThePermissionClassesKeepTheGatesHistoricalMembers(): void
    {
        $historical = [
            'read' => ['Read', 'Glob', 'Grep', 'Lsp', 'Skill'],
            'write' => ['Bash', 'Edit', 'Write', 'Task'],
            'ask' => ['WebFetch', 'WebSearch', 'doctor'],
        ];
        foreach ($historical as $class => $names) {
            foreach ($names as $name) {
                self::assertSame($class, ToolCatalog::permissionOf($name)?->value, "{$name} changed permission class");
            }
        }

        self::assertNull(ToolCatalog::permissionOf('mcp__git__status'));
    }

    public function testEveryBuiltToolImplementsTheFactory(): void
    {
        foreach (ToolCatalog::built() as $entry) {
            self::assertTrue(is_subclass_of($entry->class, BuildsFromCatalog::class), $entry->class);
            self::assertCount(1, (new \ReflectionClass($entry->class))->getAttributes(BuiltInTool::class), $entry->class);
        }
    }

    public function testAnUndeclaredToolIsRefusedNotSkipped(): void
    {
        $this->tmp = sys_get_temp_dir() . '/catalog-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        $ns = 'CatalogProbe' . bin2hex(random_bytes(4));
        file_put_contents($this->tmp . '/Ghost.php', "<?php\nnamespace {$ns};\nfinal class Ghost implements \\SugarCraft\\Code\\Tools\\Tool {\n"
            . "public function name(): string { return 'Ghost'; }\npublic function description(): string { return ''; }\n"
            . "public function inputSchema(): array { return []; }\n"
            . "public function execute(array \$args): \\SugarCraft\\Code\\Tools\\ToolResult { return \\SugarCraft\\Code\\Tools\\ToolResult::error('x'); }\n}\n");
        require $this->tmp . '/Ghost.php';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('without a #[BuiltInTool] declaration');
        ToolCatalog::discover($this->tmp, $ns . '\\');
    }

    public function testDuplicateNamesAndPositionsAreRefused(): void
    {
        $this->tmp = sys_get_temp_dir() . '/catalog-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        $ns = 'CatalogProbe' . bin2hex(random_bytes(4));
        foreach (['A' => 'Same', 'B' => 'Same'] as $class => $name) {
            file_put_contents($this->tmp . "/{$class}.php", "<?php\nnamespace {$ns};\nuse SugarCraft\\Code\\Tools\\Catalog\\{BuiltInTool, BuildsFromCatalog, ToolBuildContext, ToolPermissionClass};\n"
                . "#[BuiltInTool(name: '{$name}', permission: ToolPermissionClass::Read, position: " . \ord($class) . ")]\n"
                . "final class {$class} implements \\SugarCraft\\Code\\Tools\\Tool, BuildsFromCatalog {\n"
                . "public static function fromCatalog(ToolBuildContext \$c): self { return new self(); }\n"
                . "public function name(): string { return '{$name}'; }\npublic function description(): string { return ''; }\n"
                . "public function inputSchema(): array { return []; }\n"
                . "public function execute(array \$args): \\SugarCraft\\Code\\Tools\\ToolResult { return \\SugarCraft\\Code\\Tools\\ToolResult::error('x'); }\n}\n");
            require $this->tmp . "/{$class}.php";
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the same name Same');
        ToolCatalog::discover($this->tmp, $ns . '\\');
    }
}
