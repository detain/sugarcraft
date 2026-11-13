<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\ScriptUi\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the version of this tool as its own subcommand.
 *
 * Mirrors charmbracelet/gum `version` (capability-gating setup scripts
 * call `gum version`; candy-scriptui shipped only the global `--version`
 * leg, so `candy-scriptui version` died with an ambiguous-command rc=1).
 * The answer is delegated to the SAME long-version template the
 * `--version` leg renders, so both arms are byte-identical by
 * construction, and both stay phar-aware through
 * {@see Application::versionString()}.
 */
#[AsCommand(
    name: 'version',
    description: 'Print the version of this tool.',
)]
final class VersionCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();
        $line = $application !== null
            ? $application->getLongVersion()
            : 'candy-scriptui ' . Application::versionString();

        $output->writeln($line);

        return Command::SUCCESS;
    }
}
