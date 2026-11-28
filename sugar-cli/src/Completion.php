<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Cli\Internal\SafeText;
use SugarCraft\Cli\Internal\SpecView;

/**
 * Shell completion scripts generated from a {@see CommandSpec}.
 *
 *     echo Completion::script($spec, Shell::Zsh);
 *
 * Every script walks the words already typed the way {@see CommandSpec::parse()}
 * does: a subcommand name descends into that subcommand (only before the
 * first positional argument), a value option consumes the next word, and
 * `--` ends option parsing. It then offers, for the word under the cursor:
 *
 * - the allowed values ({@see Option::withChoices()}, {@see Option::withEnum()})
 *   after a value option, or file names when the value is free-form;
 * - the options the command accepts (its own plus persistent ones from its
 *   ancestors) when the word starts with `-`, long and short spellings,
 *   with their descriptions where the shell shows them;
 * - the subcommands otherwise, falling back to file names.
 *
 * Install: bash, `source <(app completion bash)` or a file in
 * bash-completion's directory; zsh, a file named `_app` on `$fpath` or
 * `source <(app completion zsh)` after `compinit`; fish, a file in
 * `~/.config/fish/completions/app.fish`. (`app completion <shell>` is
 * whatever command the application wires to this method.)
 *
 * Every name, value and description is emitted as a quoted literal and
 * compared as a string, never expanded as a pattern or a command, so a spec
 * cannot inject shell code into the script. Descriptions are flattened to
 * one line with control bytes removed.
 *
 * Limits, all on the conservative side: bash splits `--name=value` at `=`
 * (the value after it is still completed); zsh and fish do not complete a
 * value written inline as `--name=`; a value option inside a short-option
 * group (`-vm value`) is not recognized; options already given are offered
 * again.
 *
 * Original work: no charmbracelet upstream generates completions from a
 * spec (fang hands that to spf13/cobra).
 */
final class Completion
{
    private function __construct() {}

    /** The completion script for `$spec`'s command in `$shell`. */
    public static function script(CommandSpec $spec, Shell $shell): string
    {
        $states = self::states($spec);
        return match ($shell) {
            Shell::Bash => self::bash($spec->name(), $states),
            Shell::Zsh => self::zsh($spec->name(), $states),
            Shell::Fish => self::fish($spec->name(), $states),
        };
    }

    /**
     * One state per command in the tree, depth first from the root (state 0).
     *
     * @return list<array{subs: array<string, int>, options: list<Option>, subDescriptions: array<string, string>}>
     */
    private static function states(CommandSpec $root): array
    {
        $states = [];
        $visit = static function (array $path) use (&$visit, &$states): int {
            $id = count($states);
            $states[$id] = ['subs' => [], 'options' => SpecView::visible($path), 'subDescriptions' => []];
            foreach ($path[count($path) - 1]->subcommands() as $sub) {
                $name = $sub->name();
                $states[$id]['subDescriptions'][$name] = SafeText::line($sub->description());
                $states[$id]['subs'][$name] = $visit([...$path, $sub]);
            }
            return $id;
        };
        $visit([$root]);
        return $states;
    }

    /**
     * @param list<array{subs: array<string, int>, options: list<Option>, subDescriptions: array<string, string>}> $states
     */
    private static function bash(string $name, array $states): string
    {
        $fn = self::functionName($name);
        $q = self::shQuote(...);

        $walk = [];
        $choices = [];
        $optionWords = [];
        $commandWords = [];
        foreach ($states as $id => $state) {
            $walk[] = "            {$id})";
            $walk[] = '                case "$word" in';
            foreach ($state['options'] as $option) {
                if ($option->takesValue()) {
                    $walk[] = '                    ' . implode('|', array_map($q, self::spellings($option))) . ') pending=' . $q($option->name()) . ' ;;';
                    if ($option->choices() !== []) {
                        $choices[] = '            ' . $q($id . ':' . $option->name()) . ') __' . $fn . '_filter "$cur" ' . implode(' ', array_map($q, $option->choices())) . '; return ;;';
                    }
                }
            }
            foreach ($state['subs'] as $sub => $subId) {
                $walk[] = '                    ' . $q((string) $sub) . ") (( args )) || state={$subId} ;;";
            }
            $walk[] = '                    -*) ;;';
            $walk[] = '                    *) args=1 ;;';
            $walk[] = '                esac';
            $walk[] = '                ;;';

            $words = [];
            foreach ($state['options'] as $option) {
                array_push($words, ...self::spellings($option));
            }
            if ($words !== []) {
                $optionWords[] = "            {$id}) __{$fn}_filter \"\$cur\" " . implode(' ', array_map($q, $words)) . ' ;;';
            }
            if ($state['subs'] !== []) {
                $commandWords[] = "            {$id}) __{$fn}_filter \"\$cur\" " . implode(' ', array_map(static fn ($s): string => self::shQuote((string) $s), array_keys($state['subs']))) . '; return ;;';
            }
        }

        $lines = [
            '# bash completion for ' . SafeText::line($name),
            '# Generated by sugar-cli from the command spec; do not edit.',
            '',
            "__{$fn}_filter() {",
            '    local cur="$1" word',
            '    shift',
            '    COMPREPLY=()',
            '    for word in "$@"; do',
            '        [[ $word == "$cur"* ]] && COMPREPLY+=("$word")',
            '    done',
            '    return 0',
            '}',
            '',
            "_{$fn}() {",
            '    local cur="${COMP_WORDS[COMP_CWORD]}"',
            '    local state=0 pending="" ended=0 args=0 word i',
            '    for (( i = 1; i < COMP_CWORD; i++ )); do',
            '        word="${COMP_WORDS[i]}"',
            '        if [[ -n $pending ]]; then',
            '            # bash splits --name=value at "="; the value is the word after it.',
            '            [[ $word == "=" ]] || pending=""',
            '            continue',
            '        fi',
            '        (( ended )) && continue',
            '        if [[ $word == "--" ]]; then',
            '            ended=1',
            '            continue',
            '        fi',
            '        case "$state" in',
            ...$walk,
            '        esac',
            '    done',
            '    if [[ -n $pending ]]; then',
            '        [[ $cur == "=" ]] && cur=""',
        ];
        if ($choices !== []) {
            array_push($lines, '        case "$state:$pending" in', ...$choices);
            $lines[] = '        esac';
        }
        array_push(
            $lines,
            '        COMPREPLY=()',
            '        return 0',
            '    fi',
            '    if (( ended )); then',
            '        COMPREPLY=()',
            '        return 0',
            '    fi',
            '    if [[ $cur == -* ]]; then',
            '        COMPREPLY=()',
        );
        if ($optionWords !== []) {
            array_push($lines, '        case "$state" in', ...$optionWords);
            $lines[] = '        esac';
        }
        array_push($lines, '        return 0', '    fi');
        if ($commandWords !== []) {
            array_push($lines, '    if (( args == 0 )); then', '        case "$state" in', ...$commandWords);
            array_push($lines, '        esac', '    fi');
        }
        array_push(
            $lines,
            '    COMPREPLY=()',
            '    return 0',
            '}',
            '',
            "complete -o default -F _{$fn} " . $q($name),
        );
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array{subs: array<string, int>, options: list<Option>, subDescriptions: array<string, string>}> $states
     */
    private static function zsh(string $name, array $states): string
    {
        $fn = self::functionName($name);
        $q = self::shQuote(...);

        $walk = [];
        $choices = [];
        $optionLists = [];
        $commandLists = [];
        foreach ($states as $id => $state) {
            $walk[] = "            {$id})";
            $walk[] = '                case "$word" in';
            foreach ($state['options'] as $option) {
                if ($option->takesValue()) {
                    $walk[] = '                    ' . implode('|', array_map($q, self::spellings($option))) . ') pending=' . $q($option->name()) . ' ;;';
                    if ($option->choices() !== []) {
                        $choices[] = '            ' . $q($id . ':' . $option->name()) . ') compadd -- ' . implode(' ', array_map($q, $option->choices())) . '; return ;;';
                    }
                }
            }
            foreach ($state['subs'] as $sub => $subId) {
                $walk[] = '                    ' . $q((string) $sub) . ") (( args )) || cstate={$subId} ;;";
            }
            $walk[] = '                    -*) ;;';
            $walk[] = '                    *) args=1 ;;';
            $walk[] = '                esac';
            $walk[] = '                ;;';

            $entries = [];
            foreach ($state['options'] as $option) {
                $description = SafeText::line($option->description());
                foreach (self::spellings($option) as $spelling) {
                    $entries[] = $q(self::describeEntry($spelling, $description));
                }
            }
            if ($entries !== []) {
                $optionLists[] = "            {$id}) candidates=(" . implode(' ', $entries) . ') ;;';
            }
            if ($state['subs'] !== []) {
                $commands = [];
                foreach ($state['subDescriptions'] as $sub => $description) {
                    $commands[] = $q(self::describeEntry((string) $sub, $description));
                }
                $commandLists[] = "            {$id}) candidates=(" . implode(' ', $commands) . "); _describe -t commands 'command' candidates; return ;;";
            }
        }

        $lines = [
            '#compdef ' . $name,
            '# zsh completion for ' . SafeText::line($name),
            '# Generated by sugar-cli from the command spec; do not edit.',
            '',
            "_{$fn}() {",
            '    local cur="${words[CURRENT]}"',
            '    local cstate=0 pending="" ended=0 args=0 word i',
            '    local -a candidates',
            '    for (( i = 2; i < CURRENT; i++ )); do',
            '        word="${words[i]}"',
            '        if [[ -n $pending ]]; then',
            '            pending=""',
            '            continue',
            '        fi',
            '        (( ended )) && continue',
            '        if [[ $word == "--" ]]; then',
            '            ended=1',
            '            continue',
            '        fi',
            '        case "$cstate" in',
            ...$walk,
            '        esac',
            '    done',
            '    if [[ -n $pending ]]; then',
        ];
        if ($choices !== []) {
            array_push($lines, '        case "$cstate:$pending" in', ...$choices);
            $lines[] = '        esac';
        }
        array_push(
            $lines,
            '        _files',
            '        return',
            '    fi',
            '    if (( ended )); then',
            '        _files',
            '        return',
            '    fi',
            '    if [[ $cur == -* ]]; then',
        );
        if ($optionLists !== []) {
            array_push($lines, '        case "$cstate" in', ...$optionLists);
            array_push($lines, '        esac', "        (( \${#candidates} )) && _describe -t options 'option' candidates");
        }
        array_push($lines, '        return', '    fi');
        if ($commandLists !== []) {
            array_push($lines, '    if (( args == 0 )); then', '        case "$cstate" in', ...$commandLists);
            array_push($lines, '        esac', '    fi');
        }
        array_push(
            $lines,
            '    _files',
            '}',
            '',
            "if [[ \$funcstack[1] == _{$fn} ]]; then",
            "    _{$fn} \"\$@\"",
            'else',
            "    compdef _{$fn} " . $q($name),
            'fi',
        );
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array{subs: array<string, int>, options: list<Option>, subDescriptions: array<string, string>}> $states
     */
    private static function fish(string $name, array $states): string
    {
        $fn = self::functionName($name);
        $q = self::fishQuote(...);

        $walk = [];
        $choices = [];
        $optionLists = [];
        $commandLists = [];
        foreach ($states as $id => $state) {
            $branches = [];
            foreach ($state['options'] as $option) {
                if ($option->takesValue()) {
                    $branches[] = ['contains -- "$word" ' . implode(' ', array_map($q, self::spellings($option))), 'set pending ' . $q($option->name())];
                    if ($option->choices() !== []) {
                        $choices[] = '        if test "$cstate:$pending" = ' . $q($id . ':' . $option->name());
                        $choices[] = "            printf '%s\\n' " . implode(' ', array_map($q, $option->choices()));
                        $choices[] = '            return';
                        $choices[] = '        end';
                    }
                }
            }
            foreach ($state['subs'] as $sub => $subId) {
                $branches[] = ['test $args = 0; and test "$word" = ' . $q((string) $sub), "set cstate {$subId}"];
            }
            $branches[] = ["not string match -q -- '-*' \"\$word\"", 'set args 1'];

            $walk[] = ($id === 0 ? '        if' : '        else if') . " test \$cstate = {$id}";
            foreach ($branches as $i => [$condition, $action]) {
                $walk[] = ($i === 0 ? '            if ' : '            else if ') . $condition;
                $walk[] = '                ' . $action;
            }
            $walk[] = '            end';

            $candidates = [];
            foreach ($state['options'] as $option) {
                $description = SafeText::line($option->description());
                foreach (self::spellings($option) as $spelling) {
                    $candidates[] = self::fishCandidate($spelling, $description);
                }
            }
            if ($candidates !== []) {
                $optionLists[] = "        if test \$cstate = {$id}";
                array_push($optionLists, ...$candidates);
                $optionLists[] = '        end';
            }
            if ($state['subs'] !== []) {
                $commandLists[] = "        if test \$cstate = {$id}";
                foreach ($state['subDescriptions'] as $sub => $description) {
                    $commandLists[] = self::fishCandidate((string) $sub, $description);
                }
                $commandLists[] = '            return';
                $commandLists[] = '        end';
            }
        }
        $walk[] = '        end';

        $lines = [
            '# fish completion for ' . SafeText::line($name),
            '# Generated by sugar-cli from the command spec; do not edit.',
            '',
            "function __{$fn}_complete",
            '    set -l tokens (commandline -opc)',
            '    set -e tokens[1]',
            '    set -l cur (commandline -ct)',
            '    set -l cstate 0',
            "    set -l pending ''",
            '    set -l ended 0',
            '    set -l args 0',
            '    for word in $tokens',
            '        if test -n "$pending"',
            "            set pending ''",
            '            continue',
            '        end',
            '        if test $ended = 1',
            '            continue',
            '        end',
            "        if test \"\$word\" = '--'",
            '            set ended 1',
            '            continue',
            '        end',
            ...$walk,
            '    end',
            '    if test -n "$pending"',
            ...$choices,
            '        __fish_complete_path "$cur"',
            '        return',
            '    end',
            '    if test $ended = 1',
            '        __fish_complete_path "$cur"',
            '        return',
            '    end',
            "    if string match -q -- '-*' \"\$cur\"",
            ...$optionLists,
            '        return',
            '    end',
        ];
        if ($commandLists !== []) {
            array_push($lines, '    if test $args = 0', ...$commandLists);
            $lines[] = '    end';
        }
        array_push(
            $lines,
            '    __fish_complete_path "$cur"',
            'end',
            '',
            'complete -c ' . $q($name) . " -f -a '(__{$fn}_complete)'",
        );
        return implode("\n", $lines) . "\n";
    }

    /** @return list<string> `--name`, then `-x` when the option has a short alias */
    private static function spellings(Option $option): array
    {
        $short = $option->short();
        return $short === null ? [$option->display()] : [$option->display(), '-' . $short];
    }

    /** A shell function name derived from the command name: anything but `[A-Za-z0-9_]` becomes `_`. */
    private static function functionName(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    }

    /** A POSIX single-quoted literal (bash and zsh). */
    private static function shQuote(string $s): string
    {
        return "'" . str_replace("'", "'\\''", $s) . "'";
    }

    /** A fish single-quoted literal: only `\` and `'` are special inside. */
    private static function fishQuote(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
    }

    /** A zsh `_describe` entry, `name:description`, with `\` and `:` in the name escaped. */
    private static function describeEntry(string $name, string $description): string
    {
        $escaped = str_replace(['\\', ':'], ['\\\\', '\\:'], $name);
        return $description === '' ? $escaped : $escaped . ':' . $description;
    }

    /** One fish candidate line: the word, then a tab and its description when it has one. */
    private static function fishCandidate(string $word, string $description): string
    {
        return $description === ''
            ? "            printf '%s\\n' " . self::fishQuote($word)
            : "            printf '%s\\t%s\\n' " . self::fishQuote($word) . ' ' . self::fishQuote($description);
    }
}
