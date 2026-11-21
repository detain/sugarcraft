<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Exception;

use SugarCraft\Pty\PtyException;

/**
 * Raised when {@see \SugarCraft\Pty\Expect::expect()} (or a sibling
 * matcher) is waiting on the master and the child closes its end /
 * the kernel returns EOF before any needle matches, or, for
 * {@see \SugarCraft\Pty\Expect::expectAsync()}, when the child it was
 * given exits first.
 *
 * Carries the final buffer so callers can salvage any partial output
 * — useful for "child died mid-prompt; what did it manage to say?"
 * diagnostics.
 */
final class ExpectEofException extends PtyException
{
    /**
     * @param list<string> $needles
     */
    public function __construct(
        string $message,
        public readonly array $needles,
        public readonly string $buffer,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $needles
     */
    public static function forNeedles(array $needles, string $buffer): self
    {
        $rendered = \count($needles) === 1
            ? '"' . self::escape($needles[0]) . '"'
            : '[' . \implode(', ', \array_map(fn($n) => '"' . self::escape($n) . '"', $needles)) . ']';
        return new self(
            \sprintf(
                'Expect: master EOF before matching %s (buffer=%d bytes)',
                $rendered,
                \strlen($buffer),
            ),
            $needles,
            $buffer,
        );
    }

    public static function forPattern(string $regex, string $buffer): self
    {
        return new self(
            \sprintf(
                'Expect: master EOF before matching pattern %s (buffer=%d bytes)',
                $regex,
                \strlen($buffer),
            ),
            [$regex],
            $buffer,
        );
    }

    /**
     * The child exited, and the drain of what it wrote last brought no match.
     *
     * @param list<string> $needles literal needles, or one pattern when $pattern
     */
    public static function forChildExit(array $needles, int $exitCode, string $buffer, bool $pattern = false): self
    {
        $rendered = match (true) {
            $pattern => 'pattern ' . $needles[0],
            \count($needles) === 1 => '"' . self::escape($needles[0]) . '"',
            default => '[' . \implode(', ', \array_map(fn($n) => '"' . self::escape($n) . '"', $needles)) . ']',
        };
        return new self(
            \sprintf(
                'Expect: child exited with code %d before matching %s (buffer=%d bytes)',
                $exitCode,
                $rendered,
                \strlen($buffer),
            ),
            $needles,
            $buffer,
        );
    }

    private static function escape(string $s): string
    {
        return \addcslashes($s, "\\\"\n\r\t");
    }
}
