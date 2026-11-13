<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Discovery;

use ReflectionClass;
use SugarCraft\ScriptUi\Application as ScriptUiApplication;
use SugarCraft\ScriptUi\Attribute\Alias;
use SugarCraft\ScriptUi\Attribute\Command;
use SugarCraft\ScriptUi\Attribute\Example;
use SugarCraft\ScriptUi\Attribute\Flag;
use SugarCraft\ScriptUi\Attribute\ValueEnum;
use SugarCraft\ScriptUi\Runtime\Diagnostics;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Scans a namespace for classes bearing the {@see Command} attribute
 * and registers them into a Symfony Console {@see Application}.
 */
final class CommandScanner
{
    /** @var list<class-string> Classes bearing #[Command] that scan() could not construct. */
    private array $skipped = [];

    /**
     * Commands that were discovered but could NOT be registered (their
     * constructors require a dependency the scanner cannot inject). Call it
     * right after {@see scan()}; scan() resets it on each run.
     *
     * @return list<class-string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * @param class-string $namespace Fully-qualified namespace prefix to scan.
     * @return list<class-string> Discovered command class names.
     */
    public function scan(string $namespace, Application $application): array
    {
        $discovered = [];
        $this->skipped = [];

        $classes = [];
        foreach ($this->findClassesInNamespace($namespace) as $class) {
            $classes[] = $class;
        }
        if ($classes === []) {
            // A scan that matches zero classes is how the README's trailing-
            // separator quickstart used to silently register nothing (review
            // 2026-11-02 CC5). The prefix is normalised now, so the remaining
            // ways to land here are a typo'd or empty namespace — all of them
            // deserve a loud word rather than a vacuum.
            Diagnostics::emit(sprintf(
                "candy-scriptui: no classes found under namespace '%s'; nothing registered (check the spelling, or autoload the classes before scan()).\n",
                $namespace
            ));
        }

        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);

            $commandAttr = $ref->getAttributes(Command::class)[0] ?? null;
            if ($commandAttr === null) {
                continue;
            }

            /** @var Command */
            $commandMeta = $commandAttr->newInstance();

            $flagAttrs = $ref->getAttributes(Flag::class);
            $instance = $this->instantiate($class);

            if ($instance instanceof SymfonyCommand) {
                if ($commandMeta->name !== '') {
                    $instance->setName($commandMeta->name);
                }
                if ($commandMeta->description !== '') {
                    $instance->setDescription($commandMeta->description);
                }
                if ($commandMeta->descriptionSection !== '') {
                    $instance->setHelp($commandMeta->descriptionSection);
                }

                foreach ($flagAttrs as $flagAttr) {
                    /** @var Flag */
                    $flag = $flagAttr->newInstance();
                    $this->applyFlag($instance, $flag);
                    $this->registerEnumConstraint($application, $instance, $flag);
                }

                $aliasAttrs = $ref->getAttributes(Alias::class);
                foreach ($aliasAttrs as $aliasAttr) {
                    /** @var Alias */
                    $alias = $aliasAttr->newInstance();
                    $instance->setAliases([...$instance->getAliases(), $alias->name]);
                }

                $application->add($instance);
                $discovered[] = $class;
            } else {
                // Fail loud: a #[Command] class we silently dropped is the
                // difference between a working tool and a confusing one.
                $this->skipped[] = $class;
                Diagnostics::emit(sprintf(
                    "candy-scriptui: skipping command %s: not constructible as a Symfony Command (constructor dependency the scanner cannot inject, or missing Command base class).\n",
                    $class
                ));
            }
        }

        return $discovered;
    }

    /** @var array<string, bool> */
    private array $loadedLater = [];

    /**
     * Pass a `#[Flag(enum: SomeType::class)]` to the application for runtime
     * enforcement (review 2026-11-02 M10: the seam was documented but never
     * wired, so `--format=pineapple` sailed through). Enforcement lives in
     * {@see ScriptUiApplication::doRunCommand()} because option definitions
     * are built before any input is parsed — the validate-on-run moment is
     * the earliest point that can see the value.
     */
    private function registerEnumConstraint(Application $application, SymfonyCommand $command, Flag $flag): void
    {
        if ($flag->isFlag || $flag->enum === null) {
            return;
        }
        if (!$application instanceof ScriptUiApplication) {
            return;
        }

        if (!enum_exists($flag->enum)) {
            Diagnostics::emit(sprintf(
                "candy-scriptui: --%s declares enum '%s' but no such enum exists; values are not enforced.\n",
                $flag->name,
                $flag->enum
            ));
            return;
        }

        $commandName = $command->getName();
        if ($commandName === null) {
            return;
        }
        $application->addEnumConstraint($commandName, $flag->name, $flag->enum);
    }

    /**
     * @return iterable<class-string>
     */
    private function findClassesInNamespace(string $namespace): iterable
    {
        // Normalise: trim separators from BOTH ends, then append exactly one.
        // The README quickstart passes a trailing-backslash form, and the
        // old `ltrim(...) . '\\'` turned it into a double separator matching
        // zero declared classes — a silent no-op (review 2026-11-02 CC5).
        $normalized = trim($namespace, '\\');
        if ($normalized === '') {
            return;
        }
        $prefix = $normalized . '\\';
        $len = strlen($prefix);

        $this->loadedLater = [];
        $loader = function (string $class) use ($prefix, $len): void {
            if (strncmp($class, $prefix, $len) === 0) {
                $this->loadedLater[$class] = true;
            }
        };
        spl_autoload_register($loader, true, true);

        try {
            $known = get_declared_classes();

            foreach ($known as $class) {
                if (strncmp($class, $prefix, $len) !== 0) {
                    continue;
                }
                if (!class_exists($class)) {
                    continue;
                }
                yield $class;
            }

            foreach (array_keys($this->loadedLater) as $class) {
                if (!class_exists($class)) {
                    continue;
                }
                yield $class;
            }
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * @param class-string $class
     * @return object|false False if the class cannot be safely constructed
     *                      (has required non-nullable typed parameters we cannot fill).
     */
    private function instantiate(string $class): object|false
    {
        $rc = new ReflectionClass($class);
        $ctor = $rc->getConstructor();

        if ($ctor === null) {
            return $rc->newInstance();
        }

        // Only REQUIRED parameters get filled. The old loop walked every
        // parameter and pushed type-zero values, clobbering the author's own
        // defaults on optional parameters (review 2026-11-02 senior m-3).
        $required = $ctor->getNumberOfRequiredParameters();
        if ($required === 0) {
            return $rc->newInstance();
        }

        $args = [];
        foreach (array_slice($ctor->getParameters(), 0, $required) as $param) {
            // A required-nullable parameter gets null — a legal value the
            // author's body already handles. The old code injected null here
            // and then rejected its own injection via an in_array(null) sweep
            // (m-3 over-reject), silently skipping constructible commands.
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                // Union/intersection types: we cannot guess a value.
                return false;
            }
            if (!$type->isBuiltin()) {
                // Non-builtin type (e.g. a class) — we cannot instantiate safely.
                return false;
            }

            $fill = match ($type->getName()) {
                'string' => '',
                'int'    => 0,
                'float'  => 0.0,
                'bool'   => false,
                'array'  => [],
                default  => null,
            };
            if ($fill === null) {
                return false;
            }
            $args[] = $fill;
        }

        return $rc->newInstanceArgs($args);
    }

    private function applyFlag(SymfonyCommand $command, Flag $flag): void
    {
        $mode = InputOption::VALUE_NONE;
        if (!$flag->isFlag) {
            $mode = $flag->required
                ? InputOption::VALUE_REQUIRED
                : InputOption::VALUE_OPTIONAL;
        }

        $command->addOption(
            $flag->name,
            $flag->short ?: null,
            $mode,
            $flag->description,
            $flag->default,
        );
    }
}
