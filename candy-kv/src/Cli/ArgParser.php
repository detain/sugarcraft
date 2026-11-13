<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Cli;

use SugarCraft\Kv\Import\ImportCommit;
use SugarCraft\Kv\Store;
use SugarCraft\Kv\Support\Ttl;

/**
 * Argument parser for the candy-kv CLI.
 *
 * Mirrors charmbracelet/skate argument parsing, with the option grammar
 * tightened to the documented contract: EVERY token is accounted for.
 * Unknown flags, extra positionals, and value-hungry options facing a flag
 * are usage errors — they throw `InvalidArgumentException` (which
 * `bin/candy-kv` reports on stderr with exit code 2) instead of being
 * silently dropped. Silently ignoring `--frobnicate` once let a typo'd
 * `--ttl300=60` store a "temporary" secret forever, so the parser now
 * refuses rather than guesses.
 *
 * A bare `--` ends option processing: every token after it is data,
 * flag-shaped or not, so `set backup -- --ttl=60` stores the literal
 * value `--ttl=60`.
 *
 * @internal This class is for CLI use only and is not part of the public API.
 */
final class ArgParser
{
    /**
     * Parse arguments for the `set` command.
     *
     * The --ttl option accepts both spellings: the attached form
     * `--ttl=3600` and the separated form `--ttl 3600`. A non-numeric,
     * negative, or absurdly large value is refused loudly.
     * `--binary` / `--text` force the storage encoding; when neither is
     * given `binary` stays null and the caller auto-detects.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where set subcommand args begin.
     * @return array{key: string|null, value: string|null, ttl: int|null, binary: bool|null}
     * @throws \InvalidArgumentException on unknown flags, extra positionals,
     *         or a malformed --ttl payload.
     */
    public static function set(array $argv, int $offset): array
    {
        [$tokens, $tail] = self::splitPositional($argv, $offset);
        $ttl = null;
        $binary = null;
        $out = [];

        $count = count($tokens);
        for ($p = 0; $p < $count; $p++) {
            $arg = $tokens[$p];
            if (str_starts_with($arg, '--ttl=')) {
                $ttl = self::ttlSeconds(substr($arg, 6));
            } elseif ($arg === '--ttl') {
                $next = $tokens[$p + 1] ?? null;
                if ($next === null) {
                    throw new \InvalidArgumentException('Option --ttl requires a value.');
                }
                $p++;
                $ttl = self::ttlSeconds($next);
            } elseif ($arg === '--binary') {
                $binary = true;
            } elseif ($arg === '--text') {
                $binary = false;
            } elseif (self::isOptionLike($arg)) {
                throw self::unknownOption($arg, 'set');
            } else {
                $out[] = $arg;
            }
        }
        $out = array_merge($out, $tail);

        self::refuseSurplus($out, 2, 'set');

        return [
            'key' => $out[0] ?? null,
            'value' => $out[1] ?? null,
            'ttl' => $ttl,
            'binary' => $binary,
        ];
    }

    /**
     * Parse arguments for the `get` command.
     *
     * `get` is byte-exact by contract — the opt-IN flag is `--sanitize`,
     * which routes the value through the same control/escape scrub `list`
     * applies. Default raw preserves the documented "get is the exact
     * read" promise (PEM keys and other multi-line text survive it).
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where get subcommand args begin.
     * @return array{key: string|null, sanitize: bool}
     * @throws \InvalidArgumentException on unknown flags or extra positionals.
     */
    public static function get(array $argv, int $offset): array
    {
        [$tokens, $tail] = self::splitPositional($argv, $offset);
        $out = [];
        $sanitize = false;

        foreach ($tokens as $arg) {
            if ($arg === '--sanitize') {
                $sanitize = true;
            } elseif (self::isOptionLike($arg)) {
                throw self::unknownOption($arg, 'get');
            } else {
                $out[] = $arg;
            }
        }
        $out = array_merge($out, $tail);

        self::refuseSurplus($out, 1, 'get');

        return ['key' => $out[0] ?? null, 'sanitize' => $sanitize];
    }

    /**
     * Parse arguments for the `delete` command.
     *
     * `--dry-run` reports how many entries the pattern WOULD remove and
     * writes nothing.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where delete subcommand args begin.
     * @return array{key: string|null, dryRun: bool}
     * @throws \InvalidArgumentException on unknown flags or extra positionals.
     */
    public static function delete(array $argv, int $offset): array
    {
        [$tokens, $tail] = self::splitPositional($argv, $offset);
        $out = [];
        $dryRun = false;

        foreach ($tokens as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
            } elseif (self::isOptionLike($arg)) {
                throw self::unknownOption($arg, 'delete');
            } else {
                $out[] = $arg;
            }
        }
        $out = array_merge($out, $tail);

        self::refuseSurplus($out, 1, 'delete');

        return ['key' => $out[0] ?? null, 'dryRun' => $dryRun];
    }

    /**
     * Parse arguments for the `list` command.
     *
     * The -d option accepts both spellings: the attached form `-d:` (or
     * `-d|`) and the separated form `-d :`. A bare -d at the very end of
     * the argument list keeps the pinned default tab delimiter, but a -d
     * whose next token is another flag is a usage error — the delimiter
     * must be visible in the command line, never borrowed from an option.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where list subcommand args begin.
     * @return array{mode: 'all'|'keys'|'values', reverse: bool, delimiter: string, pattern: string|null}
     * @throws \InvalidArgumentException on unknown flags, extra positionals,
     *         or a -d facing a flag-shaped token.
     */
    public static function list(array $argv, int $offset): array
    {
        $mode = 'all';
        $reverse = false;
        $delimiter = "\t";
        [$tokens, $tail] = self::splitPositional($argv, $offset);
        $out = [];

        $count = count($tokens);
        for ($p = 0; $p < $count; $p++) {
            $arg = $tokens[$p];
            if ($arg === '-k') {
                $mode = 'keys';
            } elseif ($arg === '-v') {
                $mode = 'values';
            } elseif ($arg === '-r') {
                $reverse = true;
            } elseif (str_starts_with($arg, '-d')) {
                $attached = substr($arg, 2);
                if ($attached !== '') {
                    // Gate G2 / kv-003: an attached tail of two or more characters is
                    // indistinguishable from a typo'd flag — `list -debug pat` silently
                    // became a "|" tabular run with delimiter "ebug". The parser refuses
                    // rather than guesses: an attached delimiter must be exactly one
                    // character (the bare dash of `-d-` included). Longer delimiters are
                    // unambiguous only in separated form: `-d ', '`.
                    $attachedChars = \preg_split('//u', $attached, -1, PREG_SPLIT_NO_EMPTY);
                    if (\strlen($attached) > 1 && ($attachedChars === false || \count($attachedChars) > 1)) {
                        throw self::unknownOption($arg, 'list');
                    }
                    $delimiter = $attached;
                } else {
                    $next = $tokens[$p + 1] ?? null;
                    if ($next === null && $tail === []) {
                        // Bare -d at end of list: keep the documented tab default.
                        continue;
                    }
                    if ($next === null || str_starts_with($next, '-')) {
                        throw new \InvalidArgumentException(
                            'Option -d requires a delimiter value, got flag-like "' . ($next ?? '') . '".'
                        );
                    }
                    $delimiter = $next;
                    $p++;
                }
            } elseif (self::isOptionLike($arg)) {
                throw self::unknownOption($arg, 'list');
            } else {
                $out[] = $arg;
            }
        }
        $out = array_merge($out, $tail);

        self::refuseSurplus($out, 1, 'list');

        return [
            'mode' => $mode,
            'reverse' => $reverse,
            'delimiter' => $delimiter,
            'pattern' => $out[0] ?? null,
        ];
    }

    /**
     * Parse arguments for the `import` command.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where import subcommand args begin.
     * @return array{format: string|null, path: string|null, atomic: bool}
     * @throws \InvalidArgumentException on unknown flags or extra positionals.
     */
    public static function import(array $argv, int $offset): array
    {
        [$tokens, $tail] = self::splitPositional($argv, $offset);
        $atomic = true;
        $ifExists = 'replace';
        $out = [];

        foreach ($tokens as $arg) {
            if ($arg === '--no-atomic') {
                $atomic = false;
            } elseif (\str_starts_with($arg, '--if-exists=')) {
                // kv-051: clobber policy. Grammar lives here, the policy
                // itself in ImportCommit — an unknown MODE is a usage-class
                // refusal (exit 2 with the import usage line), the same
                // class as an unknown option.
                $mode = substr($arg, strlen('--if-exists='));
                if (!in_array($mode, ImportCommit::POLICIES, true)) {
                    // Unlike unknownOption's withheld payload, the MODE is
                    // the information the operator needs — sanitize instead
                    // of hiding (kv-004 law still holds on egress bytes).
                    throw new \InvalidArgumentException(
                        \sprintf('Unknown --if-exists mode "%s". Use skip, fail or replace.', Store::sanitizeForTty($mode))
                    );
                }
                $ifExists = $mode;
            } elseif (self::isOptionLike($arg)) {
                throw self::unknownOption($arg, 'import');
            } else {
                $out[] = $arg;
            }
        }
        $out = array_merge($out, $tail);

        self::refuseSurplus($out, 2, 'import');

        return ['format' => $out[0] ?? null, 'path' => $out[1] ?? null, 'atomic' => $atomic, 'ifExists' => $ifExists];
    }

    /**
     * Parse arguments for the `export` command.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where export subcommand args begin.
     * @return array{format: string|null, db: string|null, pattern: string|null}
     * @throws \InvalidArgumentException on unknown flags or extra positionals.
     */
    public static function export(array $argv, int $offset): array
    {
        [$tokens, $tail] = self::splitPositional($argv, $offset);
        $out = [];

        foreach ($tokens as $arg) {
            if (self::isOptionLike($arg)) {
                throw self::unknownOption($arg, 'export');
            }
            $out[] = $arg;
        }
        $out = array_merge($out, $tail);

        self::refuseSurplus($out, 3, 'export');

        return ['format' => $out[0] ?? null, 'db' => $out[1] ?? null, 'pattern' => $out[2] ?? null];
    }

    /**
     * Parse arguments for the `prune` command: it takes none.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where prune subcommand args begin.
     * @throws \InvalidArgumentException on any extra token.
     */
    /**
     * Parse arguments for the `prune` verb (candy-kv-025/085).
     *
     * `prune` takes a scope, not nothing: the default database, one named
     * database (`--db NAME`, `--db=NAME`, or the `key@db` spelling the rest
     * of the CLI routes by), or every database (`--all`). The three scope
     * forms are mutually exclusive — answering a contradiction by guessing
     * one of them is the refuse-rather-than-guess law this parser carries.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where prune subcommand args begin.
     * @return array{db: string|null, all: bool} db=null means the default.
     * @throws \InvalidArgumentException on unknown flags, both-forms scope,
     *         or surplus positionals.
     */
    public static function prune(array $argv, int $offset): array
    {
        [$tokens, $tail] = self::splitPositional($argv, $offset);
        $db = null;
        $all = false;
        $out = [];

        $p = 0;
        $count = count($tokens);
        while ($p < $count) {
            $arg = $tokens[$p];
            if ($arg === '--all') {
                $all = true;
            } elseif (str_starts_with($arg, '--db=')) {
                $db = substr($arg, strlen('--db='));
            } elseif ($arg === '--db') {
                $next = $tokens[$p + 1] ?? null;
                if ($next === null) {
                    throw new \InvalidArgumentException('Option --db requires a value.');
                }
                $p++;
                $db = $next;
            } elseif (self::isOptionLike($arg)) {
                throw self::unknownOption($arg, 'prune');
            } else {
                $out[] = $arg;
            }
            $p++;
        }
        $out = array_merge($out, $tail);

        self::refuseSurplus($out, 1, 'prune');

        // A lone positional routes through the SAME escape-aware '@' law
        // Store::set writes by: `prune cache@notes` prunes notes.db, the
        // key part is documentation of intent only. A key with no '@'
        // splits to the empty fallback — that scopes to the default, it
        // is not a named database.
        $fromPositional = null;
        if (count($out) === 1) {
            $routed = Store::splitKey($out[0], '')[0];
            if ($routed === '') {
                // `prune junk` used to be refused outright (the verb took no
                // arguments); a bare key must not come back as a silent
                // scope-to-default. Only the qualified key@db spelling
                // names a database here.
                throw new \InvalidArgumentException(
                    'prune positional must name a database (key@db); use plain "prune" for the default database.'
                );
            }
            $fromPositional = $routed;
        }

        if ($fromPositional !== null && $db !== null) {
            throw new \InvalidArgumentException(
                'prune names its database twice: a --db option and a key@db positional.'
            );
        }
        if ($all && ($db !== null || $fromPositional !== null)) {
            throw new \InvalidArgumentException('prune takes either --all or one named database, not both.');
        }

        return ['db' => $db ?? $fromPositional, 'all' => $all];
    }

    /**
     * Refuse every token following a verb that takes no options and no
     * positionals (`list-dbs`, the `--version` diagnostic).
     *
     * One shared walk for the whole no-argument family: the usage contract
     * promises surplus positionals and unknown options fold into exit 2,
     * so a verb that ignores the parser entirely breaks that promise by
     * exiting 0 on garbage it never looked at.
     *
     * @param list<string> $argv    Full argv array.
     * @param int          $offset  Offset where the verb's args begin.
     * @param string       $command Verb named in the refusal.
     * @throws \InvalidArgumentException naming the first extra token.
     */
    public static function noArguments(array $argv, int $offset, string $command): void
    {
        [$tokens, $tail] = self::splitPositional($argv, $offset);
        if ($tokens !== [] || $tail !== []) {
            $extra = $tokens[0] ?? $tail[0];
            throw new \InvalidArgumentException(
                $command . ' takes no arguments, got "' . $extra . '".'
            );
        }
    }

    /**
     * Validate a TTL option payload as a bounded POSITIVE integer.
     *
     * Two refusals, both load-bearing:
     *  - Zero: `--ttl 0` used to mean "forever" (the writer drops any
     *    non-positive TTL), the exact opposite of what an expiry flag
     *    spells. A forever entry is made by OMITTING --ttl, so 0 is a
     *    usage error, not a silent inversion.
     *  - Oversized: an unbounded token like 99999999999999999999
     *    overflows int arithmetic downstream and mints an un-expirable
     *    zombie row — exactly the silent failure a TTL flag exists to avoid.
     *
     * @throws \InvalidArgumentException naming the offending value.
     */
    private static function ttlSeconds(string $raw): int
    {
        if (preg_match('/^[0-9]{1,9}$/', $raw) !== 1) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Option --ttl expects an integer of seconds from 1 to %d, got "%s".',
                    Ttl::MAX_SECONDS,
                    // kv-004: --ttl's value is raw argv bytes; the refusal echoes them.
                    Store::sanitizeForTty($raw)
                )
            );
        }
        // The 9-digit regex ceiling IS Ttl::MAX_SECONDS (999999999), so no
        // second upper comparison is needed here — keep the shared const as
        // the single documented bound (candy-kv-001: it was private to this
        // class while Store/Database/importers accepted any int).
        $seconds = (int) $raw;
        if ($seconds < 1) {
            throw new \InvalidArgumentException(
                'Option --ttl must be at least 1 second; omit --ttl for a forever entry.'
            );
        }

        return $seconds;
    }

    /**
     * Split argv at $offset around a `--` end-of-options sentinel.
     * Everything after the sentinel is data and never option-parsed.
     *
     * @param list<string> $argv
     * @return array{0: list<string>, 1: list<string>} tokens subject to
     *         option parsing, and the pure-positional tokens that followed
     *         a `--` sentinel (empty list when there was none)
     */
    private static function splitPositional(array $argv, int $offset): array
    {
        $tokens = array_slice($argv, $offset);
        $stop = array_search('--', $tokens, true);
        if ($stop === false) {
            return [$tokens, []];
        }

        return [array_slice($tokens, 0, $stop), array_slice($tokens, $stop + 1)];
    }

    /**
     * True for anything flag-shaped, except the bare "-" STDIN convention
     * (documented on import and honored by every subcommand as a positional).
     */
    private static function isOptionLike(string $token): bool
    {
        return $token !== '-' && \str_starts_with($token, '-');
    }

    /**
     * Refuse more positionals than the subcommand can use, naming the first
     * dropped token so scripts see the same class of error they'd get from
     * a bad flag.
     *
     * @param list<string> $positional
     */
    private static function refuseSurplus(array $positional, int $max, string $command): void
    {
        if (count($positional) > $max) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s takes at most %d argument(s), got extra "%s".',
                    $command,
                    $max,
                    // kv-004: surplus positionals are raw argv bytes echoed back.
                    Store::sanitizeForTty($positional[$max]),
                )
            );
        }
    }

    private static function unknownOption(string $token, string $command): \InvalidArgumentException
    {
        // Report the flag NAME, not any =payload: a typo'd flag may carry
        // secret material, and usage errors echo into terminals, logs,
        // and shell history.
        // kv-004: the flag name is attacker-chosen argv bytes echoed into the
        // refusal — scrub before interpolation (the =payload stays withheld).
        $name = Store::sanitizeForTty((string) \strtok($token, '='));

        return new \InvalidArgumentException(
            sprintf('Unknown option "%s" for %s. Run "candy-kv --help" for usage.', $name, $command)
        );
    }
}
