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
            ->addOption('level',     null,  InputOption::VALUE_REQUIRED, 'debug|info|warn|error|fatal', 'info')
            ->addOption('min-level', null,  InputOption::VALUE_REQUIRED, 'Suppress messages below this level.', 'debug')
            ->addOption('prefix',    null,  InputOption::VALUE_REQUIRED, 'Static prefix prepended to the badge.', '')
            ->addOption('time',      't',   InputOption::VALUE_REQUIRED, 'PHP date() format for a leading timestamp (empty = none).', '')
            ->addOption('file',      'o',   InputOption::VALUE_REQUIRED, 'Append output to this file instead of stdout.', '')
            ->addOption('format',    'f',   InputOption::VALUE_REQUIRED, 'printf format for the message; "%s" is the text.', '')
            ->addOption('formatter', null,  InputOption::VALUE_REQUIRED, 'Output formatter: text (default) | json | logfmt.', 'text')
            ->addOption('structured', 's',  InputOption::VALUE_NONE,    'Alias for --formatter logfmt.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $level    = LogLevel::fromString((string) $input->getOption('level'));
        $minLevel = LogLevel::fromString((string) $input->getOption('min-level'));
        if ($level->order() < $minLevel->order()) {
            return Command::SUCCESS;
        }
        $message = $input->getArgument('message') ?: [];
        $text    = $message === [] ? self::readStdin() : implode(' ', $message);

        $fmt = (string) $input->getOption('format');
        if ($fmt !== '') {
            $text = sprintf($fmt, $text);
        }

        $time   = (string) $input->getOption('time');
        $prefix = (string) $input->getOption('prefix');
        $formatter = $input->getOption('structured')
            ? 'logfmt'
            : strtolower((string) $input->getOption('formatter'));

        $line = self::formatLine($level, $text, $prefix, $time, $formatter);

        $file = (string) $input->getOption('file');
        if ($file !== '') {
            @file_put_contents($file, $line . "\n", FILE_APPEND);
        } else {
            $output->writeln($line);
        }
        return $level === LogLevel::Fatal ? 1 : Command::SUCCESS;
    }

    /** Render `BADGE message` with the level's style applied to the badge. */
    public static function format(LogLevel $level, string $message): string
    {
        return $level->style()->render($level->badge()) . ' ' . $message;
    }

    /**
     * Format a line with optional prefix / timestamp / structured shape.
     * Mirrors the gum log surface: text (default), json, logfmt.
     */
    public static function formatLine(
        LogLevel $level,
        string $message,
        string $prefix = '',
        string $timeFormat = '',
        string $formatter = 'text',
    ): string {
        $ts = $timeFormat !== '' ? date($timeFormat) : '';
        return match ($formatter) {
            'json'   => json_encode([
                'time'    => $ts !== '' ? $ts : null,
                'level'   => strtolower($level->value),
                'prefix'  => $prefix !== '' ? $prefix : null,
                'message' => $message,
            ], JSON_UNESCAPED_SLASHES) ?: '',
            'logfmt' => self::asLogfmt([
                'time'    => $ts,
                'level'   => strtolower($level->value),
                'prefix'  => $prefix,
                'msg'     => $message,
            ]),
            default => self::asText($level, $message, $prefix, $ts),
        };
    }

    private static function asText(LogLevel $level, string $message, string $prefix, string $ts): string
    {
        $parts = [];
        if ($ts !== '') {
            $parts[] = $ts;
        }
        if ($prefix !== '') {
            $parts[] = $prefix;
        }
        $parts[] = $level->style()->render($level->badge());
        $parts[] = $message;
        return implode(' ', $parts);
    }

    /** @param array<string,string> $fields */
    private static function asLogfmt(array $fields): string
    {
        $out = [];
        foreach ($fields as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $needsQuote = preg_match('/[\s"=]/', $v) === 1;
            $val = $needsQuote
                ? '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"'
                : $v;
            $out[] = $k . '=' . $val;
        }
        return implode(' ', $out);
    }

    private static function readStdin(): string
    {
        if (!defined('STDIN') || !is_resource(STDIN) || @stream_isatty(STDIN)) {
            return '';
        }
        return rtrim((string) stream_get_contents(STDIN), "\n");
    }
}
