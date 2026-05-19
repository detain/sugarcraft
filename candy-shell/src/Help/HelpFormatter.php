<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Help;

use ReflectionClass;
use SugarCraft\Shell\Attribute\Alias;
use SugarCraft\Shell\Attribute\Command;
use SugarCraft\Shell\Attribute\Example;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Output\OutputInterface;

final class HelpFormatter
{
    public function format(SymfonyCommand $command): string
    {
        $name = $this->getCommandName($command);
        $description = $command->getDescription();
        $help = $command->getHelp();

        $lines = [];
        $lines[] = sprintf('<comment>%s</comment>', $name);
        if ($description !== '') {
            $lines[] = $description;
        }
        $lines[] = '';

        $ref = new ReflectionClass($command);
        $aliasAttrs = $ref->getAttributes(Alias::class);
        if ($aliasAttrs !== []) {
            $aliases = array_map(
                static fn(\ReflectionAttribute $attr) => $attr->newInstance()->name,
                $aliasAttrs
            );
            $lines[] = sprintf('<info>Aliases:</info> %s', implode(', ', $aliases));
            $lines[] = '';
        }

        $exampleAttrs = $ref->getAttributes(Example::class);
        if ($exampleAttrs !== []) {
            $lines[] = '<info>Examples:</info>';
            foreach ($exampleAttrs as $exampleAttr) {
                /** @var Example */
                $example = $exampleAttr->newInstance();
                $line = sprintf('  %s', $example->usage);
                if ($example->description !== '') {
                    $line .= sprintf('  — %s', $example->description);
                }
                $lines[] = $line;
            }
            $lines[] = '';
        }

        if ($help !== '') {
            $lines[] = '<info>Help:</info>';
            $lines[] = $help;
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    private function getCommandName(SymfonyCommand $command): string
    {
        $name = $command->getName();
        if ($name !== '') {
            return $name;
        }

        $ref = new ReflectionClass($command);
        $commandAttr = $ref->getAttributes(Command::class)[0 ?? -1] ?? null;
        if ($commandAttr !== null) {
            /** @var Command */
            $meta = $commandAttr->newInstance();
            if ($meta->name !== '') {
                return $meta->name;
            }
        }

        return '';
    }

    public function write(OutputInterface $output, SymfonyCommand $command): void
    {
        $output->writeln($this->format($command));
    }
}
