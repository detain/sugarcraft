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
use SugarCraft\Code\Tools\BuiltIn\ScriptTool;
use SugarCraft\Code\Tools\BuiltIn\ShellSend;
use SugarCraft\Code\Tools\BuiltIn\SkillsSearch;
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
            // C-TOOL-6.k truth-flip: ShellSend (47) landed unconditional after
            // Script (46, C-TOOL-6.d), so the pair rides ahead of only the
            // newest unconditional tools. C-TOOL-6.b in-step: NotebookEdit
            // (48) landed unconditional after ShellSend, moving the window —
            // the pin keeps its meaning (the pair never sneaks in before its
            // trigger) by naming the unconditional tools that land after it.
            [ScriptTool::NAME, ShellSend::NAME, 'NotebookEdit'],
            \array_slice($names, -3),
            'the dispatcher pair lands in roster order 39 then 40, ahead only of the newest unconditional tools',
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

    /**
     * Write one fixture tool under a fresh namespace. A position mints the
     * #[BuiltInTool] declaration; null writes a bare Tool implementation the
     * catalog can only classify externally.
     *
     * @return class-string the fixture class
     */
    private function writeToolFixture(string $ns, string $class, ?int $position, string $name): string
    {
        if ($this->tmp === '') {
            $this->tmp = sys_get_temp_dir() . '/catalog-' . bin2hex(random_bytes(4));
            mkdir($this->tmp);
        }

        $declaration = $position === null ? ''
            : "#[BuiltInTool(name: '{$name}', permission: ToolPermissionClass::Read, position: {$position})]\n";
        $body = $position === null ? ''
            : "public static function fromCatalog(ToolBuildContext \$c): self { return new self(); }\n";
        file_put_contents($this->tmp . "/{$class}.php", "<?php\nnamespace {$ns};\nuse SugarCraft\\Code\\Tools\\Catalog\\{BuiltInTool, BuildsFromCatalog, ToolBuildContext, ToolPermissionClass};\n"
            . $declaration
            . "final class {$class} implements \\SugarCraft\\Code\\Tools\\Tool" . ($position === null ? '' : ', BuildsFromCatalog') . " {\n"
            . $body
            . "public function name(): string { return '{$name}'; }\npublic function description(): string { return ''; }\n"
            . "public function inputSchema(): array { return []; }\n"
            . "public function execute(array \$args): \\SugarCraft\\Code\\Tools\\ToolResult { return \\SugarCraft\\Code\\Tools\\ToolResult::error('x'); }\n}\n");
        require $this->tmp . "/{$class}.php";

        return "{$ns}\\{$class}";
    }

    /**
     * TUIARCH-08 (reviews-1103): every externally wired row DECLARES its
     * position, and the entries carry that declaration unchanged — the wire
     * order is decided, never an accident of the file-name scandir.
     */
    public function testExternallyWiredEntriesCarryTheirDeclaredPositions(): void
    {
        $roster = (new \ReflectionClass(ToolCatalog::class))->getConstant('EXTERNALLY_WIRED');
        self::assertIsArray($roster);
        $declared = [];
        foreach ($roster as $class => $row) {
            self::assertIsInt($row['position'] ?? null, "{$class} is externally wired without a declared position");
            $declared[$row['name']] = $row['position'];
        }

        ksort($declared);
        $actual = [];
        foreach (ToolCatalog::externallyWired() as $entry) {
            $actual[$entry->name] = $entry->position;
        }
        ksort($actual);
        self::assertSame($declared, $actual, 'the entries carry the declared positions, unchanged');
    }

    /**
     * TUIARCH-08: a roster row pointing at a class the walk never meets is
     * exactly the drift this finding is about — refused, not silently dropped.
     */
    public function testStaleExternallyWiredRowRefusesTheWalk(): void
    {
        $ns = 'CatalogProbe' . bin2hex(random_bytes(4));
        $this->writeToolFixture($ns, 'Alpha', 7, 'alpha');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no such built-in Tool');
        ToolCatalog::discover($this->tmp, $ns . '\\', [
            "{$ns}\\Absent" => ['name' => 'ghost-row', 'permission' => ToolPermissionClass::Read, 'position' => 1000],
        ]);
    }

    /**
     * TUIARCH-08: positions are unique across the COMBINED set, not the built
     * half only — an external row can no longer collide behind the census.
     */
    public function testExternalPositionCollidingWithABuiltRowRefuses(): void
    {
        $ns = 'CatalogProbe' . bin2hex(random_bytes(4));
        $this->writeToolFixture($ns, 'Alpha', 1000, 'alpha');
        $beta = $this->writeToolFixture($ns, 'Beta', null, 'beta');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the same position 1000');
        ToolCatalog::discover($this->tmp, $ns . '\\', [
            $beta => ['name' => 'beta-ext', 'permission' => ToolPermissionClass::Read, 'position' => 1000],
        ]);
    }

    /**
     * TUIARCH-08: an external row without a declared position is a derived
     * order sneaking back in — refused at the door.
     */
    public function testExternalRowWithoutADeclaredPositionRefuses(): void
    {
        $ns = 'CatalogProbe' . bin2hex(random_bytes(4));
        $beta = $this->writeToolFixture($ns, 'Beta', null, 'beta');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('externally wired without a declared position');
        ToolCatalog::discover($this->tmp, $ns . '\\', [
            $beta => ['name' => 'beta-ext', 'permission' => ToolPermissionClass::Read],
        ]);
    }
}
