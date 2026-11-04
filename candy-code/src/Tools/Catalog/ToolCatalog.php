<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Catalog;

use SugarCraft\Code\Tools\BuiltIn\BoardPostTool;
use SugarCraft\Code\Tools\BuiltIn\BoardReadTool;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\Tool;

/**
 * The built-in tool set, discovered from `src/Tools/BuiltIn/*.php`.
 *
 * Every concrete {@see Tool} in that directory declares its wire name,
 * permission class and wire position — through {@see BuiltInTool}, or,
 * for the three tools {@see EXTERNALLY_WIRED} names, through that one
 * roster. Either way the declaration is singular: this class holds the
 * only copy. Three
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
     * `AgentManager`. Their declarations live here rather than as attributes on
     * the classes because each class would also need BuildsFromCatalog and its
     * construction lane re-anchored — TaskTool and the board tools are not this
     * file's wave. TUIARCH-08 (reviews-1103) made this the ONE roster of
     * declared positions: every row carries its own `position`, so the wire
     * order is decided here, never an accident of file-name scandir, and the
     * attribute migration that remains is a recorded follow-up, not a mystery.
     *
     * `BoardRead` and `BoardPost` (the pre-1.0 roadmap) are never on a launch at all:
     * `TaskTool` adds them, bound to the batch's board, to the run of a member
     * of a parallel `Task` batch. They are classified here so the gate judges
     * a member's calls to them by their class (read; no-ask), not as unknown
     * names it would put to the user.
     *
     * @var array<class-string<Tool>, array{name: string, permission: ToolPermissionClass, position: int, gloss?: string, onLaunch?: bool}>
     */
    private const EXTERNALLY_WIRED = [
        TaskTool::class => ['name' => TaskTool::NAME, 'permission' => ToolPermissionClass::Write, 'position' => 1002],
        BoardReadTool::class => ['name' => BoardReadTool::NAME, 'permission' => ToolPermissionClass::Read, 'position' => 1001, 'gloss' => 'is offered only to the members of a parallel `Task` batch, to read the board they share', 'onLaunch' => false],
        BoardPostTool::class => ['name' => BoardPostTool::NAME, 'permission' => ToolPermissionClass::NoAsk, 'position' => 1000, 'gloss' => 'is offered only to the members of a parallel `Task` batch, to post to the board they share', 'onLaunch' => false],
    ];

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
        return self::$entries ??= self::discover(
            __DIR__ . '/../BuiltIn',
            'SugarCraft\\Code\\Tools\\BuiltIn\\',
            self::EXTERNALLY_WIRED,
        );
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
     * Entries whose fromCatalog() may answer NULL on an ordinary launch: the
     * name exists in the catalog (classified, rosters, docs) but the tool is
     * built only when the context carries what it needs. MCP-7's dispatcher
     * pair is the first resident — it builds only from a frozen shape that
     * actually deferred a server. C-PRV-3.d's `Advisor` joins it the same way:
     * it builds only when the launch resolved an `advisorModel`, so the plain
     * set no-MCP, no-advisor launch ships is still what the pins below read.
     * Pins that equate "a plain launch's built-in set" to the catalog read
     * {@see builtUnconditionally()}, not built(),
     * or the pair's mere DECLARATION would silently demand its presence in
     * every no-MCP launch.
     *
     * @var list<string>
     */
    public const CONDITIONAL_BUILDS = ['mcp_list', 'mcp_call', 'Advisor'];

    /**
     * The entries every launch carries, whatever its context: built() minus
     * the conditional ones. {@see build()} may return MORE than this (a lazy
     * MCP shape adds the pair) but never less.
     *
     * @return list<CatalogEntry>
     */
    public static function builtUnconditionally(): array
    {
        return array_values(array_filter(
            self::built(),
            static fn (CatalogEntry $e): bool => !in_array($e->name, self::CONDITIONAL_BUILDS, true),
        ));
    }

    /**
     * @return list<CatalogEntry>
     */
    public static function externallyWired(): array
    {
        return array_values(array_filter(self::entries(), static fn (CatalogEntry $e): bool => $e->externallyWired));
    }

    /**
     * The tools a launch can carry: every entry but the ones only a delegated
     * run is ever handed ({@see CatalogEntry::$onLaunch}).
     *
     * @return list<CatalogEntry>
     */
    public static function onLaunch(): array
    {
        return array_values(array_filter(self::entries(), static fn (CatalogEntry $e): bool => $e->onLaunch));
    }

    /**
     * Whether $name is a built-in no launch carries — one the harness hands
     * to a delegated run itself ({@see CatalogEntry::$onLaunch}), which a
     * preset's `tools` grant therefore cannot name and does not narrow.
     */
    public static function isMemberOnly(string $name): bool
    {
        foreach (self::entries() as $entry) {
            if ($entry->name === $name) {
                return !$entry->onLaunch;
            }
        }

        return false;
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
     * @param array<class-string<Tool>, array{name: string, permission: ToolPermissionClass, position: int, gloss?: string, onLaunch?: bool}> $externals
     *                   the declared externally-wired rows; production passes
     *                   {@see EXTERNALLY_WIRED}, fixtures pass [] — the roster
     *                   is consulted ONLY through this parameter (TUIARCH-08).
     *
     * @return list<CatalogEntry>
     */
    public static function discover(string $dir, string $namespacePrefix, array $externals = []): array
    {
        // scandir, not glob: glob() reads no stream wrapper, and under a phar
        // build (DST-1, tools/build-phar.php) this directory lives INSIDE the
        // phar:// archive, where a glob listing comes back empty and the
        // shipped tool set would silently be zero. scandir/filter/sort is
        // the wrapper-safe pair; the sorted-by-full-path order glob gave is
        // re-established by the sort below and is unchanged on a real
        // filesystem.
        $root = rtrim($dir, '/');
        $files = [];
        foreach (scandir($root) ?: [] as $name) {
            if (str_ends_with($name, '.php')) {
                $files[] = $root . '/' . $name;
            }
        }
        sort($files);

        $built = [];
        $external = [];
        $seenExternals = [];
        foreach ($files as $file) {
            $class = $namespacePrefix . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(Tool::class)) {
                continue;
            }

            if (\array_key_exists($class, $externals)) {
                $declared = $externals[$class];
                if (!isset($declared['position'])) {
                    // TUIARCH-08: positions are declared, never derived.
                    throw new \LogicException("{$class} is externally wired without a declared position");
                }

                $seenExternals[$class] = true;
                $external[] = new CatalogEntry(
                    $class,
                    $declared['name'],
                    $declared['permission'],
                    $declared['position'],
                    $declared['gloss'] ?? '',
                    externallyWired: true,
                    onLaunch: $declared['onLaunch'] ?? true,
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

        // TUIARCH-08: ONE order for every entry, by declared position — the
        // built set and the externally wired set no longer sort by separate
        // rules, and the uniqueness refusal covers both halves.
        $entries = [...$built, ...$external];
        usort($entries, static fn (CatalogEntry $a, CatalogEntry $b): int => $a->position <=> $b->position);

        foreach ($externals as $class => $row) {
            if (!isset($seenExternals[$class])) {
                // Fail closed: a roster row for a class the walk never met is
                // the exact drift this finding was about.
                throw new \LogicException("{$class} is declared externally wired but the walk found no such built-in Tool");
            }
        }

        self::assertUnique(array_map(static fn (CatalogEntry $e): string => $e->name, $entries), 'name');
        self::assertUnique(array_map(static fn (CatalogEntry $e): string => (string) $e->position, $entries), 'position');

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
