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
     * twin of {@see Style::print()}. Same fail-fast write accounting:
     * returns the bytes written, throws `RuntimeException` on an
     * invalid STDOUT or a false/short write.
     */
    public static function print(string ...$parts): int
    {
        return self::writeStream(STDOUT, self::sprint(...$parts), 'STDOUT');
    }

    /**
     * Write `Output::sprint($parts) . "\n"` to STDOUT. Un-styled twin
     * of {@see Style::println()}. Same fail-fast write accounting as
     * {@see print()}.
     */
    public static function println(string ...$parts): int
    {
        return self::writeStream(STDOUT, self::sprint(...$parts) . "\n", 'STDOUT');
    }

    /**
     * Write `Output::sprint($parts)` to a caller-supplied stream
     * resource (no trailing newline). Un-styled twin of
     * {@see Style::fprint()}.
     *
     * Fail-fast accounting: returns the number of bytes written;
     * throws `RuntimeException` on an invalid stream or a false/short
     * write. An empty payload legitimately writes zero bytes.
     *
     * @param resource $stream
     */
    public static function fprint($stream, string ...$parts): int
    {
        return self::writeStream($stream, self::sprint(...$parts), 'stream');
    }

    /**
     * Accounted fwrite for the writers family — the same contract as
     * {@see Style::writeStream()} (kept as a private door per class so
     * Output stays dependency-free). The payload must land in full or
     * the call refuses loudly; `@` folds fwrite's PHP warning into the
     * false arm.
     *
     * @param resource|mixed $stream
     */
    private static function writeStream($stream, string $payload, string $destination): int
    {
        if (!is_resource($stream)) {
            throw new \RuntimeException(Lang::t('style.write_invalid_stream', ['destination' => $destination]));
        }
        $total   = strlen($payload);
        $written = @fwrite($stream, $payload);
        if ($written === false || $written < $total) {
            throw new \RuntimeException(Lang::t('style.write_short', [
                'written' => $written === false ? 0 : $written,
                'total'   => $total,
            ]));
        }
        return $written;
    }
}
