<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Cli\Internal\SafeText;
use SugarCraft\Cli\Internal\SpecView;

/**
 * A man page in roff (man(7) macros) generated from a {@see CommandSpec}.
 *
 *     file_put_contents('candy-code.1', Manpage::render($spec));
 *     // man ./candy-code.1
 *
 * Sections: NAME (the command and its description), SYNOPSIS, OPTIONS
 * (the root's ungrouped options), one section per option group, COMMANDS
 * (every subcommand at any depth, by full name, with its synopsis,
 * description and own options) and ENVIRONMENT (each variable an option
 * falls back to). Option entries carry the same required/default/allowed
 * value notes as {@see HelpPage}.
 *
 * The section names follow man-page convention and stay in English, as
 * man(1) readers and tools such as `apropos` expect. Every caller string is
 * flattened to one line, stripped of control bytes and roff-escaped, so a
 * description cannot inject a request.
 *
 * Original work: no charmbracelet upstream renders man pages from a spec
 * (fang delegates to cobra's doc generator via muesli/mango).
 */
final class Manpage
{
    private function __construct() {}

    /**
     * @param int $section man section number; 1 (user commands) by default
     * @param string $date shown in the page footer; empty by default, so the
     *        output does not change from one day to the next
     * @throws \InvalidArgumentException when `$section` is not 1 to 9
     */
    public static function render(CommandSpec $spec, int $section = 1, string $date = ''): string
    {
        if ($section < 1 || $section > 9) {
            throw new \InvalidArgumentException(sprintf('Man section must be 1 to 9, got %d.', $section));
        }
        $name = $spec->name();
        $lines = [
            '.TH ' . self::quoted(mb_strtoupper($name, 'UTF-8')) . ' ' . $section . ' ' . self::quoted($date) . ' '
                . self::quoted($name) . ' ' . self::quoted($section === 1 ? 'User Commands' : ''),
            '.SH NAME',
            self::escape($name) . ($spec->description() === '' ? '' : ' \- ' . self::escape($spec->description())),
            '.SH SYNOPSIS',
            ...self::synopsis([$spec]),
        ];

        foreach ($spec->groups() as $group => $options) {
            $lines[] = '.SH ' . self::quoted((string) $group === '' ? 'OPTIONS' : mb_strtoupper((string) $group, 'UTF-8'));
            array_push($lines, ...self::options($options));
        }

        $commands = self::commands([$spec]);
        if ($commands !== []) {
            array_push($lines, '.SH COMMANDS', ...$commands);
        }

        $environment = self::environment($spec);
        if ($environment !== []) {
            array_push($lines, '.SH ENVIRONMENT', ...$environment);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param non-empty-list<CommandSpec> $path
     * @return list<string>
     */
    private static function synopsis(array $path): array
    {
        $tail = SpecView::synopsisTail($path, 'options', 'command', 'arg');
        $lines = ['.B ' . self::escape(SpecView::commandLine($path))];
        if ($tail !== '') {
            $lines[] = self::escape($tail);
        }
        return $lines;
    }

    /**
     * Every subcommand below the end of `$path`, depth first.
     *
     * @param non-empty-list<CommandSpec> $path
     * @return list<string>
     */
    private static function commands(array $path): array
    {
        $lines = [];
        foreach ($path[count($path) - 1]->subcommands() as $sub) {
            $subPath = [...$path, $sub];
            $tail = SpecView::synopsisTail($subPath, 'options', 'command', 'arg');
            $lines[] = '.TP';
            $lines[] = '\fB' . self::escape(SpecView::commandLine($subPath)) . '\fR' . ($tail === '' ? '' : ' ' . self::escape($tail));
            if ($sub->description() !== '') {
                $lines[] = self::escape($sub->description());
            }
            $own = array_values($sub->options());
            if ($own !== []) {
                array_push($lines, '.RS', ...self::options($own));
                $lines[] = '.RE';
            }
            array_push($lines, ...self::commands($subPath));
        }
        return $lines;
    }

    /**
     * @param list<Option> $options
     * @return list<string>
     */
    private static function options(array $options): array
    {
        $lines = [];
        foreach ($options as $option) {
            $term = '\fB' . self::escape($option->display()) . '\fR';
            if ($option->short() !== null) {
                $term = '\fB\-' . self::escape($option->short()) . '\fR, ' . $term;
            }
            if ($option->takesValue()) {
                $term .= ' \fI' . self::escape($option->valueName()) . '\fR';
            }
            if ($option->repeatable()) {
                $term .= '...';
            }
            $notes = [];
            if ($option->required()) {
                $notes[] = 'Required.';
            }
            $default = SpecView::defaultText($option);
            if ($default !== null) {
                $notes[] = 'Default: ' . $default . '.';
            }
            if ($option->choices() !== []) {
                $notes[] = 'Possible values: ' . implode(', ', $option->choices()) . '.';
            }
            if ($option->env() !== null) {
                $notes[] = 'Environment: ' . $option->env() . '.';
            }
            array_push($lines, '.TP', $term);
            if ($option->description() !== '') {
                $lines[] = self::escape($option->description());
            }
            if ($notes !== []) {
                // A line break keeps the notes apart from a description that
                // may not end in a full stop.
                if ($option->description() !== '') {
                    $lines[] = '.br';
                }
                $lines[] = self::escape(implode(' ', $notes));
            }
        }
        return $lines;
    }

    /** @return list<string> */
    private static function environment(CommandSpec $root): array
    {
        $lines = [];
        $walk = static function (array $path) use (&$walk, &$lines): void {
            $leaf = $path[count($path) - 1];
            foreach ($leaf->options() as $option) {
                if ($option->env() !== null) {
                    $lines[] = '.TP';
                    $lines[] = '.B ' . self::escape($option->env());
                    $lines[] = self::escape('Used for ' . $option->display() . ' of ' . SpecView::commandLine($path) . ' when it is not given.');
                }
            }
            foreach ($leaf->subcommands() as $sub) {
                $walk([...$path, $sub]);
            }
        };
        $walk([$root]);
        return $lines;
    }

    /**
     * One line of text, safe as roff: control bytes removed, backslashes and
     * hyphens escaped, and a leading `.` or `'` neutralized so the line is
     * never read as a request.
     */
    private static function escape(string $text): string
    {
        $text = str_replace(['\\', '-'], ['\\e', '\\-'], SafeText::line($text));
        return $text !== '' && ($text[0] === '.' || $text[0] === "'") ? '\\&' . $text : $text;
    }

    /** A double-quoted macro argument (`"` itself becomes `\(dq`). */
    private static function quoted(string $text): string
    {
        return '"' . str_replace('"', '\\(dq', self::escape($text)) . '"';
    }
}
