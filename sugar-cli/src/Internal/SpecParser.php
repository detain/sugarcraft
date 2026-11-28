<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Internal;

use SugarCraft\Cli\CommandSpec;
use SugarCraft\Cli\Option;
use SugarCraft\Cli\OptionKind;
use SugarCraft\Cli\ParsedArgs;
use SugarCraft\Cli\UsageError;
use SugarCraft\Cli\UsageErrorKind;
use SugarCraft\Cli\ValueSource;
use SugarCraft\Cli\ValueType;

/**
 * The parse engine behind {@see CommandSpec::parse()}. Kept apart so the spec
 * stays a plain value object; the rules are documented there.
 *
 * @internal
 */
final class SpecParser
{
    private const TRUE_WORDS = ['1', 'true', 'yes', 'on'];
    private const FALSE_WORDS = ['0', 'false', 'no', 'off'];

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     */
    public static function parse(CommandSpec $root, array $argv, array $env): ParsedArgs|UsageError
    {
        // Validate every command path up front, so a malformed spec throws
        // whatever argv says instead of only once argv parses cleanly.
        self::assertTree([$root]);

        /** @var list<CommandSpec> $path */
        $path = [$root];
        $names = [$root->name()];
        [$long, $short] = self::visible($path);

        /** @var array<string, list<mixed>> $given  long name => converted argv values (a flag records true) */
        $given = [];
        /** @var array<string, int> $firstSeen  long name => argv index of first occurrence */
        $firstSeen = [];
        $arguments = [];
        $endOfOptions = false;
        $count = count($argv);

        for ($i = 0; $i < $count; $i++) {
            $token = $argv[$i];
            if ($endOfOptions) {
                $arguments[] = $token;
                continue;
            }
            if ($token === '--') {
                $endOfOptions = true;
                continue;
            }

            if (str_starts_with($token, '--')) {
                $eq = strpos($token, '=');
                $name = $eq === false ? substr($token, 2) : substr($token, 2, $eq - 2);
                $inline = $eq === false ? null : substr($token, $eq + 1);
                $option = $long[$name] ?? null;
                if ($option === null) {
                    $suggestion = Suggest::closest($name, array_keys($long));
                    return self::error(UsageErrorKind::UnknownOption, [
                        'option' => '--' . $name,
                        'suggestion' => $suggestion === null ? null : '--' . $suggestion,
                    ], $names);
                }
                if (!$option->takesValue()) {
                    if ($inline !== null) {
                        return self::error(UsageErrorKind::UnexpectedValue, [
                            'option' => $option->display(),
                            'value' => $inline,
                        ], $names);
                    }
                    self::record($given, $firstSeen, $option, true, $i);
                    continue;
                }
                if ($inline === null) {
                    if ($i + 1 >= $count || self::looksLikeOption($argv[$i + 1])) {
                        return self::error(UsageErrorKind::MissingValue, ['option' => $option->display()], $names);
                    }
                    $inline = $argv[++$i];
                }
                $value = self::convert($option, $inline, null, $names);
                if ($value instanceof UsageError) {
                    return $value;
                }
                self::record($given, $firstSeen, $option, $value, $i);
                continue;
            }

            if (strlen($token) > 1 && $token[0] === '-' && !(is_numeric($token) && !isset($short[$token[1]]))) {
                $cluster = substr($token, 1);
                $length = strlen($cluster);
                for ($j = 0; $j < $length; $j++) {
                    $char = $cluster[$j];
                    $option = $short[$char] ?? null;
                    if ($option === null) {
                        return self::error(UsageErrorKind::UnknownOption, ['option' => '-' . $char, 'suggestion' => null], $names);
                    }
                    if (!$option->takesValue()) {
                        self::record($given, $firstSeen, $option, true, $i);
                        continue;
                    }
                    // A value option ends the cluster: the rest of it is the
                    // value (`-mgpt`, `-m=gpt`), else the next token is.
                    $rest = substr($cluster, $j + 1);
                    if ($rest !== '') {
                        $raw = $rest[0] === '=' ? substr($rest, 1) : $rest;
                    } elseif ($i + 1 >= $count || self::looksLikeOption($argv[$i + 1])) {
                        return self::error(UsageErrorKind::MissingValue, ['option' => $option->display()], $names);
                    } else {
                        $raw = $argv[++$i];
                    }
                    $value = self::convert($option, $raw, null, $names);
                    if ($value instanceof UsageError) {
                        return $value;
                    }
                    self::record($given, $firstSeen, $option, $value, $i);
                    break;
                }
                continue;
            }

            $current = $path[count($path) - 1];
            if ($arguments === [] && $current->subcommands() !== []) {
                $sub = $current->subcommand($token);
                if ($sub !== null) {
                    $path[] = $sub;
                    $names[] = $sub->name();
                    [$long, $short] = self::visible($path);
                    continue;
                }
                if ($current->effectiveMaxArguments() === 0) {
                    return self::error(UsageErrorKind::UnknownCommand, [
                        'command' => implode(' ', $names),
                        'given' => $token,
                        'suggestion' => Suggest::closest($token, array_keys($current->subcommands())),
                    ], $names);
                }
            }
            $arguments[] = $token;
        }

        return self::resolve($path, $names, $given, $firstSeen, $arguments, $env);
    }

    /**
     * @param list<CommandSpec> $path
     * @param list<string> $names
     * @param array<string, list<mixed>> $given
     * @param array<string, int> $firstSeen
     * @param list<string> $arguments
     * @param array<string, string> $env
     */
    private static function resolve(
        array $path,
        array $names,
        array $given,
        array $firstSeen,
        array $arguments,
        array $env,
    ): ParsedArgs|UsageError {
        $options = self::pathOptions($path);
        self::assertRules($path, $options);
        // The leaf's parse: its own options plus persistent ancestors, as
        // only withPersistent() options carry into a subcommand. A
        // non-persistent ancestor option argv typed at its own command's
        // level still answers that command's conflict and requires() rules
        // through $sources, but its value stays out of the parse.
        [$leafOptions] = self::visible($path);

        $suppressed = [];
        foreach ($path as $spec) {
            foreach ($spec->conflictSets() as $set) {
                if (array_intersect($set, array_keys($given)) !== []) {
                    foreach ($set as $member) {
                        $suppressed[$member] = true;
                    }
                }
            }
        }

        $values = [];
        $sources = [];
        foreach ($options as $name => $option) {
            if (isset($given[$name])) {
                $sources[$name] = ValueSource::Argv;
                if (!isset($leafOptions[$name])) {
                    continue;
                }
                $occurrences = $given[$name];
                $values[$name] = match (true) {
                    !$option->takesValue() && $option->repeatable() => count($occurrences),
                    !$option->takesValue() => true,
                    $option->repeatable() => $occurrences,
                    default => $occurrences[count($occurrences) - 1],
                };
                continue;
            }

            // Not part of the subcommand's parse: no value key, so ParsedArgs
            // rejects the name like any other undeclared one.
            if (!isset($leafOptions[$name])) {
                continue;
            }

            $envName = $option->env();
            $raw = $envName === null || isset($suppressed[$name]) ? null : ($env[$envName] ?? null);
            if (is_string($raw) && $raw !== '') {
                $value = self::convertEnv($option, $raw, $envName, $names);
                if ($value instanceof UsageError) {
                    return $value;
                }
                if ($value === null) {
                    // A falsy flag variable switches the flag off over its
                    // default; it reports no source, so the rules ignore it.
                    $values[$name] = $option->repeatable() ? 0 : false;
                    continue;
                }
                $values[$name] = $value;
                $sources[$name] = ValueSource::Env;
                continue;
            }

            if ($option->default() !== null) {
                $default = $option->default();
                // A repeatable option keeps the shape argv gives it: a value
                // option's scalar default is wrapped in a list, and a flag's
                // bool default becomes a count (true is 1, false is 0).
                $values[$name] = match (true) {
                    $option->takesValue() && $option->repeatable() && !is_array($default) => [$default],
                    !$option->takesValue() && $option->repeatable() && is_bool($default) => (int) $default,
                    default => $default,
                };
                $sources[$name] = ValueSource::Default;
                continue;
            }
            $values[$name] = self::emptyValue($option);
        }

        $present = static fn (string $name): bool => ($sources[$name] ?? null) === ValueSource::Argv
            || ($sources[$name] ?? null) === ValueSource::Env;
        // The variable an option's value came from, so a rule error can name
        // it: the user typed neither option when both came from the env.
        $envSource = static fn (string $name): ?string => ($sources[$name] ?? null) === ValueSource::Env
            ? $options[$name]->env()
            : null;

        // A non-persistent ancestor option outside the leaf's parse still
        // answers its own command's conflict and requires() rules: a
        // variable its command would accept counts as given, though the
        // value stays out of the subcommand's parse. Otherwise the error
        // would name an option the environment already supplies. A variable
        // that fails conversion comes back as the InvalidValue the ancestor
        // itself would report.
        $suppliedAbove = static function (string $name) use ($leafOptions, $given, $suppressed, $options, $env, $names): bool|UsageError {
            if (isset($leafOptions[$name]) || isset($given[$name]) || isset($suppressed[$name])) {
                return false;
            }
            $envName = $options[$name]->env();
            $raw = $envName === null ? '' : ($env[$envName] ?? '');
            if ($envName === null || $raw === '') {
                return false;
            }
            $value = self::convertEnv($options[$name], $raw, $envName, $names);
            return $value instanceof UsageError ? $value : $value !== null;
        };

        foreach ($path as $spec) {
            foreach ($spec->conflictSets() as $set) {
                $hits = [];
                $hitEnv = [];
                foreach ($set as $member) {
                    if ($present($member)) {
                        $hits[] = $member;
                        $hitEnv[$member] = $envSource($member);
                        continue;
                    }
                    // A member outside the leaf's parse never gets an Env
                    // source; without this, two ancestor variables would
                    // dodge the ancestor's conflict rule at a subcommand.
                    $above = $suppliedAbove($member);
                    if ($above instanceof UsageError) {
                        return $above;
                    }
                    if ($above) {
                        $hits[] = $member;
                        $hitEnv[$member] = $options[$member]->env();
                    }
                }
                if (count($hits) < 2) {
                    continue;
                }
                usort($hits, static fn (string $a, string $b): int => ($firstSeen[$a] ?? PHP_INT_MAX) <=> ($firstSeen[$b] ?? PHP_INT_MAX));
                return self::error(UsageErrorKind::ConflictingOptions, [
                    'option' => '--' . $hits[0],
                    'other' => '--' . $hits[1],
                    'env' => $hitEnv[$hits[0]],
                    'otherEnv' => $hitEnv[$hits[1]],
                ], $names);
            }
        }

        foreach ($leafOptions as $option) {
            if ($option->required() && !$present($option->name())) {
                // A conflict set silenced this env variable: telling the user
                // to set it would be wrong, since it already is.
                $envName = $option->env();
                $silenced = $envName !== null && isset($suppressed[$option->name()]) && ($env[$envName] ?? '') !== '';
                return self::error(UsageErrorKind::MissingOption, [
                    'option' => $option->display(),
                    'env' => $silenced ? null : $envName,
                ], $names);
            }
        }

        foreach ($path as $spec) {
            foreach ($spec->requirements() as $name => $needs) {
                // An all-digit name comes back from the array key as an int.
                $name = (string) $name;
                $triggerEnv = $envSource($name);
                if (!$present($name)) {
                    // A trigger outside the leaf's parse never gets an Env
                    // source, so its variable is checked the same way as a
                    // needed option's: otherwise setting the variable instead
                    // of typing the option would bypass the rule.
                    $above = $suppliedAbove($name);
                    if ($above instanceof UsageError) {
                        return $above;
                    }
                    if (!$above) {
                        continue;
                    }
                    $triggerEnv = $options[$name]->env();
                }
                foreach ($needs as $need) {
                    if ($present($need)) {
                        continue;
                    }
                    $above = $suppliedAbove($need);
                    if ($above instanceof UsageError) {
                        return $above;
                    }
                    if (!$above) {
                        return self::error(UsageErrorKind::MissingRequirement, [
                            'option' => '--' . $name,
                            'requires' => '--' . $need,
                            'env' => $triggerEnv,
                        ], $names);
                    }
                }
            }
        }

        $leaf = $path[count($path) - 1];
        $argumentCount = count($arguments);
        if ($argumentCount < $leaf->minArguments()) {
            return self::error(UsageErrorKind::TooFewArguments, [
                'command' => implode(' ', $names),
                'min' => $leaf->minArguments(),
                'given' => $argumentCount,
            ], $names);
        }
        $max = $leaf->effectiveMaxArguments();
        if ($max !== null && $argumentCount > $max) {
            return self::error(UsageErrorKind::TooManyArguments, [
                'command' => implode(' ', $names),
                'max' => $max,
                'given' => $argumentCount,
            ], $names);
        }

        return ParsedArgs::new($names, $values, array_intersect_key($sources, $leafOptions), $arguments);
    }

    /**
     * Run the spec checks of {@see resolve()} and {@see visible()} for every
     * path in the command tree rooted at the end of `$path`.
     *
     * @param list<CommandSpec> $path
     */
    private static function assertTree(array $path): void
    {
        self::visible($path);
        self::assertRules($path, self::pathOptions($path));
        foreach ($path[count($path) - 1]->subcommands() as $sub) {
            self::assertTree([...$path, $sub]);
        }
    }

    /**
     * Options recognized at the end of `$path`: the leaf's own, plus the
     * persistent options of every ancestor.
     *
     * @param list<CommandSpec> $path
     * @return array{0: array<string, Option>, 1: array<string, Option>}  [long, short]
     */
    private static function visible(array $path): array
    {
        $long = [];
        $short = [];
        $last = count($path) - 1;
        foreach ($path as $depth => $spec) {
            foreach ($spec->options() as $name => $option) {
                if ($depth !== $last && !$option->persistent()) {
                    continue;
                }
                if (isset($long[$name])) {
                    throw new \LogicException(sprintf('Option "--%s" is declared twice on the command path "%s".', $name, self::pathName($path)));
                }
                $long[$name] = $option;
                $char = $option->short();
                if ($char !== null) {
                    if (isset($short[$char])) {
                        throw new \LogicException(sprintf('Short option "-%s" is declared twice on the command path "%s".', $char, self::pathName($path)));
                    }
                    $short[$char] = $option;
                }
            }
        }
        return [$long, $short];
    }

    /**
     * Every option declared along the path, keyed by long name.
     *
     * @param list<CommandSpec> $path
     * @return array<string, Option>
     */
    private static function pathOptions(array $path): array
    {
        $options = [];
        foreach ($path as $spec) {
            foreach ($spec->options() as $name => $option) {
                if (isset($options[$name])) {
                    throw new \LogicException(sprintf('Option "--%s" is declared twice on the command path "%s".', $name, self::pathName($path)));
                }
                $options[$name] = $option;
            }
        }
        return $options;
    }

    /**
     * @param list<CommandSpec> $path
     * @param array<string, Option> $options
     */
    private static function assertRules(array $path, array $options): void
    {
        foreach ($path as $spec) {
            $named = array_merge(array_keys($spec->requirements()), ...array_values($spec->requirements()), ...$spec->conflictSets());
            foreach ($named as $name) {
                if (!isset($options[$name])) {
                    throw new \LogicException(sprintf('Rule on "%s" names undeclared option "--%s".', $spec->name(), $name));
                }
            }
            // The effective maximum: a command with subcommands and no
            // withMaxArguments() takes none, so any minimum is unsatisfiable.
            $max = $spec->effectiveMaxArguments();
            if ($max !== null && $spec->minArguments() > $max) {
                throw new \LogicException(sprintf(
                    '"%s" requires at least %d arguments but accepts at most %d.',
                    $spec->name(),
                    $spec->minArguments(),
                    $max,
                ));
            }
        }
    }

    /** The value of an option nothing supplied, in the shape argv would give it. */
    private static function emptyValue(Option $option): mixed
    {
        return match (true) {
            !$option->takesValue() && $option->repeatable() => 0,
            !$option->takesValue() => false,
            $option->repeatable() => [],
            default => null,
        };
    }

    /**
     * @param array<string, list<mixed>> $given
     * @param array<string, int> $firstSeen
     */
    private static function record(array &$given, array &$firstSeen, Option $option, mixed $value, int $index): void
    {
        $given[$option->name()][] = $value;
        $firstSeen[$option->name()] ??= $index;
    }

    /**
     * A token that cannot be a value: anything option-shaped except `-`
     * (stdin by convention) and negative numbers. `--name=-x` passes a
     * dash-leading value explicitly.
     */
    private static function looksLikeOption(string $token): bool
    {
        return strlen($token) > 1 && $token[0] === '-' && !is_numeric($token);
    }

    /**
     * @param list<string> $names
     */
    private static function convert(Option $option, string $raw, ?string $env, array $names): mixed
    {
        $choices = $option->choices();
        if ($choices !== []) {
            if (!in_array($raw, $choices, true)) {
                return self::error(UsageErrorKind::InvalidChoice, [
                    'option' => $option->display(),
                    'value' => $raw,
                    'choices' => $choices,
                    'suggestion' => Suggest::closest($raw, $choices),
                    'env' => $env,
                ], $names);
            }
            $enum = $option->enum();
            if ($enum !== null) {
                foreach ($enum::cases() as $case) {
                    if ((string) $case->value === $raw) {
                        return $case;
                    }
                }
            }
        }

        return match ($option->type()) {
            ValueType::String => $raw,
            // `0 + $raw` turns an out-of-range digit string into a float, so
            // overflow is rejected instead of saturating like `(int)` does.
            ValueType::Int => preg_match('/^[+-]?\d+\z/', $raw) === 1 && is_int(0 + $raw)
                ? 0 + $raw
                : self::invalid($option, $raw, 'an integer', $env, $names),
            // is_numeric() without its surrounding whitespace, so ` 1.5` is
            // rejected as Int rejects ` 5`. `1e400` matches but casts to INF;
            // reject it like Int overflow.
            ValueType::Float => preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?\z/', $raw) === 1
                && is_finite((float) $raw)
                ? (float) $raw
                : self::invalid($option, $raw, 'a number', $env, $names),
        };
    }

    /**
     * Environment value of an option; null when a flag's variable is falsy,
     * which the caller resolves to the flag being off, even over a default.
     *
     * @param list<string> $names
     */
    private static function convertEnv(Option $option, string $raw, string $env, array $names): mixed
    {
        if ($option->kind() === OptionKind::Flag) {
            $word = strtolower(trim($raw));
            if (in_array($word, self::TRUE_WORDS, true)) {
                return $option->repeatable() ? 1 : true;
            }
            if (in_array($word, self::FALSE_WORDS, true)) {
                return null;
            }
            return self::invalid($option, $raw, 'one of ' . implode(', ', [...self::TRUE_WORDS, ...self::FALSE_WORDS]), $env, $names);
        }
        $value = self::convert($option, $raw, $env, $names);
        if ($value instanceof UsageError) {
            return $value;
        }
        return $option->repeatable() ? [$value] : $value;
    }

    /** @param list<string> $names */
    private static function invalid(Option $option, string $raw, string $expected, ?string $env, array $names): UsageError
    {
        return self::error(UsageErrorKind::InvalidValue, [
            'option' => $option->display(),
            'value' => $raw,
            'expected' => $expected,
            'env' => $env,
        ], $names);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string> $names
     */
    private static function error(UsageErrorKind $kind, array $params, array $names): UsageError
    {
        return UsageError::new($kind, $params, $names);
    }

    /** @param list<CommandSpec> $path */
    private static function pathName(array $path): string
    {
        return implode(' ', array_map(static fn (CommandSpec $spec): string => $spec->name(), $path));
    }
}
