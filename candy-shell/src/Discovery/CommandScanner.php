<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Discovery;

use ReflectionClass;
use SugarCraft\Shell\Attribute\Alias;
use SugarCraft\Shell\Attribute\Command;
use SugarCraft\Shell\Attribute\Example;
use SugarCraft\Shell\Attribute\Flag;
use SugarCraft\Shell\Attribute\ValueEnum;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Scans a namespace for classes bearing the {@see Command} attribute
 * and registers them into a Symfony Console {@see Application}.
 */
final class CommandScanner
{
    /**
     * @param class-string $namespace Fully-qualified namespace prefix to scan.
     * @return list<class-string> Discovered command class names.
     */
    public function scan(string $namespace, Application $application): array
    {
        $discovered = [];

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
            }
        }

        return $discovered;
    }

    /**
     * @return iterable<class-string>
     */
    private function findClassesInNamespace(string $namespace): iterable
    {
        $prefix = ltrim($namespace, '\\') . '\\';
        $len = strlen($prefix);

        foreach ($this->getDeclaredClasses() as $class) {
            if (strncmp($class, $prefix, $len) !== 0) {
                continue;
            }
            if (!class_exists($class)) {
                continue;
            }
            yield $class;
        }
    }

    /**
     * @return list<string>
     */
    private function getDeclaredClasses(): array
    {
        return get_declared_classes();
    }

    /**
     * @param class-string $class
     */
    private function instantiate(string $class): object
    {
        $rc = new ReflectionClass($class);
        $ctor = $rc->getConstructor();

        if ($ctor === null || !$ctor->getNumberOfRequiredParameters()) {
            return $rc->newInstance();
        }

        $args = array_fill(0, $ctor->getNumberOfRequiredParameters(), null);
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
