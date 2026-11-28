<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Cli\Internal\SafeText;

/**
 * The result of a {@see CommandSpec::parse()} that broke a rule: which rule
 * ({@see kind()}), the values involved ({@see params()}), the command path it
 * was raised in, and a ready English {@see message()}.
 *
 * A value, not an exception: a usage error is the expected answer to bad
 * input, and the caller decides how to print it and what to exit with.
 * sugar-cli ships no language catalog, so a localized CLI renders its own
 * text from the kind and params; the English message is the default.
 *
 * Original work: no charmbracelet upstream parses argv.
 */
final class UsageError
{
    /**
     * @param array<string, mixed> $params
     * @param list<string> $command
     */
    private function __construct(
        private readonly UsageErrorKind $kind,
        private readonly array $params,
        private readonly array $command,
    ) {}

    /**
     * @param array<string, mixed> $params  see each {@see UsageErrorKind} case for its keys
     * @param list<string> $command  command path, root first
     */
    public static function new(UsageErrorKind $kind, array $params, array $command): self
    {
        return new self($kind, $params, array_values($command));
    }

    public function kind(): UsageErrorKind
    {
        return $this->kind;
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * Command path the error was raised in, root first (`['git', 'remote']`).
     *
     * @return list<string>
     */
    public function command(): array
    {
        return $this->command;
    }

    /**
     * The option the error is about as `--name` (an unknown short option
     * keeps its `-x` spelling), or null for a command or argument error.
     */
    public function option(): ?string
    {
        $option = $this->params['option'] ?? null;
        return is_string($option) ? $option : null;
    }

    /** The did-you-mean candidate, or null when nothing was close enough. */
    public function suggestion(): ?string
    {
        $suggestion = $this->params['suggestion'] ?? null;
        return is_string($suggestion) ? $suggestion : null;
    }

    /**
     * One-line English description of the error, without a trailing period.
     *
     * The interpolated values come from argv and the environment, so each
     * string is passed through {@see SafeText::line()}: an escape sequence or
     * control byte in a bad value must not reach the terminal the caller
     * prints this to, and a newline must not split the line. {@see params()}
     * keeps the raw values.
     */
    public function message(): string
    {
        $p = self::safe($this->params);
        $from = isset($p['env']) && is_string($p['env']) ? sprintf(' (from %s)', $p['env']) : '';
        $message = match ($this->kind) {
            UsageErrorKind::UnknownOption => sprintf('unknown option "%s"', $p['option']),
            UsageErrorKind::UnknownCommand => sprintf('unknown command "%s" for "%s"', $p['given'], $p['command']),
            UsageErrorKind::MissingValue => sprintf('option "%s" needs a value', $p['option']),
            UsageErrorKind::UnexpectedValue => sprintf('option "%s" does not take a value', $p['option']),
            UsageErrorKind::InvalidValue => sprintf(
                'invalid value "%s" for option "%s"%s: expected %s',
                $p['value'],
                $p['option'],
                $from,
                $p['expected'],
            ),
            UsageErrorKind::InvalidChoice => sprintf(
                'invalid value "%s" for option "%s"%s: expected one of %s',
                $p['value'],
                $p['option'],
                $from,
                implode(', ', $p['choices']),
            ),
            UsageErrorKind::MissingOption => isset($p['env']) && is_string($p['env'])
                ? sprintf('required option "%s" is missing (or set %s)', $p['option'], $p['env'])
                : sprintf('required option "%s" is missing', $p['option']),
            UsageErrorKind::MissingRequirement => sprintf('option "%s" requires "%s"', $p['option'], $p['requires']),
            UsageErrorKind::ConflictingOptions => sprintf(
                'options "%s" and "%s" cannot be used together',
                $p['option'],
                $p['other'],
            ),
            UsageErrorKind::TooFewArguments => sprintf(
                '"%s" expects at least %d %s, got %d',
                $p['command'],
                $p['min'],
                $p['min'] === 1 ? 'argument' : 'arguments',
                $p['given'],
            ),
            UsageErrorKind::TooManyArguments => $p['max'] === 0
                ? sprintf('"%s" takes no arguments, got %d', $p['command'], $p['given'])
                : sprintf(
                    '"%s" expects at most %d %s, got %d',
                    $p['command'],
                    $p['max'],
                    $p['max'] === 1 ? 'argument' : 'arguments',
                    $p['given'],
                ),
        };
        $suggestion = $this->suggestion();
        return $suggestion === null
            ? $message
            : sprintf('%s (did you mean "%s"?)', $message, SafeText::line($suggestion));
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<array-key, mixed>
     */
    private static function safe(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $params[$key] = SafeText::line($value);
            } elseif (is_array($value)) {
                $params[$key] = self::safe($value);
            }
        }
        return $params;
    }
}
