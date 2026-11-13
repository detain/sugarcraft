<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\Identifier;

/**
 * Completion for the Query pane (SQL-O04): keywords, plus the schema leg —
 * table names after FROM/JOIN/INTO/UPDATE/TABLE, and columns in context
 * (unqualified columns of the statement's tables, `alias.`/`table.`
 * qualified columns, the column list of INSERT INTO t (...)).
 *
 * Free of editor coupling: {@see complete()} takes the SQL text and a
 * codepoint caret offset and answers a {@see Completion}; the App folds that
 * into keystrokes on the TextArea. Schema knowledge arrives as a table-name
 * list plus a lazy `columnsOf(table)` closure, so columns are only fetched
 * for tables the statement actually mentions. With no schema (no tables
 * known) every context degrades to the keyword roster.
 *
 * Mirrors the completion ergonomics of jorgerojas26/lazysql at the caret
 * (unique completes, prefix advances, ambiguous lists); no upstream Go twin
 * class — the roster, caret grammar and context classifier are ours.
 */
final class Completer
{
    /**
     * Core SQL vocabulary: standard clauses, DDL/DML verbs, and the
     * SQLite/MySQL/PostgreSQL keywords a reader is most likely to want at
     * the caret. Uppercase is the shipped spelling — completion inserts
     * keywords as written here (SQL keyword case is cosmetic; upper matches
     * every style in this repo's docs and examples).
     */
    public const KEYWORDS = [
        'ABORT', 'ACTION', 'ADD', 'AFTER', 'ALL', 'ALTER', 'ANALYZE', 'AND',
        'AS', 'ASC', 'ATTACH', 'AUTO_INCREMENT', 'BEGIN', 'BETWEEN', 'BIGINT',
        'BLOB', 'BOOL', 'BOOLEAN', 'BY', 'CACHE', 'CASCADE', 'CASE', 'CHANGE',
        'CHAR', 'CHARACTER', 'CHECK', 'COLLATE', 'COLUMN', 'COMMIT', 'CONCURRENTLY',
        'CONFLICT', 'CONSTRAINT', 'CONTINUE', 'CONVERT', 'COUNT', 'CREATE', 'CROSS',
        'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'CURRENT_USER',
        'DATABASE', 'DATE', 'DATETIME', 'DEALLOCATE', 'DEFAULT', 'DEFERRABLE',
        'DEFERRED', 'DELETE', 'DESC', 'DESCRIBE', 'DETACH', 'DISTINCT', 'DO',
        'DROP', 'EACH', 'ELSE', 'END', 'ESCAPE', 'EXCEPT', 'EXCLUDE', 'EXECUTE',
        'EXISTS', 'EXPLAIN', 'FAIL', 'FALSE', 'FETCH', 'FIRST', 'FLOAT', 'FOR',
        'FOREIGN', 'FORUPDATE', 'FROM', 'FULL', 'GENERATED', 'GLOBAL', 'GRANT',
        'GROUP', 'GROUPING', 'HAVING', 'IF', 'IGNORE', 'ILIKE', 'IMMEDIATE',
        'IN', 'INDEX', 'INHERITS', 'INITIALLY', 'INNER', 'INSTEAD', 'INSERT',
        'INSTALL', 'INT', 'INTEGER', 'INTERSECT', 'INTO', 'IS', 'ISNULL', 'JOIN',
        'KEY', 'LEFT', 'LIKE', 'LIMIT', 'LIST', 'LOAD', 'LOCAL', 'LOCK',
        'LONGTEXT', 'MATCH', 'MATERIALIZED', 'MODIFY', 'NATURAL', 'NOT',
        'NOTNULL', 'NULL', 'NULLS', 'OF', 'OFFSET', 'ON', 'ONLY', 'OPTIMIZE',
        'OR', 'ORDER', 'OUTER', 'OVER', 'OVERLAPS', 'PARTITION', 'PLAN',
        'PRAGMA', 'PRECEDING', 'PREPARE', 'PRIMARY', 'PRIORITY', 'PROCEDURE',
        'PUBLIC', 'RAISE', 'RANGE', 'READ', 'RECURSIVE', 'REFERENCES',
        'REINDEX', 'RELEASE', 'RENAME', 'REPEATABLE', 'REPLACE', 'RESTRICT',
        'RETURNING', 'REVOKE', 'RIGHT', 'ROLLBACK', 'ROW', 'ROWS', 'SAVEPOINT',
        'SCHEMA', 'SECURITY', 'SELECT', 'SEQUENCE', 'SERIALIZABLE', 'SESSION',
        'SET', 'SHARE', 'SHOW', 'SKIPPED', 'STRICT', 'TABLE', 'TABLESPACE',
        'TEMP', 'TEMPLATE', 'TEMPORARY', 'TEXT', 'THEN', 'TIES', 'TIME',
        'TIMESTAMP', 'TO', 'TRANSACTION', 'TRIGGER', 'TRUE', 'TRUNCATE',
        'UNBOUNDED', 'UNCOMMITTED', 'UNDEFINED', 'UNINSTALL', 'UNION',
        'UNIQUE', 'UNKNOWN', 'UPDATE', 'UPGRADE', 'USER', 'USING', 'VACUUM',
        'VALUES', 'VIEW', 'VIRTUAL', 'WHEN', 'WHERE', 'WINDOW', 'WITH',
        'WITHOUT', 'WORK', 'WRITE',
    ];

    /** Words that open a table-name position. */
    private const TABLE_KW = ['FROM', 'JOIN', 'INTO', 'UPDATE', 'TABLE'];

    /** Clause keywords the backwards scan stops at. */
    private const CLAUSE_KW = [
        'SELECT', 'FROM', 'JOIN', 'WHERE', 'ON', 'BY', 'HAVING', 'SET', 'INTO', 'UPDATE',
        'TABLE', 'VALUES', 'USING', 'LIMIT', 'OFFSET', 'RETURNING', 'UNION', 'EXCEPT', 'INTERSECT',
    ];

    /** Clauses whose operands are column references. */
    private const COLUMN_CLAUSES = ['SELECT', 'WHERE', 'ON', 'BY', 'HAVING', 'SET', 'USING', 'RETURNING'];

    /** Words after which an operand (column) is expected. */
    private const OPERAND_KW = [
        'SELECT', 'WHERE', 'ON', 'BY', 'HAVING', 'SET', 'AND', 'OR', 'NOT', 'DISTINCT', 'ALL',
        'CASE', 'WHEN', 'THEN', 'ELSE', 'LIKE', 'IN', 'BETWEEN', 'IS', 'USING', 'RETURNING',
    ];

    /**
     * Classify the caret position in $sql and gather its candidates.
     *
     * @param string                 $sql       Whole editor text.
     * @param int                    $caret     Codepoint offset of the caret in $sql.
     * @param list<string>           $tables    Known table names ([] = no schema loaded).
     * @param \Closure(string): list<string> $columnsOf Column names of a table (lazy; only
     *                                          called for tables the statement names).
     * @param ?Flavor                $flavor    Quoting dialect for identifiers that need it.
     */
    public static function complete(
        string $sql,
        int $caret,
        array $tables = [],
        ?\Closure $columnsOf = null,
        ?Flavor $flavor = null,
    ): Completion {
        $all = SqlTokenizer::tokenize($sql);

        // Statement bounds: the tokens between the ';' before and after the caret.
        $stmt = [];
        foreach ($all as $t) {
            if ($t['t'] === 'punct' && $t['v'] === ';') {
                if ($t['e'] <= $caret) {
                    $stmt = [];
                    continue;
                }
                if ($t['s'] >= $caret) {
                    break;
                }
            }
            $stmt[] = $t;
        }

        // The token the caret sits in (a word, or inside an identifier quote).
        $cur = null;
        foreach ($stmt as $t) {
            if ($t['s'] >= $caret) {
                break;
            }
            $inside = $caret < $t['e'] || ($caret === $t['e'] && ($t['t'] === 'word' || $t['open']));
            if (!$inside) {
                continue;
            }
            if ($t['t'] === 'string' || $t['t'] === 'comment') {
                return new Completion('none', '', []);
            }
            if ($t['t'] === 'word' || $t['t'] === 'quoted') {
                $cur = $t;
            }
        }

        $inQuote = $cur !== null && $cur['t'] === 'quoted';
        $closeQuote = '';
        if ($cur === null) {
            $prefix = '';
            $limit = $caret;
        } elseif ($inQuote) {
            $prefix = mb_substr($sql, $cur['s'] + 1, $caret - $cur['s'] - 1);
            $limit = $cur['s'];
            if ($cur['open']) {
                $q = mb_substr($cur['v'], 0, 1);
                $closeQuote = $q === '[' ? ']' : $q;
            }
        } else {
            $prefix = mb_substr($sql, $cur['s'], $caret - $cur['s']);
            $limit = $cur['s'];
        }

        $before = [];
        foreach ($stmt as $t) {
            if ($t['e'] <= $limit) {
                $before[] = $t;
            }
        }

        $scope = self::scope($stmt, $tables);
        $names = [];
        $fetch = static function (string $table) use ($columnsOf): array {
            return $columnsOf === null ? [] : $columnsOf($table);
        };

        $n = count($before);
        $last = $n > 0 ? $before[$n - 1] : null;

        // Qualified: `x.` / `x.pre` — columns of the alias/table x.
        if ($last !== null && $last['t'] === 'punct' && $last['v'] === '.' && $n >= 2
            && ($before[$n - 2]['t'] === 'word' || $before[$n - 2]['t'] === 'quoted')) {
            $qualifier = SqlTokenizer::unquote($before[$n - 2]);
            $table = self::resolveQualifier($qualifier, $scope, $tables);
            if ($table !== null) {
                $cols = $fetch($table);
                return self::build('qualified', $prefix, $cols, $inQuote, $closeQuote, $flavor, $qualifier);
            }
            return new Completion('qualified', $prefix, [], $qualifier);
        }

        $kind = self::kindAt($before);

        if ($kind[0] === 'table' && $tables !== []) {
            return self::build('table', $prefix, $tables, $inQuote, $closeQuote, $flavor);
        }

        if ($kind[0] === 'column' && $tables !== []) {
            $cols = [];
            if ($kind[1] !== null) {
                // INSERT INTO t (...) names its own target.
                $target = self::resolveQualifier($kind[1], $scope, $tables);
                $cols = $target !== null ? $fetch($target) : [];
            } else {
                $seen = [];
                foreach ($scope as $entry) {
                    if (isset($seen[strtolower($entry['table'])])) {
                        continue;
                    }
                    $seen[strtolower($entry['table'])] = true;
                    foreach ($fetch($entry['table']) as $col) {
                        $cols[] = $col;
                    }
                }
            }
            $built = self::build('column', $prefix, $cols, $inQuote, $closeQuote, $flavor);
            if ($built->candidates !== [] || $inQuote) {
                return $built;
            }
            // Nothing in the tables matches: the keyword roster still applies
            // (functions, DISTINCT, CASE ...) — never a dead end.
        }

        return new Completion('keyword', $prefix, self::candidates($prefix));
    }

    /**
     * Table list of the statement: alias (or table name) → real table.
     * Entries are limited to tables the schema actually has.
     *
     * @param list<array{t: string, v: string, s: int, e: int, open: bool}> $stmt
     * @param list<string> $tables
     * @return list<array{alias: string, table: string}>
     */
    private static function scope(array $stmt, array $tables): array
    {
        $known = [];
        foreach ($tables as $t) {
            $known[strtolower($t)] = $t;
        }
        $out = [];
        $count = count($stmt);
        $expect = false;
        $inFrom = false;
        for ($i = 0; $i < $count; $i++) {
            $t = $stmt[$i];
            $up = $t['t'] === 'word' ? strtoupper($t['v']) : '';
            if ($up !== '' && in_array($up, self::TABLE_KW, true)) {
                $expect = true;
                $inFrom = $up !== 'INTO' && $up !== 'TABLE';
                continue;
            }
            if ($up !== '' && in_array($up, self::CLAUSE_KW, true)) {
                $inFrom = false;
                $expect = false;
                continue;
            }
            if ($t['t'] === 'punct' && $t['v'] === ',' && $inFrom) {
                $expect = true;
                continue;
            }
            if (!$expect) {
                continue;
            }
            if ($t['t'] !== 'word' && $t['t'] !== 'quoted') {
                $expect = false;
                continue;
            }
            // schema.table — the last dotted part is the table.
            $name = SqlTokenizer::unquote($t);
            while (($stmt[$i + 1]['v'] ?? '') === '.' && isset($stmt[$i + 2])
                && ($stmt[$i + 2]['t'] === 'word' || $stmt[$i + 2]['t'] === 'quoted')) {
                $name = SqlTokenizer::unquote($stmt[$i + 2]);
                $i += 2;
            }
            $expect = false;
            $alias = $name;
            $next = $stmt[$i + 1] ?? null;
            if ($next !== null && $next['t'] === 'word' && strtoupper($next['v']) === 'AS' && isset($stmt[$i + 2])
                && ($stmt[$i + 2]['t'] === 'word' || $stmt[$i + 2]['t'] === 'quoted')) {
                $alias = SqlTokenizer::unquote($stmt[$i + 2]);
                $i += 2;
            } elseif ($next !== null && ($next['t'] === 'quoted'
                || ($next['t'] === 'word' && !in_array(strtoupper($next['v']), self::KEYWORDS, true)))) {
                $alias = SqlTokenizer::unquote($next);
                $i += 1;
            }
            if (isset($known[strtolower($name)])) {
                $out[] = ['alias' => $alias, 'table' => $known[strtolower($name)]];
            }
        }
        return $out;
    }

    /**
     * @param list<array{alias: string, table: string}> $scope
     * @param list<string> $tables
     */
    private static function resolveQualifier(string $qualifier, array $scope, array $tables): ?string
    {
        $q = strtolower($qualifier);
        foreach ($scope as $entry) {
            if (strtolower($entry['alias']) === $q) {
                return $entry['table'];
            }
        }
        foreach ($scope as $entry) {
            if (strtolower($entry['table']) === $q) {
                return $entry['table'];
            }
        }
        foreach ($tables as $t) {
            if (strtolower($t) === $q) {
                return $t;
            }
        }
        return null;
    }

    /**
     * What the position after $before expects: [kind, intoTarget|null].
     *
     * @param list<array{t: string, v: string, s: int, e: int, open: bool}> $before
     * @return array{0: string, 1: ?string}
     */
    private static function kindAt(array $before): array
    {
        $n = count($before);
        if ($n === 0) {
            return ['keyword', null];
        }
        $last = $before[$n - 1];
        $word = $last['t'] === 'word' ? strtoupper($last['v']) : '';

        if ($word !== '' && in_array($word, self::TABLE_KW, true)) {
            return ['table', null];
        }
        if ($word !== '' && in_array($word, self::OPERAND_KW, true)) {
            return ['column', null];
        }
        if ($last['t'] !== 'punct') {
            return ['keyword', null];
        }

        $nearest = static function (int $from) use ($before): string {
            for ($i = $from; $i >= 0; $i--) {
                if ($before[$i]['t'] === 'word') {
                    $up = strtoupper($before[$i]['v']);
                    if (in_array($up, self::CLAUSE_KW, true)) {
                        return $up;
                    }
                }
            }
            return '';
        };

        if ($last['v'] === ',') {
            $clause = $nearest($n - 2);
            if ($clause === 'FROM' || $clause === 'JOIN' || $clause === 'UPDATE') {
                return ['table', null];
            }
            if ($clause === 'INTO') {
                return ['column', self::intoTarget($before, $n - 2)];
            }
            return in_array($clause, self::COLUMN_CLAUSES, true) ? ['column', null] : ['keyword', null];
        }

        if ($last['v'] === '(') {
            $prev = $n >= 2 ? $before[$n - 2] : null;
            $prevWord = $prev !== null && $prev['t'] === 'word' ? strtoupper($prev['v']) : '';
            if ($prev !== null && $n >= 3 && strtoupper($before[$n - 3]['v']) === 'INTO'
                && ($prev['t'] === 'word' || $prev['t'] === 'quoted')) {
                return ['column', SqlTokenizer::unquote($prev)];
            }
            if (in_array($prevWord, self::TABLE_KW, true) || $prevWord === 'VALUES') {
                return ['keyword', null];
            }
            return in_array($nearest($n - 2), self::COLUMN_CLAUSES, true) ? ['column', null] : ['keyword', null];
        }

        if (in_array($last['v'], ['=', '<', '>', '+', '-', '*', '/', '%', '|', '!', '~'], true)) {
            // `SELECT *` / `, *` / `(*` is a wildcard, not a multiplication.
            if ($last['v'] === '*') {
                $before2 = $n >= 2 ? $before[$n - 2] : null;
                if ($before2 === null || ($before2['t'] === 'punct' && in_array($before2['v'], [',', '('], true))
                    || ($before2['t'] === 'word' && in_array(strtoupper($before2['v']), ['SELECT', 'DISTINCT', 'ALL'], true))) {
                    return ['keyword', null];
                }
            }
            return in_array($nearest($n - 2), self::COLUMN_CLAUSES, true) ? ['column', null] : ['keyword', null];
        }

        return ['keyword', null];
    }

    /**
     * Target table of `INSERT INTO t (a, b, |` found by walking back from $from.
     *
     * @param list<array{t: string, v: string, s: int, e: int, open: bool}> $before
     */
    private static function intoTarget(array $before, int $from): ?string
    {
        for ($i = $from; $i >= 1; $i--) {
            if ($before[$i - 1]['t'] === 'word' && strtoupper($before[$i - 1]['v']) === 'INTO'
                && ($before[$i]['t'] === 'word' || $before[$i]['t'] === 'quoted')) {
                return SqlTokenizer::unquote($before[$i]);
            }
        }
        return null;
    }

    /**
     * Prefix-filter $names (case-insensitive, deduped) into a Completion,
     * quoting spellings that cannot stand bare when not already inside a quote.
     *
     * @param list<string> $names
     */
    private static function build(
        string $kind,
        string $prefix,
        array $names,
        bool $inQuote,
        string $closeQuote,
        ?Flavor $flavor,
        string $qualifier = '',
    ): Completion {
        $needle = mb_strtolower($prefix);
        $seen = [];
        $out = [];
        foreach ($names as $name) {
            if ($needle !== '' && !str_starts_with(mb_strtolower($name), $needle)) {
                continue;
            }
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $bare = preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $name) === 1;
            $out[] = $inQuote || $bare ? $name : Identifier::quote($flavor ?? Flavor::Sqlite, $name);
        }
        return new Completion($kind, $prefix, $out, $qualifier, $inQuote, $closeQuote);
    }

    /**
     * Keywords completing $prefix (case-insensitive substring up to the
     * caret), roster order. Empty array when nothing matches.
     *
     * @return list<string>
     */
    public static function candidates(string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }
        $needle = strtoupper($prefix);
        return array_values(array_filter(
            self::KEYWORDS,
            static fn (string $kw): bool => str_starts_with($kw, $needle),
        ));
    }

    /**
     * Longest common prefix of $words, compared case-insensitively and
     * spelled as the first word writes it ('' when empty). The
     * tab-completion courtesy: 'inse' -> 'INSERT' advances through several
     * candidates in one press, and 'Users'/'users_old' advance to 'Users'.
     *
     * @param list<string> $words
     */
    public static function longestCommonPrefix(array $words): string
    {
        if ($words === []) {
            return '';
        }
        $first = (string) $words[0];
        $len = mb_strlen($first);
        foreach ($words as $word) {
            $i = 0;
            $max = min($len, mb_strlen($word));
            while ($i < $max && mb_strtolower(mb_substr($first, $i, 1)) === mb_strtolower(mb_substr($word, $i, 1))) {
                $i++;
            }
            $len = $i;
            if ($len === 0) {
                return '';
            }
        }
        return mb_substr($first, 0, $len);
    }

    /**
     * Text to type for $c: the lone candidate, or the shared prefix when it
     * is longer than what is already typed, or '' (ambiguous: list instead).
     */
    public static function insertion(Completion $c): string
    {
        if (count($c->candidates) === 1) {
            return $c->candidates[0];
        }
        $common = self::longestCommonPrefix($c->candidates);
        return mb_strlen($common) > mb_strlen($c->prefix) ? $common : '';
    }

    /**
     * The word prefix at the caret: trailing [A-Za-z_] run of the text BEFORE
     * column $col on line $lines[$row]. A word touching the caret must end at
     * the caret (classic completion semantics: text after the caret is a
     * different word).
     */
    public static function wordAt(array $lines, int $row, int $col): string
    {
        $line = (string) ($lines[$row] ?? '');
        $before = substr($line, 0, max(0, $col));
        return preg_match('/[A-Za-z_]*$/', $before, $m) === 1 ? $m[0] : '';
    }
}
