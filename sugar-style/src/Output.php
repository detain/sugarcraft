<?php

declare(strict_types=1);

namespace SugarCraft\Style;

/**
 * Top-level (un-styled) output helpers — the equivalents of the
 * package-level `lipgloss.Print` / `Println` / `Sprint` / `Fprint` /
 * `Printf` functions. Each method is the un-styled twin of the matching
 * {@see Style} method (`Output::sprint()` vs `Style::sprint()`, and so
 * on) so callers can reach the same shape without a Style instance.
 *
 * The join rule matches {@see Style::sprint()} exactly — single spaces
 * between every argument — and with it the same deliberate divergence
 * from Go's `fmt.Sprint`: Go inserts a space only when neither
 * neighbour operand is a string, so `Sprint("hello", "world")` yields
 * `"helloworld"`. Likewise `printf()` here RETURNS the formatted string
 * where upstream `Printf` writes it. For Go-exact bytes pass
 * `implode('', $parts)` as a single argument.
 *
 * These helpers do *no* ANSI escape emission — pass already-styled strings
 * (e.g. the result of `Style::new()->bold(true)->render('hi')`) when you
 * want colour.
 */
final class Output
{
    /**
     * Concatenate arguments with single spaces. No newline, no styling.
     * Un-styled twin of {@see Style::sprint()} (same join rule and same
     * Go divergence, both documented in the class docblock).
     */
    public static function sprint(string ...$parts): string
    {
        return implode(' ', $parts);
    }

    /**
     * `sprintf()` substitution that RETURNS the formatted string
     * (upstream `Printf` writes instead). Un-styled twin of
     * {@see Style::printfSprint()}.
     */
    public static function printf(string $format, mixed ...$args): string
    {
        return sprintf($format, ...$args);
    }

    /**
     * Write `Output::sprint($parts)` to STDOUT (no newline). Un-styled
     * twin of {@see Style::print()}.
     */
    public static function print(string ...$parts): void
    {
        fwrite(STDOUT, self::sprint(...$parts));
    }

    /**
     * Write `Output::sprint($parts) . "\n"` to STDOUT. Un-styled twin
     * of {@see Style::println()}.
     */
    public static function println(string ...$parts): void
    {
        fwrite(STDOUT, self::sprint(...$parts) . "\n");
    }

    /**
     * Write `Output::sprint($parts)` to a caller-supplied stream
     * resource (no trailing newline). Un-styled twin of
     * {@see Style::fprint()}.
     *
     * @param resource $stream
     */
    public static function fprint($stream, string ...$parts): void
    {
        fwrite($stream, self::sprint(...$parts));
    }
}
