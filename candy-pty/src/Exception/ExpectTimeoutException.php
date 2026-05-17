<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Exception;

use SugarCraft\Pty\PtyException;

/**
 * Raised when {@see \SugarCraft\Pty\Expect::expect()} (or a sibling
 * matcher) blocks past its timeout without seeing the needle / pattern.
 *
 * Carries the partial `buffer` so callers can log what *did* arrive
 * before the timeout — invaluable when a script's `expect('login: ')`
 * fires too early because the server sent `login : ` (extra space) or
 * an MOTD pushed the prompt past the read window.
 */
final class ExpectTimeoutException extends PtyException
{
    /**
     * @param list<string> $needles
     */
    public function __construct(
        string $message,
        public readonly array $needles,
        public readonly float $timeoutSec,
        public readonly string $buffer,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $needles
     */
    public static function forNeedles(array $needles, float $timeoutSec, string $buffer): self
    {
        $rendered = \count($needles) === 1
            ? '"' . self::escape($needles[0]) . '"'
            : '[' . \implode(', ', \array_map(fn($n) => '"' . self::escape($n) . '"', $needles)) . ']';
        return new self(
            \sprintf(
                'Expect: timed out after %.3fs waiting for %s (buffer=%d bytes)',
                $timeoutSec,
                $rendered,
                \strlen($buffer),
            ),
            $needles,
            $timeoutSec,
            $buffer,
        );
    }

    public static function forPattern(string $regex, float $timeoutSec, string $buffer): self
    {
        return new self(
            \sprintf(
                'Expect: timed out after %.3fs waiting for pattern %s (buffer=%d bytes)',
                $timeoutSec,
                $regex,
                \strlen($buffer),
            ),
            [$regex],
            $timeoutSec,
            $buffer,
        );
    }

    private static function escape(string $s): string
    {
        return \addcslashes($s, "\\\"\n\r\t");
    }
}
