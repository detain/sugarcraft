<?php

declare(strict_types=1);

namespace SugarCraft\Shell;

use SugarCraft\Shell\Command\ChooseCommand;
use SugarCraft\Shell\Command\ConfirmCommand;
use SugarCraft\Shell\Command\FileCommand;
use SugarCraft\Shell\Command\FilterCommand;
use SugarCraft\Shell\Command\FormatCommand;
use SugarCraft\Shell\Command\InputCommand;
use SugarCraft\Shell\Command\JoinCommand;
use SugarCraft\Shell\Command\LogCommand;
use SugarCraft\Shell\Command\PagerCommand;
use SugarCraft\Shell\Command\SpinCommand;
use SugarCraft\Shell\Command\StyleCommand;
use SugarCraft\Shell\Command\TableCommand;
use SugarCraft\Shell\Command\WriteCommand;
use SugarCraft\Shell\Discovery\CommandScanner;
use SugarCraft\Shell\Help\TypoSuggester;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Top-level Symfony Console application registering each subcommand.
 */
final class Application extends SymfonyApplication
{
    private const ENV_PREFIX = 'CANDYSHELL_';

    public function __construct()
    {
        parent::__construct('candyshell', $this->versionFromComposer());
        $this->addCommands([
            new StyleCommand(),
            new ChooseCommand(),
            new InputCommand(),
            new ConfirmCommand(),
            new JoinCommand(),
            new LogCommand(),
            new TableCommand(),
            new FilterCommand(),
            new WriteCommand(),
            new FileCommand(),
            new PagerCommand(),
            new SpinCommand(),
            new FormatCommand(),
            new CompletionCommand(),
        ]);
    }

    /**
     * Reads the version from the root composer.json file.
     */
    public function versionFromComposer(): string
    {
        $rootComposer = $this->findRootComposer();
        if ($rootComposer === null) {
            return '0.0.0';
        }

        $json = json_decode(file_get_contents($rootComposer), true);
        if (!is_array($json)) {
            return '0.0.0';
        }

        return $json['version'] ?? '0.0.0';
    }

    private function findRootComposer(): ?string
    {
        $dir = __DIR__;
        while ($dir !== dirname($dir)) {
            $composerPath = $dir . '/composer.json';
            if (file_exists($composerPath)) {
                $json = json_decode(file_get_contents($composerPath), true);
                if (is_array($json) && ($json['version'] ?? '') !== '') {
                    return $composerPath;
                }
            }
            $dir = dirname($dir);
        }
        return null;
    }

    public function run(InputInterface $input = null, OutputInterface $output = null): int
    {
        $this->setAutoExit(false);
        return parent::run($input, $output);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $command = $this->find($input->getFirstArgument() ?? '');
        if ($command instanceof Command) {
            $this->applyEnvVarFallbackToInput($input, $command);
        }
        return parent::doRun($input, $output);
    }

    private function applyEnvVarFallbackToInput(InputInterface $input, Command $command): void
    {
        $definition = $command->getDefinition();
        foreach ($definition->getOptions() as $option) {
            $envVar = $this->optionToEnvVar($option);
            $envValue = getenv($envVar);
            if ($envValue === false) {
                continue;
            }
            if ($option->isNegatable()) {
                continue;
            }
            if (!$option->acceptValue()) {
                if (in_array(strtolower($envValue), ['1', 'true', 'yes'], true)) {
                    $input->setOption($option->getName(), true);
                }
            } else {
                $input->setOption($option->getName(), $envValue);
            }
        }
    }

    private function optionToEnvVar(InputOption $option): string
    {
        $name = strtoupper($option->getName());
        $name = preg_replace('/[^A-Z0-9_]/', '_', $name) ?: $name;
        return self::ENV_PREFIX . $name;
    }

    /**
     * Scan a namespace for classes bearing the #[Command] attribute
     * and register them into this application.
     *
     * @param class-string $namespace Fully-qualified namespace prefix to scan.
     * @return list<class-string> Names of the discovered command classes.
     */
    public function scan(string $namespace): array
    {
        $scanner = new CommandScanner();
        return $scanner->scan($namespace, $this);
    }

    public function find(string $name): Command
    {
        try {
            return parent::find($name);
        } catch (CommandNotFoundException $e) {
            $commandNames = array_keys($this->all());
            $suggester = new TypoSuggester($commandNames);
            $suggestion = $suggester->suggest($name);

            if ($suggestion !== null) {
                throw new CommandNotFoundException(
                    sprintf(
                        'Command "%s" not found. Did you mean <info>%s</info>?',
                        $name,
                        $suggestion
                    ),
                    array_values($this->all())
                );
            }

            throw $e;
        }
    }
}
