<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use React\EventLoop\LoopInterface;
use SugarCraft\Forms\Spinner\Style as SpinStyle;
use SugarCraft\Forms\Spinner\TickMsg;
use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\SpinModel;
use SugarCraft\ScriptUi\Process\Process;
use SugarCraft\ScriptUi\Process\RealProcess;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
use SugarCraft\ScriptUi\Runtime\SignalExit;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use SugarCraft\Pty\PtyException;
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
    /** Exit-watcher cadence — same 15 ms granularity as sugar-pty waitAsync (scriptui-10). */
    public const EXIT_POLL_SECONDS = 0.015;

    /** @var \Closure(list<string>, bool, bool): Process spawns the child */
    private readonly \Closure $processFactory;

    /** @var \Closure(Model, LoopInterface): Program builds the spinner runtime */
    private readonly \Closure $programFactory;

    /** scriptui-06 seam: true only in production (no test injects a default program factory). */
    private readonly bool $usesDefaultProgram;

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

        $this->usesDefaultProgram = $programFactory === null;

        $this->programFactory = $programFactory
            ?? static function (Model $model, LoopInterface $loop): Program {
                $options = InteractiveRuntime::programOptions(loop: $loop, useAltScreen: false, hideCursor: true);
                if ($options === null) {
                    // The old headless `?? new ProgramOptions(...)` fallback
                    // (scriptui-06, candy-scriptui-002/-005) seeded raw
                    // STDIN as a KEY channel and painted DEC-private modes
                    // onto a redirected data channel. A headless spin now
                    // never reaches a Program at all — execute() routes
                    // that shape to the direct-run arm. This throw is
                    // deliberately unreachable-by-construction: it stays so
                    // a future caller cannot revive the unsafe fallback.
                    throw new PtyException(Lang::t('runtime.no_terminal', ['command' => 'spin']));
                }
                return new Program($model, $options);
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
            ->addOption('show-output', null, InputOption::VALUE_NONE,    'Print captured stdout after the command exits (it is captured silently by default).')
            ->addOption('show-error',  null, InputOption::VALUE_NONE,    'Print captured stderr after the command exits (it is captured silently by default).')
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

        try {
            // scriptui-09 (candy-scriptui-053): the spinner arm always
            // captures the child stdio — inherited descriptors used to
            // leak child bytes onto OUR stdout even though nothing was
            // "shown" (option help and gum's default-silent posture imply
            // the opposite). Silence is now real: capture, bounded
            // retention (-03), print only on the flags. The headless arm
            // keeps inherited passthrough — there the wrapper contract IS
            // the child's stream (-06 purity pins).
            $headless = $this->usesDefaultProgram && InteractiveRuntime::isHeadless();
            $process = ($this->processFactory)(
                $argv,
                $headless ? $showOutput : true,
                $headless ? $showError : true,
            );
        } catch (PtyException $e) {
            // scriptui-08: a spawn-stage refusal (native Windows
            // unsupported, or a plain spawn failure) reaches the user as
            // a clean sentence + exit code — the matrix must never lie
            // with a raw exception frame.
            fwrite(STDERR, $e->getMessage() . "\n");
            return Command::FAILURE;
        }

        $loop = \React\EventLoop\Loop::get();

        // scriptui-06 (candy-scriptui-002/-005): with no terminal anywhere
        // (no stdout TTY, no /dev/tty, no stdin TTY) the spinner has nothing
        // honest to paint on and no safe place for a key reader — run the
        // child directly. Piped bytes stay DATA forever, and DEC-private
        // modes never touch a redirected data channel. Injected program
        // factories (every deterministic test) keep the Program arm exactly
        // as before.
        $code = $headless
            ? $this->runHeadless($process, $loop, $timeoutSeconds)
            : $this->runSpinner($process, $loop, $timeoutSeconds, $title, $style, $align);

        // Flush captured output on EVERY exit path — including the
        // timeout — so `spin --timeout N --show-output` never swallows
        // what the child managed to print.
        if ($showOutput) {
            $output->write($process->stdout(), false, OutputInterface::OUTPUT_RAW);
        }
        if ($showError) {
            fwrite(STDERR, $process->stderr());
        }
        // scriptui-03: a capped capture shows the head and says so — one
        // notice line on the diagnostics stream, the child's exit code
        // untouched (truncation is a capture limit, not the child failing).
        if (($showOutput && $process->stdoutDropped() > 0)
            || ($showError && $process->stderrDropped() > 0)) {
            fwrite(STDERR, Lang::t('spinner.output_truncated') . "\n");
        }
        $process->close();
        return $code;
    }

    /**
     * The terminal-less direct-run arm (scriptui-06): no Program, no reader
     * on STDIN, no renderer. Exit codes match the spinner arm — deadline
     * 124 first, the child's own status next, and a no-verdict fallback
     * that kills the child and returns 130.
     */
    private function runHeadless(Process $process, LoopInterface $loop, float $timeoutSeconds): int
    {
        $guard = TimeoutGuard::arm(
            $loop,
            $timeoutSeconds,
            // Two statements on fire, so a real closure (not an arrow fn).
            // A TERM-ignoring child must not spin this loop forever: the
            // grace timer stops it, and close() escalates to KILL after the
            // same grace the spinner arm's reap ladder uses.
            // scriptui-05 (candy-scriptui-037): every statement of the
            // callback is individually exception-proof — a terminate() that
            // throws must still arm the stop timer, and the whole deadline
            // must settle as 124 with a reap instead of unwinding the loop.
            function () use ($process, $loop): void {
                try {
                    $process->terminate();
                } catch (\Throwable) {
                    // Signalling the child failed; the loop stop and the
                    // finally-ladder close() below still bound the reap.
                }
                try {
                    $loop->addTimer(RealProcess::TERM_GRACE_SECONDS + 0.5, static function () use ($loop): void {
                        $loop->stop();
                    });
                } catch (\Throwable) {
                    // A dead loop cannot receive the stop; the watch poll
                    // settles once the child verdict (or close()) lands.
                }
            },
        );
        $process->attachPump($loop);

        $watch = $loop->addPeriodicTimer(0.05, function () use ($process, $loop): void {
            $process->drain(); // polling belt behind the pump (scriptui-02 pairing)
            if ($process->exited()) {
                $loop->stop();
            }
        });

        try {
            $loop->run();
        } finally {
            // scriptui-05 (candy-scriptui-035): the reap ladder runs on
            // EVERY exit path, including a Throwable that unwinds the loop
            // while the child is still alive. All steps are idempotent and
            // individually exception-proof: a broken rung must not skip
            // the rest of the settle.
            try {
                $loop->cancelTimer($watch);
            } catch (\Throwable) {
            }
            try {
                $process->detachPump();
            } catch (\Throwable) {
            }
            try {
                $guard->disarm();
            } catch (\Throwable) {
            }
            try {
                $process->terminate();
            } catch (\Throwable) {
            }
            try {
                $process->close();
            } catch (\Throwable) {
            }
        }

        $fired = $guard->fired();
        $guard->disarm();
        if ($fired) {
            return TimeoutGuard::EXIT_TIMEOUT;
        }
        $code = $process->exitCode();
        if ($code === null) {
            // Loop settled with no child verdict — same posture as the
            // spinner arm: treat it as cancellation and kill the child.
            $process->terminate();
            $code = self::EXIT_INTERRUPTED;
        }
        return $code;
    }

    /**
     * The interactive Program arm — the pre-scriptui-06 shape, now with no
     * unsafe headless fallback behind it.
     */
    private function runSpinner(Process $process, LoopInterface $loop, float $timeoutSeconds, string $title, SpinStyle $style, string $align): int
    {
        $model = SpinModel::spawn($process, $title, $style, $align);
        $guard = null;
        /** @var ?\React\EventLoop\TimerInterface $exitWatch */
        $exitWatch = null;
        try {
            $program = ($this->programFactory)($model, $loop);
            // Two statements on fire, so a real closure (not an arrow fn): the
            // deadline must both stop the UI AND signal the child — killing
            // the Program alone would orphan the still-running command.
            // scriptui-05 (candy-scriptui-037): the kill now leads and every
            // statement is exception-proof, so a terminate() that throws can
            // no longer unwind the loop past the 124 verdict — the UI stops
            // painting, the guard records fired, and the finally ladder reaps.
            $guard = TimeoutGuard::arm(
                $loop,
                $timeoutSeconds,
                function () use ($process, $program): void {
                    try {
                        $program->kill();
                    } catch (\Throwable) {
                    }
                    try {
                        $process->terminate();
                    } catch (\Throwable) {
                    }
                },
            );

            // scriptui-02 (the 64KiB cure): the capture pipes ride the run
            // loop as read streams, so a child writing more than the OS pipe
            // buffer keeps moving bytes instead of blocking while the spinner
            // polls an exit verdict that can never arrive. With no capture
            // pipes (--show-output/--show-error unset) the attach is a no-op.
            $process->attachPump($loop);

            // scriptui-10 (finding 096): exit detection used to ride the
            // animation tick alone — latency of a whole spinner interval,
            // and correctness coupled to the spinner still ticking. The
            // watcher polls at sugar-pty's waitAsync granularity (15 ms)
            // and wakes the model with its own tick; the animation tick
            // stays as the fallback belt. The belt also covers a child
            // that is already gone when the first frame would arrive.
            $exitWatch = $loop->addPeriodicTimer(self::EXIT_POLL_SECONDS, function () use ($process, $program, $model, $loop, &$exitWatch): void {
                if (!$process->exited()) {
                    return;
                }
                if ($exitWatch !== null) {
                    $loop->cancelTimer($exitWatch);
                    $exitWatch = null;
                }
                $program->send(new TickMsg($model->spinner->id));
            });

            /** @var SpinModel $final */
            $final = $program->run();
        } finally {
            if ($exitWatch !== null) {
                try {
                    $loop->cancelTimer($exitWatch);
                } catch (\Throwable) {
                }
            }
            // scriptui-05 (candy-scriptui-035): any Throwable between spawn
            // and settle — factory blowup, renderer crash, pump death — used
            // to unwind to Symfony's exit-1 with the child STILL RUNNING (and
            // wedged at 64KiB, the pump having died with the loop). The ladder
            // below runs on every path; each rung is idempotent and bounded.
            try {
                $guard?->disarm();
            } catch (\Throwable) {
            }
            try {
                $process->detachPump();
            } catch (\Throwable) {
            }
            try {
                $process->terminate();
            } catch (\Throwable) {
            }
            try {
                $process->close();
            } catch (\Throwable) {
            }
        }
        // (the finally ladder above already disarmed the guard and detached
        // the pump on every path — $guard is non-null here because run()
        // returned only after the arm above)

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
