<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Discovery;

use ReflectionClass;
use SugarCraft\ScriptUi\Attribute\Alias;
use SugarCraft\ScriptUi\Attribute\Command;
use SugarCraft\ScriptUi\Attribute\Example;
use SugarCraft\ScriptUi\Attribute\Flag;
use SugarCraft\ScriptUi\Attribute\ValueEnum;
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

        foreach ($this->findClassesInNamespace($namespace) as $class) {
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
                fwrite(STDERR, sprintf(
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
     * @return iterable<class-string>
     */
    private function findClassesInNamespace(string $namespace): iterable
    {
        $prefix = ltrim($namespace, '\\') . '\\';
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

        $params = $ctor->getParameters();
        if ($ctor->getNumberOfRequiredParameters() === 0) {
            return $rc->newInstance();
        }

        $args = [];
        foreach ($params as $param) {
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                // Non-builtin type (e.g. a class) — we cannot instantiate safely.
                return false;
            }

            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            $args[] = match ($typeName) {
                'string' => '',
                'int'    => 0,
                'float'  => 0.0,
                'bool'   => false,
                'array'  => [],
                default  => null,
            };
        }

        // If any required param has a type we couldn't fill (e.g. union types,
        // external class types), skip this class.
        if (in_array(null, $args, true)) {
            return false;
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
