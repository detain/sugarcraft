<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

/**
 * Keyword-only completion for the Query pane (SQL-O04, owner ruling 3a: the
 * v1 scope is the keyword leg; the schema leg — tables after FROM/JOIN and
 * columns in context — rides a follow-up and is disclosed as deferred).
 *
 * This class is deliberately free of editor coupling: it answers "given the
 * word prefix at the caret, which keywords does the roster offer?" The App
 * folds that answer into keystrokes on the TextArea. The keyword-only v1
 * shape mirrors jorgerojas26/lazysql's completion ergonomics at the caret
 * (unique completes, prefix advances, ambiguous lists); no upstream Go twin
 * class — the roster and caret grammar are ours.
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
     * Longest common uppercase prefix of $words ('' when empty). The
     * tab-completion courtesy: 'gr' → 'GR' is nothing new, but 'inse' →
     * 'INSERT' advances through several candidates in one press.
     */
    public static function longestCommonPrefix(array $words): string
    {
        if ($words === []) {
            return '';
        }
        $common = (string) $words[0];
        foreach ($words as $word) {
            $len = min(strlen($common), strlen($word));
            $i = 0;
            while ($i < $len && $common[$i] === $word[$i]) {
                $i++;
            }
            $common = substr($common, 0, $i);
            if ($common === '') {
                return '';
            }
        }
        return $common;
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
