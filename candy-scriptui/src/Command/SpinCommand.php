<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use React\EventLoop\LoopInterface;
use SugarCraft\Forms\Spinner\Style as SpinStyle;
use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\SpinModel;
use SugarCraft\ScriptUi\Process\Process;
use SugarCraft\ScriptUi\Process\RealProcess;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
use SugarCraft\ScriptUi\Runtime\SignalExit;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use SugarCraft\ScriptUi\Attribute\Example;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run a command while showing a spinner.
 *
 *   $ candy-scriptui spin --title "Building..." -- npm run build
 *
 * The child inherits the parent's stdout/stderr so its output overlays
 * the spinner naturally. Use shell redirection if you want silent
 * execution. Esc / Ctrl+C terminate the child and exit with 130 (the
 * conventional 128+SIGINT code); SIGTERM exits 143 and a --timeout
 * deadline exits 124. The child's own exit code is forwarded as the
 * candy-scriptui exit code — note that a child exiting 2/124/130/143
 * is therefore indistinguishable from the runner's own reserved codes
 * (gum parity: the child's verdict always wins).
 */
#[Example('candy-scriptui spin -- npm ci', 'Run a build behind a spinner.')]
#[AsCommand(name: 'spin', description: 'Run a command while showing a spinner.')]
final class SpinCommand extends Command
{
    /** @var \Closure(list<string>, bool, bool): Process spawns the child */
    private readonly \Closure $processFactory;

    /** @var \Closure(Model, LoopInterface): Program builds the spinner runtime */
    private readonly \Closure $programFactory;

    /**
     * The class is `final`, so the child-process spawn and the {@see Program}
     * construction are injected as optional factory closures rather than
     * exposed as overridable methods. Production leaves both null (real
     * {@see RealProcess} + a TTY Program); tests pass a {@see \SugarCraft\ScriptUi\Process\FakeProcess}
     * that never exits plus a headless Program so the `--timeout` path can be
     * driven deterministically. The zero-arg default keeps `new SpinCommand()`
     * working for {@see \SugarCraft\ScriptUi\Application} auto-registration.
     *
     * @param ?\Closure(list<string>, bool, bool): Process $processFactory
     * @param ?\Closure(Model, LoopInterface): Program      $programFactory
     */
    public function __construct(
        ?\Closure $processFactory = null,
        ?\Closure $programFactory = null,
    ) {
        parent::__construct();

        $this->processFactory = $processFactory
            ?? static fn (array $argv, bool $captureStdout, bool $captureStderr): Process
                => RealProcess::spawn($argv, captureStdout: $captureStdout, captureStderr: $captureStderr);

        $this->programFactory = $programFactory
            ?? static function (Model $model, LoopInterface $loop): Program {
                $options = InteractiveRuntime::programOptions(useAltScreen: false, hideCursor: true);
                return new Program($model, $options ?? new ProgramOptions(
                    useAltScreen:    false,
                    hideCursor:      true,
                    catchInterrupts: true,
                    loop:            $loop,
                    withoutRenderer: true,
                ));
            };
    }

    protected function configure(): void
    {
        $this
            ->addArgument('argv', InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'The command to run. Use `--` to separate from spin\'s own flags.')
            ->addOption('title',       't', InputOption::VALUE_REQUIRED, 'Status text shown next to the spinner.', '')
            ->addOption('style',       null, InputOption::VALUE_REQUIRED,
                'Spinner style: line | dot | mini-dot | points | pulse | globe | meter | jump | moon | monkey | hamburger | ellipsis.', 'dot')
            ->addOption('spinner',     's', InputOption::VALUE_REQUIRED, 'Alias for --style (gum compat).', null)
            ->addOption('show-output', null, InputOption::VALUE_NONE,    'Print captured stdout after the command exits.')
            ->addOption('show-error',  null, InputOption::VALUE_NONE,    'Print captured stderr after the command exits.')
            ->addOption('show-stdout', null, InputOption::VALUE_NONE,    'Alias for --show-output.')
            ->addOption('show-stderr', null, InputOption::VALUE_NONE,    'Alias for --show-error.')
            ->addOption('align',       'a',  InputOption::VALUE_REQUIRED, 'Title position relative to the spinner: left | right.', 'left')
            ->addOption('show-help',   null, InputOption::VALUE_NONE,     'Alias for --help (gum compat).')
            ->addOption('timeout',     null, InputOption::VALUE_REQUIRED, 'Kill the spawned command after N seconds (0 = no limit).', 0);
    }

    public const EXIT_INTERRUPTED = SignalExit::EXIT_INTERRUPTED;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeoutSeconds = 0.0;
        if (!TimeoutGuard::tryParseSeconds($input->getOption('timeout'), $timeoutSeconds)) {
            // A typo'd deadline must not silently disable itself (fail-open).
            fwrite(STDERR, Lang::t('runtime.bad_timeout', ['value' => (string) $input->getOption('timeout')]) . "\n");
            return Command::FAILURE;
        }

        /** @var list<string> $argv */
        $argv  = $input->getArgument('argv');
        $title = (string) $input->getOption('title');
        $styleName = $input->getOption('spinner') ?? $input->getOption('style');
        // Refuse an unknown style as a plain diagnostic naming every
        // accepted spelling; the static pickStyle() throw stays as the
        // library contract, but CLI users never see an exception frame.
        $styleKey = strtolower((string) $styleName);
        if (!in_array($styleKey, self::STYLE_SPELLINGS, true)) {
            fwrite(STDERR, Lang::t('runtime.unknown_choice', [
                'option' => '--style',
                'value' => (string) $styleName,
                'allowed' => 'line|dot|mini-dot|points|pulse|globe|meter|jump|moon|monkey|hamburger|ellipsis',
            ]) . "\n");
            return Command::FAILURE;
        }
        $style = self::pickStyle((string) $styleName);
        $showOutput = (bool) ($input->getOption('show-output') || $input->getOption('show-stdout'));
        $showError  = (bool) ($input->getOption('show-error')  || $input->getOption('show-stderr'));

        // When the caller wants captured output, redirect stdout/stderr
        // so they don't bleed onto the spinner — write the buffered
        // content after the spinner stops.
        $align = strtolower((string) $input->getOption('align'));
        if ($align === '') {
            $align = 'left';
        }
        if (!in_array($align, ['left', 'right'], true)) {
            // Anything else would silently render left-aligned; refuse loudly.
            fwrite(STDERR, Lang::t('runtime.unknown_choice', [
                'option'  => '--align',
                'value'   => $align,
                'allowed' => 'left|right',
            ]) . "\n");
            return Command::FAILURE;
        }

        $process = ($this->processFactory)($argv, $showOutput, $showError);
        $model   = SpinModel::spawn($process, $title, $style, $align);

        $loop    = \React\EventLoop\Loop::get();
        $program = ($this->programFactory)($model, $loop);
        // Two statements on fire, so a real closure (not an arrow fn): the
        // deadline must both signal the child AND stop the loop — killing
        // the Program alone would orphan the still-running command.
        $guard = TimeoutGuard::arm(
            $loop,
            $timeoutSeconds,
            function () use ($process, $program): void {
                $process->terminate();
                $program->kill();
            },
        );

        // scriptui-02 (the 64KiB cure): the capture pipes ride the run
        // loop as read streams, so a child writing more than the OS pipe
        // buffer keeps moving bytes instead of blocking while the spinner
        // polls an exit verdict that can never arrive. With no capture
        // pipes (--show-output/--show-error unset) the attach is a no-op.
        $process->attachPump($loop);

        /** @var SpinModel $final */
        $final = $program->run();
        $guard->disarm();
        $process->detachPump();

        // Decide the exit code with the deadline checked FIRST: a timed-out
        // run also leaves exitCode() null, so any other order would
        // mis-report a timeout as a cancellation.
        if ($guard->fired()) {
            $code = TimeoutGuard::EXIT_TIMEOUT;
        } else {
            // OS-level signals (kill -INT / -TERM) land through the
            // Program's stopSignal. Raw mode clears ISIG, so a TYPED
            // Ctrl+C never becomes a signal here — it arrives as a KeyMsg
            // and SpinModel records it as exit code 130.
            $signalExit = SignalExit::code($program->stopSignal());
            if ($signalExit !== null) {
                $process->terminate();
                $code = $signalExit;
            } else {
                $code = $final->exitCode();
                if ($code === null) {
                    // Loop stopped with no child verdict (e.g. a QuitMsg
                    // race) — treat as cancellation and make sure the
                    // child dies with it.
                    $process->terminate();
                    $code = self::EXIT_INTERRUPTED;
                }
            }
        }

        // Flush captured output on EVERY exit path — including the
        // timeout — so `spin --timeout N --show-output` never swallows
        // what the child managed to print.
        if ($showOutput) {
            $output->write($process->stdout(), false, OutputInterface::OUTPUT_RAW);
        }
        if ($showError) {
            fwrite(STDERR, $process->stderr());
        }
        $process->close();
        return $code;
    }

    /** @var list<string> every spelling pickStyle() resolves (incl. aliases). */
    public const STYLE_SPELLINGS = ['line', 'dot', 'minidot', 'mini-dot', 'points', 'pulse', 'globe', 'meter', 'jump', 'moon', 'monkey', 'hamburger', 'ellipsis'];

    public static function pickStyle(string $name): SpinStyle
    {
        return match (strtolower($name)) {
            'line'      => SpinStyle::line(),
            'dot'       => SpinStyle::dot(),
            // 'mini-dot' is the spelling the README and gum docs use;
            // 'minidot' is the internal style name. Both are accepted.
            'minidot', 'mini-dot' => SpinStyle::miniDot(),
            'points'    => SpinStyle::points(),
            'pulse'     => SpinStyle::pulse(),
            'globe'     => SpinStyle::globe(),
            'meter'     => SpinStyle::meter(),
            'jump'      => SpinStyle::jump(),
            'moon'      => SpinStyle::moon(),
            'monkey'    => SpinStyle::monkey(),
            'hamburger' => SpinStyle::hamburger(),
            'ellipsis'  => SpinStyle::ellipsis(),
            default     => throw new \InvalidArgumentException(Lang::t('spinner.unknown_style', ['name' => $name])),
        };
    }
}
