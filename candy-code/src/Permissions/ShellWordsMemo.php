<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

/**
 * The parse-once cache behind {@see ShellWords::parse()}.
 *
 * WHY. One tool call used to lex the same command line once PER CHECK: every
 * argument-scoped `Bash(...)` rule the gate tried parsed the command again in
 * {@see PermissionRule} (and an `Allow` rule written as a chain parsed its own
 * pattern again too), and {@see SessionPermissionMemo} parsed it up to five
 * more times while it judged and recorded a grant. Audit row 41 measured the
 * lexer at about 64 us per 100 bytes, so a config with a dozen shell rules
 * paid the lex a dozen times for one call. {@see ShellWords} is a pure
 * function of the line and its result is immutable, so the first lex can
 * answer every later check of the same text.
 *
 * Keyed by the exact line, so two spellings never share a parse. Bounded:
 * at most {@see CAPACITY} lines, least recently used evicted first, and a
 * line over {@see MAX_LINE_BYTES} (the gate's own length door,
 * {@see PermissionGate::MAX_REASONED_COMMAND_BYTES}) is lexed every time and
 * never kept, so the cache cannot hold more than a few MiB of text.
 *
 * Process-wide rather than per gate on purpose: the parse depends on nothing
 * but the text, so sharing it across gates and hooks cannot change an
 * answer, and a hook that inspects the same command after the gate (the
 * `rm` and escape deny hooks) also reads the cached parse.
 *
 * @internal The observer and {@see reset()} exist for tests.
 */
final class ShellWordsMemo
{
    /** Lines kept at once. */
    public const CAPACITY = 32;

    /** Longest line kept; a longer one is lexed on every call. */
    public const MAX_LINE_BYTES = 65536;

    /** @var array<array-key, ShellWords> */
    private static array $entries = [];

    /** @var (\Closure(string): void)|null */
    private static ?\Closure $observer = null;

    /**
     * The cached parse of `$line`, or `$lex()` run once and remembered.
     *
     * @param \Closure(): ShellWords $lex
     */
    public static function remember(string $line, \Closure $lex): ShellWords
    {
        $hit = self::$entries[$line] ?? null;
        if ($hit !== null) {
            // Move to the young end so a hot line outlives a burst of others.
            unset(self::$entries[$line]);
            self::$entries[$line] = $hit;

            return $hit;
        }

        if (self::$observer !== null) {
            (self::$observer)($line);
        }
        $parsed = $lex();
        if (\strlen($line) > self::MAX_LINE_BYTES) {
            return $parsed;
        }

        self::$entries[$line] = $parsed;
        if (\count(self::$entries) > self::CAPACITY) {
            unset(self::$entries[array_key_first(self::$entries)]);
        }

        return $parsed;
    }

    /**
     * Called with each line just before it is lexed (a cache miss), or null
     * to stop. Test seam: this is how "parsed once per tool call" is counted.
     *
     * @param (\Closure(string): void)|null $observer
     */
    public static function observe(?\Closure $observer): void
    {
        self::$observer = $observer;
    }

    /** Forget every cached parse. */
    public static function reset(): void
    {
        self::$entries = [];
    }

    /** How many lines are cached now. */
    public static function size(): int
    {
        return \count(self::$entries);
    }
}
