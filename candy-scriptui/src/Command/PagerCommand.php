<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\Core\Program;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\PagerModel;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
use SugarCraft\ScriptUi\Runtime\SignalExit;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scroll long input. Reads from stdin or `--file` and lets the user
 * navigate with the standard pager keys (↑/↓ / PgUp / PgDn / Home / End
 * / `q` to quit). Quitting with q or Esc exits 0; Ctrl+C exits 130, a
 * --timeout deadline exits 124, and an OS signal exits 128+signum.
 */
#[AsCommand(name: 'pager', description: 'Scroll long input in a fullscreen pager.')]
final class PagerCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('file',               'f',  InputOption::VALUE_REQUIRED, 'Input file. Default: stdin.')
            ->addOption('width',              null, InputOption::VALUE_REQUIRED, 'Pager width in cells (0 = terminal size).',  0)
            ->addOption('height',             null, InputOption::VALUE_REQUIRED, 'Pager height in rows (0 = terminal size).', 0)
            ->addOption('show-line-numbers',  null, InputOption::VALUE_NONE,     'Prefix every line with a 1-based line number.')
            ->addOption('match',              null, InputOption::VALUE_REQUIRED, 'Highlight every case-insensitive occurrence of the substring.', '')
            ->addOption('soft-wrap',          null, InputOption::VALUE_NONE,     'Wrap long lines at the pager width instead of clipping.')
            ->addOption('show-help',          null, InputOption::VALUE_NONE,     'Alias for --help (gum compat).')
            ->addOption('no-alt-screen',      null, InputOption::VALUE_NONE,     'Render inline instead of the alternate screen (keeps output in scrollback).')
            ->addOption('timeout',            null, InputOption::VALUE_REQUIRED, 'Auto-exit after N seconds (0 = none).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeoutSeconds = 0.0;
        if (!TimeoutGuard::tryParseSeconds($input->getOption('timeout'), $timeoutSeconds)) {
            // Refuse a typo'd deadline BEFORE reading the file/stdin — and
            // never let it silently disable itself (fail-open).
            fwrite(STDERR, Lang::t('runtime.bad_timeout', ['value' => (string) $input->getOption('timeout')]) . "\n");
            return Command::FAILURE;
        }

        $file = $input->getOption('file');
        if (is_string($file) && $file !== '') {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                fwrite(STDERR, Lang::t('io.read_failed', ['path' => $file]) . "\n");
                return Command::FAILURE;
            }
        } else {
            $raw = self::readStdin();
        }

        // --soft-wrap pre-wraps lines at the pager width before
        // feeding the viewport, so long source lines stay visible
        // without horizontal scroll.
        if ((bool) $input->getOption('soft-wrap')) {
            $width = (int) $input->getOption('width');
            if ($width <= 0) {
                $width = \SugarCraft\ScriptUi\Runtime\Geometry::columnsOrNull() ?? 80;
            }
            $raw = self::prewrapSoftWrap($raw, $width);
        }

        $model   = PagerModel::fromContent(
            $raw,
            (int)    $input->getOption('width'),
            (int)    $input->getOption('height'),
            (bool)   $input->getOption('show-line-numbers'),
            (string) $input->getOption('match'),
        );
        $loop    = \React\EventLoop\Loop::get();
        $options = InteractiveRuntime::programOptions(
            useAltScreen: ! (bool) $input->getOption('no-alt-screen'),
            hideCursor:   true,
        );
        if ($options === null) {
            fwrite(STDERR, Lang::t('runtime.no_terminal', ['command' => 'pager']) . "\n");
            return Command::FAILURE;
        }
        $program = new Program($model, $options);
        $guard = TimeoutGuard::arm($loop, $timeoutSeconds, fn () => $program->kill());
        /** @var PagerModel $final */
        $final = $program->run();
        $guard->disarm();

        // Deadline elapsed — the pager auto-exits with the timeout code.
        if ($guard->fired()) {
            return TimeoutGuard::EXIT_TIMEOUT;
        }
        // Ctrl-C / SIGTERM: report the conventional 128+signal exit rather
        // than pretending the page was read to the end.
        $signalExit = SignalExit::code($program->stopSignal());
        if ($signalExit !== null) {
            return $signalExit;
        }

        // Typed Ctrl+C (KeyMsg — raw mode clears ISIG) must not exit 0 as
        // if the page had been read; q/Esc stay a normal 0 finish.
        if ($final->isInterrupted()) {
            return SignalExit::EXIT_INTERRUPTED;
        }
        return Command::SUCCESS;
    }

    /**
     * scriptui-21: --soft-wrap must only change line breaks, never
     * styling. Width::wrap begins with Ansi::strip, so colourised input
     * (ls --color, git diff) arrived at the viewport unstyled while the
     * non-wrap clip path is ANSI-aware. Width::wrapAnsi keeps every CSI
     * sequence intact and holds the visible width contract; an active
     * SGR simply continues across the break.
     */
    public static function prewrapSoftWrap(string $raw, int $width): string
    {
        return \SugarCraft\Core\Util\Width::wrapAnsi($raw, max(1, $width));
    }

    private static function readStdin(): string
    {
        if (!defined('STDIN') || !is_resource(STDIN) || @stream_isatty(STDIN)) {
            return '';
        }
        return (string) stream_get_contents(STDIN);
    }
}
