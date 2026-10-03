<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\Catalog;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulePathNudge;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\CatalogEntry;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Tool;

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
            $classes[] = 'SugarCraft\\Crush\\Tools\\BuiltIn\\' . basename($file, '.php');
        }
        sort($classes);

        $catalogued = array_map(static fn (CatalogEntry $e): string => $e->class, ToolCatalog::entries());
        sort($catalogued);

        self::assertSame($classes, $catalogued);
    }

    public function testTheDeclaredNameIsTheNameTheToolAnswers(): void
    {
        $root = sys_get_temp_dir();
        $built = ToolCatalog::build(self::context($root));

        self::assertSame(
            array_map(static fn (CatalogEntry $e): string => $e->name, ToolCatalog::built()),
            array_map(static fn (Tool $t): string => $t->name(), $built),
        );
        foreach ($built as $i => $tool) {
            self::assertInstanceOf(ToolCatalog::built()[$i]->class, $tool);
        }
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
        self::assertSame([TaskTool::class], array_map(static fn (CatalogEntry $e): string => $e->class, ToolCatalog::externallyWired()));
        self::assertSame(ToolPermissionClass::Write, ToolCatalog::permissionOf(TaskTool::NAME));
        self::assertNotContains(TaskTool::NAME, array_map(static fn (Tool $t): string => $t->name(), ToolCatalog::build(self::context(sys_get_temp_dir()))));
    }

    /**
     * The classes the gate's hand-kept lists held before the catalog: a new
     * tool may join a class, but none of these may silently change class.
     */
    public function testThePermissionClassesKeepTheGatesHistoricalMembers(): void
    {
        $historical = [
            'read' => ['Read', 'Glob', 'Grep', 'Lsp'],
            'write' => ['Bash', 'Edit', 'Write', 'Task'],
            'ask' => ['WebFetch', 'WebSearch', 'doctor', 'Skill'],
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
        file_put_contents($this->tmp . '/Ghost.php', "<?php\nnamespace {$ns};\nfinal class Ghost implements \\SugarCraft\\Crush\\Tools\\Tool {\n"
            . "public function name(): string { return 'Ghost'; }\npublic function description(): string { return ''; }\n"
            . "public function inputSchema(): array { return []; }\n"
            . "public function execute(array \$args): \\SugarCraft\\Crush\\Tools\\ToolResult { return \\SugarCraft\\Crush\\Tools\\ToolResult::error('x'); }\n}\n");
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
            file_put_contents($this->tmp . "/{$class}.php", "<?php\nnamespace {$ns};\nuse SugarCraft\\Crush\\Tools\\Catalog\\{BuiltInTool, BuildsFromCatalog, ToolBuildContext, ToolPermissionClass};\n"
                . "#[BuiltInTool(name: '{$name}', permission: ToolPermissionClass::Read, position: " . \ord($class) . ")]\n"
                . "final class {$class} implements \\SugarCraft\\Crush\\Tools\\Tool, BuildsFromCatalog {\n"
                . "public static function fromCatalog(ToolBuildContext \$c): self { return new self(); }\n"
                . "public function name(): string { return '{$name}'; }\npublic function description(): string { return ''; }\n"
                . "public function inputSchema(): array { return []; }\n"
                . "public function execute(array \$args): \\SugarCraft\\Crush\\Tools\\ToolResult { return \\SugarCraft\\Crush\\Tools\\ToolResult::error('x'); }\n}\n");
            require $this->tmp . "/{$class}.php";
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the same name Same');
        ToolCatalog::discover($this->tmp, $ns . '\\');
    }
}
