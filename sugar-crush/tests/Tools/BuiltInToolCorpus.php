<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tools\Tool;

/**
 * The built-in tool set, DERIVED from `src/Tools/BuiltIn/` rather than listed.
 *
 * Every corpus of built-in tools in this suite used to be a literal array, and
 * that pins only one of the two directions. MEASURED: adding an eleventh `Tool`
 * implementor (`src/Tools/BuiltIn/Notify.php`) and NOT listing it in
 * {@see \SugarCraft\Crush\Cli\Bootstrap::tools()} left
 * `BinSugarcrushWiringTest` at `OK (298 tests, 1692 assertions)` and the whole
 * Integration tier green — while `tests/Tools/BuiltInToolTest.php`'s "all
 * built-in tools" provider listed only NINE of the ten that existed, `SkillTool`
 * absent. Nothing scanned the directory. The omitted-from-the-array direction is
 * exactly what happened to `Write`: written, tested, named in the README, and
 * unreachable from any real run.
 *
 * So the directory is the corpus. A new tool class is in every one of these
 * tests the moment it exists, and it fails them until it is wired.
 *
 * NOT a trait, and not a static in one of the test classes: three test files in
 * three namespaces need the same list, and a copy per file is the shape being
 * removed. It carries no `Test` suffix, so PHPUnit does not collect it.
 */
final class BuiltInToolCorpus
{
    /**
     * Every concrete {@see Tool} implementor under `src/Tools/BuiltIn/`.
     *
     * Sorted, so a provider keyed off it names its cases the same way on every
     * machine — `glob()` order is not a contract.
     *
     * @return list<class-string<Tool>>
     */
    public static function classNames(): array
    {
        $dir = \dirname(__DIR__, 2) . '/src/Tools/BuiltIn';
        $files = glob($dir . '/*.php');

        if ($files === false || $files === []) {
            throw new \RuntimeException("No built-in tool sources found under {$dir}");
        }

        $classes = [];
        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'SugarCraft\\Crush\\Tools\\BuiltIn\\' . basename($file, '.php');

            if (!class_exists($class)) {
                throw new \RuntimeException(
                    "{$file} does not declare {$class}; the built-in tool namespace and directory must agree",
                );
            }

            $reflection = new \ReflectionClass($class);
            // Interfaces, traits and abstract bases are not tools a run can
            // dispatch. There are none today; the filter is what keeps this
            // scanner correct if one arrives.
            if ($reflection->isAbstract() || !$reflection->implementsInterface(Tool::class)) {
                continue;
            }

            /** @var class-string<Tool> $class */
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * Every built-in tool, constructed with its default (standalone) wiring.
     *
     * A tool whose constructor has REQUIRED arguments has to be named here.
     * Deliberately a throw rather than a skip: a new tool that this cannot build
     * is a new tool nobody has said how to test, and a silent skip would hand
     * back the empty-corpus problem this class exists to remove.
     *
     * @return list<Tool>
     */
    public static function instances(): array
    {
        $tools = [];
        foreach (self::classNames() as $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();

            if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                $tools[] = new $class();

                continue;
            }

            $tools[] = match ($class) {
                SkillTool::class => new SkillTool(new SkillRegistry()),
                default => throw new \RuntimeException(sprintf(
                    '%s needs constructor arguments; add it to %s::instances() so every built-in-tool test '
                    . 'covers it rather than silently skipping it.',
                    $class,
                    self::class,
                )),
            };
        }

        return $tools;
    }
}
