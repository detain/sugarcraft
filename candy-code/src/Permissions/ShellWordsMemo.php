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
 * Keyed by the exact line, so two spellings never share a parse. Bounded
 * twice, least recently used evicted first: at most {@see CAPACITY} lines,
 * and at most {@see MAX_RETAINED_BYTES} of PARSED result. The second bound is
 * the one that matters for memory: a parse costs far more than its text
 * when the line is word- or command-dense (a 64 KiB line of `a a a ...`
 * parses to about 2 MiB of arrays, so 32 of them pinned about 62 MiB), while
 * a heredoc body or one long quoted argument costs about its text. The cost
 * of each parse is measured, not guessed, as the heap growth across the lex
 * (never less than the line's own length). A parse that alone costs more
 * than {@see MAX_ENTRY_BYTES}, or a line over {@see MAX_LINE_BYTES} (the
 * gate's own length door, {@see PermissionGate::MAX_REASONED_COMMAND_BYTES}),
 * is lexed every time and never kept, so it cannot flush the hot lines.
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

    /** Most parsed bytes kept at once, across every entry. */
    public const MAX_RETAINED_BYTES = 4 * 1024 * 1024;

    /** Costliest single parse kept; a costlier one is lexed on every call. */
    public const MAX_ENTRY_BYTES = 512 * 1024;

    /** @var array<array-key, ShellWords> */
    private static array $entries = [];

    /** @var array<array-key, int> Measured cost of each entry, same keys. */
    private static array $costs = [];

    /** Sum of {@see $costs}. */
    private static int $retained = 0;

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
        $before = memory_get_usage();
        $parsed = $lex();
        $cost = max(memory_get_usage() - $before, \strlen($line));
        if (\strlen($line) > self::MAX_LINE_BYTES || $cost > self::MAX_ENTRY_BYTES) {
            return $parsed;
        }

        self::$entries[$line] = $parsed;
        self::$costs[$line] = $cost;
        self::$retained += $cost;
        while (\count(self::$entries) > self::CAPACITY || self::$retained > self::MAX_RETAINED_BYTES) {
            $oldest = array_key_first(self::$entries);
            self::$retained -= self::$costs[$oldest];
            unset(self::$entries[$oldest], self::$costs[$oldest]);
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
        self::$costs = [];
        self::$retained = 0;
    }

    /** How many lines are cached now. */
    public static function size(): int
    {
        return \count(self::$entries);
    }

    /** Measured parsed bytes the cached lines hold now. */
    public static function retainedBytes(): int
    {
        return self::$retained;
    }
}
