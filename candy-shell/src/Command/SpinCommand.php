<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Bits\Spinner\Style as SpinStyle;
use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Shell\Model\SpinModel;
use CandyCore\Shell\Process\RealProcess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run a command while showing a spinner.
 *
 *   $ candyshell spin --title "Building..." -- npm run build
 *
 * The child inherits the parent's stdout/stderr so its output overlays
 * the spinner naturally. Use shell redirection if you want silent
 * execution. Esc / Ctrl-C terminate the child and exit with -1; the
 * normal exit code is forwarded as the candyshell exit code.
 */
#[AsCommand(name: 'spin', description: 'Run a command while showing a spinner.')]
final class SpinCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('argv', InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'The command to run. Use `--` to separate from spin\'s own flags.')
            ->addOption('title', 't', InputOption::VALUE_REQUIRED, 'Status text shown next to the spinner.', '')
            ->addOption('style', null, InputOption::VALUE_REQUIRED,
                'Spinner style: line | dot | minidot | points | pulse | globe | meter.', 'dot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $argv */
        $argv  = $input->getArgument('argv');
        $title = (string) $input->getOption('title');
        $style = self::pickStyle((string) $input->getOption('style'));

        $process = RealProcess::spawn($argv);
        $model   = SpinModel::spawn($process, $title, $style);

        $program = new Program($model, new ProgramOptions(
            useAltScreen:    false,
            hideCursor:      true,
            catchInterrupts: true,
        ));
        /** @var SpinModel $final */
        $final = $program->run();

        $code = $final->exitCode();
        $process->close();
        return $code ?? 0;
    }

    public static function pickStyle(string $name): SpinStyle
    {
        return match (strtolower($name)) {
            'line'    => SpinStyle::line(),
            'dot'     => SpinStyle::dot(),
            'minidot' => SpinStyle::miniDot(),
            'points'  => SpinStyle::points(),
            'pulse'   => SpinStyle::pulse(),
            'globe'   => SpinStyle::globe(),
            'meter'   => SpinStyle::meter(),
            default   => throw new \InvalidArgumentException("unknown spinner style: $name"),
        };
    }
}
