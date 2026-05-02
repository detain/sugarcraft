<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Shell\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print a styled log line. The level badge (DEBUG / INFO / WARN / ERROR
 * / FATAL) is rendered in a colour matching the level; the message text
 * follows verbatim. Useful for shell scripts that want consistent log
 * output without pulling in a logging framework.
 */
#[AsCommand(name: 'log', description: 'Print a styled log line at the given level.')]
final class LogCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('message', InputArgument::IS_ARRAY, 'Message text. Empty = read from stdin.')
            ->addOption('level',  null, InputOption::VALUE_REQUIRED, 'debug|info|warn|error|fatal', 'info');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $level   = LogLevel::fromString((string) $input->getOption('level'));
        $message = $input->getArgument('message') ?: [];
        $text    = $message === [] ? self::readStdin() : implode(' ', $message);

        $output->writeln(self::format($level, $text));
        return $level === LogLevel::Fatal ? 1 : Command::SUCCESS;
    }

    /** Render `BADGE message` with the level's style applied to the badge. */
    public static function format(LogLevel $level, string $message): string
    {
        return $level->style()->render($level->badge()) . ' ' . $message;
    }

    private static function readStdin(): string
    {
        if (!defined('STDIN') || !is_resource(STDIN) || @stream_isatty(STDIN)) {
            return '';
        }
        return rtrim((string) stream_get_contents(STDIN), "\n");
    }
}
