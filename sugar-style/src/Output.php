<?php

declare(strict_types=1);

namespace SugarCraft\Style;

/**
 * Top-level (un-styled) output helpers — the lipgloss equivalents of the
 * package-level `lipgloss.Print` / `Println` / `Sprint` / `Fprint` / `Printf`
 * functions. Each one mirrors the matching method on {@see Style} but
 * skips the styling layer so callers can use the same surface from
 * `lipgloss.X(...)` to `Output::x(...)` without needing a Style instance.
 *
 * These helpers do *no* ANSI escape emission — pass already-styled strings
 * (e.g. the result of `Style::new()->bold(true)->render('hi')`) when you
 * want colour. The helpers exist so the `Output::println(...)` shape is
 * available without requiring a `Style::new()->println(...)` boilerplate.
 */
final class Output
{
    /**
     * Concatenate arguments with single spaces. No newline. Mirrors
     * lipgloss `Sprint`.
     */
    public static function sprint(string ...$parts): string
    {
        return implode(' ', $parts);
    }

    /**
     * sprintf-flavoured concatenation. Mirrors lipgloss `Printf`.
     */
    public static function printf(string $format, mixed ...$args): string
    {
        return sprintf($format, ...$args);
    }

    /**
     * Write `Output::sprint($parts)` to STDOUT (no newline). Mirrors
     * lipgloss `Print`.
     */
    public static function print(string ...$parts): void
    {
        fwrite(STDOUT, self::sprint(...$parts));
    }

    /**
     * Write `Output::sprint($parts) . "\n"` to STDOUT. Mirrors
     * lipgloss `Println`.
     */
    public static function println(string ...$parts): void
    {
        fwrite(STDOUT, self::sprint(...$parts) . "\n");
    }

    /**
     * Write `Output::sprint($parts)` to a caller-supplied stream
     * resource (no trailing newline). Mirrors lipgloss `Fprint`.
     *
     * @param resource $stream
     */
    public static function fprint($stream, string ...$parts): void
    {
        fwrite($stream, self::sprint(...$parts));
    }
}
