<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

/**
 * Tiny lossless SQL lexer for the completion context classifier (SQL-O04).
 *
 * It only has to answer "what is at / before the caret": quoted identifiers
 * ("x", `x`, [x]), string literals and comments must be skipped as opaque
 * units so a keyword or dot inside them never changes the context. Offsets
 * are CODEPOINT offsets (the TextArea column unit), whitespace is dropped.
 *
 * Token shape: ['t' => word|quoted|string|comment|number|punct,
 *               'v' => raw text, 's' => start, 'e' => end (exclusive),
 *               'open' => true when the quote/comment never closed].
 *
 * No upstream Go twin — jorgerojas26/lazysql's completion is keyword-level; this is ours.
 *
 * @phpstan-type Token array{t: string, v: string, s: int, e: int, open: bool}
 */
final class SqlTokenizer
{
    /**
     * @return list<array{t: string, v: string, s: int, e: int, open: bool}>
     */
    public static function tokenize(string $sql): array
    {
        $c = preg_split('//u', $sql, -1, PREG_SPLIT_NO_EMPTY);
        if ($c === false) {
            return [];
        }
        $n = count($c);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $ch = $c[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            $s = $i;
            if ($ch === '-' && ($c[$i + 1] ?? '') === '-') {
                while ($i < $n && $c[$i] !== "\n") {
                    $i++;
                }
                $out[] = self::tok('comment', $c, $s, $i, $i >= $n);
                continue;
            }
            if ($ch === '/' && ($c[$i + 1] ?? '') === '*') {
                $i += 2;
                $closed = false;
                while ($i < $n) {
                    if ($c[$i] === '*' && ($c[$i + 1] ?? '') === '/') {
                        $i += 2;
                        $closed = true;
                        break;
                    }
                    $i++;
                }
                $out[] = self::tok('comment', $c, $s, $i, !$closed);
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`' || $ch === '[') {
                $close = $ch === '[' ? ']' : $ch;
                $i++;
                $closed = false;
                while ($i < $n) {
                    if ($c[$i] === $close) {
                        if ($close !== ']' && ($c[$i + 1] ?? '') === $close) {
                            $i += 2; // doubled quote is an escaped quote
                            continue;
                        }
                        $i++;
                        $closed = true;
                        break;
                    }
                    $i++;
                }
                $out[] = self::tok($ch === "'" ? 'string' : 'quoted', $c, $s, $i, !$closed);
                continue;
            }
            if (preg_match('/^[\p{L}_]$/u', $ch) === 1) {
                while ($i < $n && preg_match('/^[\p{L}\p{N}_$]$/u', $c[$i]) === 1) {
                    $i++;
                }
                $out[] = self::tok('word', $c, $s, $i, false);
                continue;
            }
            if (ctype_digit($ch)) {
                while ($i < $n && (ctype_digit($c[$i]) || $c[$i] === '.')) {
                    $i++;
                }
                $out[] = self::tok('number', $c, $s, $i, false);
                continue;
            }
            $i++;
            $out[] = self::tok('punct', $c, $s, $i, false);
        }
        return $out;
    }

    /**
     * Identifier text with its quotes stripped (word passes through).
     *
     * @param array{t: string, v: string, s: int, e: int, open: bool} $tok
     */
    public static function unquote(array $tok): string
    {
        if ($tok['t'] !== 'quoted') {
            return $tok['v'];
        }
        $inner = mb_substr($tok['v'], 1, $tok['open'] ? null : mb_strlen($tok['v']) - 2);
        $q = mb_substr($tok['v'], 0, 1);
        return $q === '[' ? $inner : str_replace($q . $q, $q, $inner);
    }

    /**
     * @param list<string> $c
     * @return array{t: string, v: string, s: int, e: int, open: bool}
     */
    private static function tok(string $type, array $c, int $s, int $e, bool $open): array
    {
        return ['t' => $type, 'v' => implode('', array_slice($c, $s, $e - $s)), 's' => $s, 'e' => $e, 'open' => $open];
    }
}
