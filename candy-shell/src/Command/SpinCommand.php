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
            ->addOption('title',       't', InputOption::VALUE_REQUIRED, 'Status text shown next to the spinner.', '')
            ->addOption('style',       null, InputOption::VALUE_REQUIRED,
                'Spinner style: line | dot | minidot | points | pulse | globe | meter | jump | moon | monkey | hamburger | ellipsis.', 'dot')
            ->addOption('spinner',     's', InputOption::VALUE_REQUIRED, 'Alias for --style (gum compat).', null)
            ->addOption('show-output', null, InputOption::VALUE_NONE,    'Print captured stdout after the command exits.')
            ->addOption('show-error',  null, InputOption::VALUE_NONE,    'Print captured stderr after the command exits.')
            ->addOption('show-stdout', null, InputOption::VALUE_NONE,    'Alias for --show-output.')
            ->addOption('show-stderr', null, InputOption::VALUE_NONE,    'Alias for --show-error.');
    }

    public const EXIT_INTERRUPTED = 130; // 128 + SIGINT(2)

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $argv */
        $argv  = $input->getArgument('argv');
        $title = (string) $input->getOption('title');
        $styleName = $input->getOption('spinner') ?? $input->getOption('style');
        $style = self::pickStyle((string) $styleName);
        $showOutput = (bool) ($input->getOption('show-output') || $input->getOption('show-stdout'));
        $showError  = (bool) ($input->getOption('show-error')  || $input->getOption('show-stderr'));

        // When the caller wants captured output, redirect stdout/stderr
        // so they don't bleed onto the spinner — write the buffered
        // content after the spinner stops.
        $process = RealProcess::spawn($argv, captureStdout: $showOutput, captureStderr: $showError);
        $model   = SpinModel::spawn($process, $title, $style);

        $program = new Program($model, new ProgramOptions(
            useAltScreen:    false,
            hideCursor:      true,
            catchInterrupts: true,
        ));
        /** @var SpinModel $final */
        $final = $program->run();

        $code = $final->exitCode();
        // The Program installs a SIGINT handler that stops the loop
        // *before* dispatching a KeyMsg, so a Ctrl-C run never reaches
        // SpinModel::update() and exitCode stays null. Treat any
        // non-completed run as cancellation: terminate the child and
        // surface 130 (the conventional SIGINT exit code) so calling
        // scripts can detect interruption.
        if ($code === null) {
            $process->terminate();
            $code = self::EXIT_INTERRUPTED;
        }
        if ($showOutput) {
            $output->write($process->stdout());
        }
        if ($showError) {
            fwrite(STDERR, $process->stderr());
        }
        $process->close();
        return $code;
    }

    public static function pickStyle(string $name): SpinStyle
    {
        return match (strtolower($name)) {
            'line'      => SpinStyle::line(),
            'dot'       => SpinStyle::dot(),
            'minidot'   => SpinStyle::miniDot(),
            'points'    => SpinStyle::points(),
            'pulse'     => SpinStyle::pulse(),
            'globe'     => SpinStyle::globe(),
            'meter'     => SpinStyle::meter(),
            'jump'      => SpinStyle::jump(),
            'moon'      => SpinStyle::moon(),
            'monkey'    => SpinStyle::monkey(),
            'hamburger' => SpinStyle::hamburger(),
            'ellipsis'  => SpinStyle::ellipsis(),
            default     => throw new \InvalidArgumentException("unknown spinner style: $name"),
        };
    }
}
