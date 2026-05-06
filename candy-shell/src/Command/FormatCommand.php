<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Shine\Renderer;
use CandyCore\Shine\Theme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Render a Markdown file (or stdin) as styled ANSI text using
 * {@see \CandyCore\Shine\Renderer}. Non-interactive — produces output
 * straight to stdout so it pipelines naturally:
 *
 *   $ candyshell format README.md
 *   $ git log -1 --pretty=%B | candyshell format
 */
#[AsCommand(name: 'format', description: 'Render Markdown as styled ANSI text.')]
final class FormatCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('file',  InputArgument::OPTIONAL, 'Input file. Default: stdin.')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'ansi | plain | dark | light | dracula | tokyo-night | pink | notty | ascii', 'ansi')
            ->addOption('type',  't',  InputOption::VALUE_REQUIRED, 'Render type: markdown | code | template | emoji. Default markdown.', 'markdown')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'Source language for `--type code`.', '')
            ->addOption('strip-ansi', null, InputOption::VALUE_NONE, 'Strip ANSI escapes from the rendered output.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $raw  = is_string($file) && $file !== ''
            ? @file_get_contents($file)
            : self::readStdin();
        if (!is_string($raw)) {
            return Command::FAILURE;
        }

        $type = strtolower((string) $input->getOption('type'));
        $rendered = match ($type) {
            'markdown', '' => (new Renderer(self::pickTheme((string) $input->getOption('theme'))))->render($raw),
            'code'         => self::renderCode($raw, (string) $input->getOption('language'), (string) $input->getOption('theme')),
            'template'     => self::renderTemplate($raw),
            'emoji'        => self::renderEmoji($raw),
            default        => throw new \InvalidArgumentException("unknown --type: $type"),
        };

        if ($input->getOption('strip-ansi')) {
            $rendered = (string) preg_replace("/\x1b\[[0-9;:]*[A-Za-z]/", '', $rendered);
        }

        $output->writeln($rendered);
        return Command::SUCCESS;
    }

    public static function pickTheme(string $name): Theme
    {
        // Lean on Theme::byName() (case-insensitive, hyphen/underscore-tolerant)
        // so callers stay aligned with CandyShine's full theme set.
        $theme = Theme::byName($name);
        if ($theme === null) {
            throw new \InvalidArgumentException("unknown theme: $name");
        }
        return $theme;
    }

    /**
     * Render `--type code <body>` as a fenced code block of the given
     * language. Equivalent to wrapping the input in ``` fences and
     * piping through the markdown pipeline.
     */
    private static function renderCode(string $raw, string $lang, string $themeName): string
    {
        $body = "```$lang\n" . rtrim($raw, "\n") . "\n```\n";
        return (new Renderer(self::pickTheme($themeName)))->render($body);
    }

    /**
     * `--type template` expands `{{var}}` placeholders from environment
     * variables and emits the result. Mirrors gum's lightweight
     * template mode (no Go template-function support).
     */
    private static function renderTemplate(string $raw): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            static fn (array $m) => (string) (getenv($m[1]) ?: ''),
            $raw,
        );
    }

    /**
     * `--type emoji` expands `:smile:` shortcodes via the small
     * built-in mapping. Unknown shortcodes pass through verbatim
     * so callers can mix emoji + text without breakage.
     */
    private static function renderEmoji(string $raw): string
    {
        $map = [
            'smile'        => '😄', 'grin'        => '😁',
            'heart'        => '❤️', 'fire'        => '🔥',
            'rocket'       => '🚀', 'star'        => '⭐',
            'thumbsup'     => '👍', 'thumbsdown'  => '👎',
            'check'        => '✅', 'x'           => '❌',
            'warning'      => '⚠️',  'info'        => 'ℹ️',
            'tada'         => '🎉', 'sparkles'    => '✨',
            'candy'        => '🍬', 'sugar'       => '🍭',
            'honey'        => '🍯',
        ];
        return (string) preg_replace_callback(
            '/:([a-z0-9_+-]+):/i',
            static fn (array $m) => $map[strtolower($m[1])] ?? $m[0],
            $raw,
        );
    }

    private static function readStdin(): string
    {
        if (!defined('STDIN') || !is_resource(STDIN) || @stream_isatty(STDIN)) {
            return '';
        }
        return (string) stream_get_contents(STDIN);
    }
}
