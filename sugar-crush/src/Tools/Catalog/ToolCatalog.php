<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Catalog;

use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;

/**
 * The built-in tool set, discovered from `src/Tools/BuiltIn/*.php`.
 *
 * Every concrete {@see Tool} in that directory declares its wire name,
 * permission class and wire position through {@see BuiltInTool}. Three
 * consumers used to keep their own copy of that knowledge and drift apart:
 * `Bootstrap::unfilteredTools()` (the constructor list),
 * `PermissionGate::isReadOnlyTool()` / `isWriteTool()` (name lists) and
 * `ProtectFilesHook` (its read-only list), plus the tool counts written into
 * README, ARCHITECTURE, AGENTS_AUTHORING, PERMISSIONS and SETTINGS. All of
 * them now read this class, and the counts are rewritten by
 * `tools/gen-tool-docs.php`.
 *
 * A concrete Tool in the directory with no declaration is a hard error rather
 * than a silent skip: an undeclared tool would be neither built nor
 * classified, which is the "written, tested and unreachable" `Write` defect
 * again.
 */
final class ToolCatalog
{
    /**
     * Built-in tools the catalog classifies but does not construct, keyed by
     * class: `Bootstrap::tools()` appends `Task` after the
     * `allowedTools`/`disabledTools` filter, and only when the launch holds an
     * `AgentManager`. Its declaration lives here rather than as an attribute on
     * the class because `TaskTool.php` is owned by another work stream this
     * wave; moving it onto the class is a one-line follow-up.
     *
     * @var array<class-string<Tool>, array{name: string, permission: ToolPermissionClass}>
     */
    private const EXTERNALLY_WIRED = [
        TaskTool::class => ['name' => TaskTool::NAME, 'permission' => ToolPermissionClass::Write],
    ];

    /** Externally wired tools sort after every catalog-built position. */
    private const EXTERNAL_POSITION_BASE = 1000;

    /** @var list<CatalogEntry>|null */
    private static ?array $entries = null;

    private function __construct()
    {
    }

    /**
     * Every built-in tool, catalog-built ones in wire order and then the
     * externally wired ones.
     *
     * @return list<CatalogEntry>
     */
    public static function entries(): array
    {
        return self::$entries ??= self::discover(__DIR__ . '/../BuiltIn', 'SugarCraft\\Crush\\Tools\\BuiltIn\\');
    }

    /**
     * The tools {@see build()} constructs: the `allowedTools`/`disabledTools`
     * ceiling, in wire order.
     *
     * @return list<CatalogEntry>
     */
    public static function built(): array
    {
        return array_values(array_filter(self::entries(), static fn (CatalogEntry $e): bool => !$e->externallyWired));
    }

    /**
     * @return list<CatalogEntry>
     */
    public static function externallyWired(): array
    {
        return array_values(array_filter(self::entries(), static fn (CatalogEntry $e): bool => $e->externallyWired));
    }

    /**
     * Every built-in wire name, in {@see entries()} order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (CatalogEntry $e): string => $e->name, self::entries());
    }

    /**
     * The wire names in one permission class, in {@see entries()} order.
     *
     * @return list<string>
     */
    public static function namesOf(ToolPermissionClass $class): array
    {
        $names = [];
        foreach (self::entries() as $entry) {
            if ($entry->permission === $class) {
                $names[] = $entry->name;
            }
        }

        return $names;
    }

    /**
     * The declared class for a wire name, or null for a name that is not a
     * built-in (an `mcp__*` bridge, a typo, a workflow-declared name).
     */
    public static function permissionOf(string $name): ?ToolPermissionClass
    {
        foreach (self::entries() as $entry) {
            if ($entry->name === $name) {
                return $entry->permission;
            }
        }

        return null;
    }

    /**
     * Construct every catalog-built tool for one launch, in wire order.
     *
     * @return list<Tool>
     */
    public static function build(ToolBuildContext $context): array
    {
        $tools = [];
        foreach (self::built() as $entry) {
            /** @var class-string<BuildsFromCatalog> $class */
            $class = $entry->class;
            $tool = $class::fromCatalog($context);
            if ($tool !== null) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * Scan one directory. Public so the catalog's own refusals can be tested
     * against a fixture tree; production reads {@see entries()}.
     *
     * @return list<CatalogEntry>
     */
    public static function discover(string $dir, string $namespacePrefix): array
    {
        $files = glob(rtrim($dir, '/') . '/*.php') ?: [];
        sort($files);

        $built = [];
        $external = [];
        foreach ($files as $file) {
            $class = $namespacePrefix . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(Tool::class)) {
                continue;
            }

            if (\array_key_exists($class, self::EXTERNALLY_WIRED)) {
                $declared = self::EXTERNALLY_WIRED[$class];
                $external[] = new CatalogEntry(
                    $class,
                    $declared['name'],
                    $declared['permission'],
                    self::EXTERNAL_POSITION_BASE + \count($external),
                    externallyWired: true,
                );

                continue;
            }

            $attributes = $reflection->getAttributes(BuiltInTool::class);
            if (\count($attributes) !== 1) {
                throw new \LogicException("{$class} is a built-in Tool without a #[BuiltInTool] declaration");
            }

            if (!$reflection->implementsInterface(BuildsFromCatalog::class)) {
                throw new \LogicException("{$class} declares #[BuiltInTool] but does not implement BuildsFromCatalog");
            }

            $declaration = $attributes[0]->newInstance();
            $built[] = new CatalogEntry(
                $class,
                $declaration->name,
                $declaration->permission,
                $declaration->position,
                $declaration->gloss,
            );
        }

        usort($built, static fn (CatalogEntry $a, CatalogEntry $b): int => $a->position <=> $b->position);
        $entries = [...$built, ...$external];

        self::assertUnique(array_map(static fn (CatalogEntry $e): string => $e->name, $entries), 'name');
        self::assertUnique(array_map(static fn (CatalogEntry $e): string => (string) $e->position, $built), 'position');

        return $entries;
    }

    /**
     * @param list<string> $values
     */
    private static function assertUnique(array $values, string $what): void
    {
        $seen = [];
        foreach ($values as $value) {
            if (isset($seen[$value])) {
                throw new \LogicException("two built-in tools declare the same {$what} {$value}");
            }

            $seen[$value] = true;
        }
    }
}
