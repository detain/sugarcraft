<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Code\Chat;

/**
 * Chat is 103 constructor fields deep, and {@see Chat::mutate()} re-keys them
 * into `new self(...)` by a HAND-MAINTAINED literal array: one `'name' => $this->name`
 * row per field, 103 rows today, kept in step purely by discipline. The map's own
 * comment (src/Chat.php, F11 scar) states the failure mode: a field missing from
 * this map silently resets on the next keystroke — the user types one character
 * and some piece of live session state evaporates. Nothing in the suite noticed,
 * because each reset only shows up in whichever feature reads the dropped field.
 *
 * This file is the structural guard the finding asked for. It reflects the REAL
 * constructor surface (live class, so promoted and plain params alike are seen)
 * against the REAL carry map (source slice, so the guard needs no object graph),
 * in both directions:
 *
 *   - a constructor parameter with no row → RED (the silent-reset bug, pinned);
 *   - a row whose name is no longer a constructor parameter → RED (a stale row
 *     is dead code that will mis-lead the next reader about what the map covers);
 *   - duplicate rows → RED (array literals silently last-write, hiding the first);
 *   - a row that does not read exactly `$this->itsOwnName` → RED (shape law: the
 *     map's whole claim is that it carries the CURRENT field through; a row that
 *     computes something else is a different kind of statement and belongs in the
 *     body below the literal, where the two conditional blocks live, reviewed as
 *     such);
 *   - the literal's merge target rewritten away from `new self(...array_merge(
 *     $constructorProps, $changes))` → RED (the census polices THIS mechanism; if
 *     the mechanism changes, the guard must be re-derived consciously, not left
 *     reading a map nobody spreads).
 *
 * Exact parity (103/103, set-difference empty both ways) is asserted as
 * SUBSET-PAIRS, never as a pinned numeral: legitimate field additions with their
 * rows must stay green, and only the unpaired shapes go red.
 *
 * COUPLING DISCLOSED (for the F11/F13 split program): the parser anchors on the
 * `mutate` name token and then on the `$constructorProps` variable INSIDE
 * Chat::mutate()'s body. It survives the literal growing, shrinking, comment
 * churn, and re-ordering — it does NOT survive Chat::mutate() moving to another
 * file or the map variable being renamed. Either move is a deliberate refactor
 * that must update these anchors in the same commit (W7 weld note).
 */
final class MutateCarryCensusTest extends TestCase
{
    /**
     * Every constructor parameter rides the carry map. This is the arm that
     * goes red the moment a field is added to Chat::__construct without its
     * `'name' => $this->name` row — the exact silent-reset hazard of F11.
     */
    public function testEveryConstructorParameterRidesTheCarryMap(): void
    {
        $missing = array_values(array_diff(self::constructorFieldNames(), array_keys(self::carryMapRows())));
        $this->assertSame(
            [],
            $missing,
            'constructor field(s) missing from Chat::mutate()\'s carry map - a field not carried '
            .'silently resets on the next keystroke; add the \'name\' => $this->name row'
        );
    }

    /**
     * No carry row exists for a field the constructor no longer takes. The
     * mirror arm: a stale row looks authoritative to the next reader (and,
     * spread into `new self(...)`, a named argument for a non-parameter would
     * fatal at runtime on the first keystroke that takes this path).
     */
    public function testTheCarryMapHoldsNoRowsForFieldsTheConstructorNoLongerTakes(): void
    {
        $stale = array_values(array_diff(array_keys(self::carryMapRows()), self::constructorFieldNames()));
        $this->assertSame(
            [],
            $stale,
            'carry map row(s) name no constructor parameter - delete the row (or the parameter was '
            .'renamed and every caller needs a conscious look, not a half-migrated map)'
        );
    }

    /**
     * Shape law: every row is exactly `'name' => $this->name,` and no name
     * appears twice. A row that computes, or a doubled key, means the map has
     * started carrying something other than the current field — that is a
     * different statement and it has to be taken out of the literal and looked
     * at on purpose.
     */
    public function testEveryCarriedRowReadsItsOwnFieldByThatName(): void
    {
        $rows = self::carryMapRows();
        $doubles = array_keys(array_filter(array_count_values(array_keys($rows)), static fn(int $c): bool => $c > 1));
        $this->assertSame([], $doubles, 'duplicate keys in the carry literal: PHP keeps the last write and hides the first');

        $offShape = [];
        foreach ($rows as $name => $expression) {
            if ($expression !== '$this->'.$name) {
                $offShape[] = $name.' => '.$expression;
            }
        }
        $this->assertSame(
            [],
            $offShape,
            'carry map row(s) do not read their own field verbatim; anything computed belongs in a named '
            .'statement below the literal, not inside the census-covered map'
        );
    }

    /**
     * The mechanism the census polices is alive: the literal is still what gets
     * spread into the new instance. If this goes red, mutate()'s merge target
     * changed shape and THIS FILE must be re-derived consciously - a census
     * quietly reading a dead array proves nothing.
     */
    public function testTheCarryMapIsSpreadIntoTheNewInstance(): void
    {
        $tail = self::mutateBodyTailAfterLiteral();
        $this->assertStringContainsString(
            // Token text flattened: 'new' and 'self' are separate tokens, so the
            // space between them is gone ('newself').
            'newself(...array_merge($constructorProps,$changes))',
            $tail,
            'Chat::mutate() no longer ends by spreading the carried literal into new self(); '
            .'the carry map this census reads may no longer be the live mechanism'
        );
    }

    /**
     * Live constructor parameter names, promoted or not, in declaration order.
     *
     * @return list<string>
     */
    private static function constructorFieldNames(): array
    {
        return array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Chat::class, '__construct'))->getParameters(),
        );
    }

    /**
     * The carry literal as written in source: key => verbatim value expression,
     * in file order, comments dropped, whitespace normalised.
     *
     * @return array<string, string>
     */
    private static function carryMapRows(): array
    {
        [$tokens, $from, $to] = self::carryLiteralBounds();
        $rows = [];
        $i = $from + 1;
        while ($i < $to) {
            $token = $tokens[$i];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $i++;
                continue;
            }
            if (!$token->is(T_CONSTANT_ENCAPSED_STRING)) {
                throw new RuntimeException(
                    'Chat::mutate() carry literal no longer consists of \'name\' => value rows '
                    .'(found '.$token->text.' at line '.$token->line.'); the census needs re-derivation.'
                );
            }
            $name = trim($token->text, "'\"");
            $valueParts = [];
            $depth = 0;
            for ($j = $i + 1; $j < $to; $j++) {
                $candidate = $tokens[$j];
                $text = $candidate->text;
                // Interpolation openers are ARRAY tokens whose text is `{`
                // (T_CURLY_OPEN) or `${` (T_DOLLAR_OPEN_CURLY_BRACES); the
                // bare-string leg only catches the first, by accident.
                if (in_array($text, ['[', '(', '{'], true) || $candidate->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif (in_array($text, [']', ')', '}'], true)) {
                    $depth--;
                } elseif ($text === ',' && $depth === 0) {
                    break;
                } elseif ($text === '=>' && $valueParts === []) {
                    continue;
                } elseif ($candidate->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }
                $valueParts[] = $text;
            }
            $rows[$name] = implode('', $valueParts);
            // Skip past this row's terminating comma so the loop resumes at the next key.
            $depth = 0;
            for (; $i < $to; $i++) {
                $text = $tokens[$i]->text;
                if (in_array($text, ['[', '(', '{'], true) || $tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif (in_array($text, [']', ')', '}'], true)) {
                    $depth--;
                } elseif ($text === ',' && $depth === 0) {
                    $i++;
                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * Source text of mutate()'s body after the carry literal's closing bracket,
     * whitespace flattened, so the spread shape can be matched without caring
     * about formatting.
     */
    private static function mutateBodyTailAfterLiteral(): string
    {
        [$tokens, , $to] = self::carryLiteralBounds();
        // Walk to the method's closing brace: the literal close consumed its own
        // bracket, so the first depth-0 '}' ends mutate()'s body.
        $body = '';
        $depth = 0;
        for ($i = $to + 1; $i < count($tokens); $i++) {
            $text = $tokens[$i]->text;
            if ($text === '{' || $tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($text === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
            if (!$tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $body .= $text;
            }
        }

        return $body;
    }

    /**
     * Anchors: the `mutate` name token, then the `$constructorProps = [`
     * assignment inside its body, then the bracket-matched close of that
     * literal. Every anchor fails LOUD (RuntimeException names what it looked
     * for) - a census that cannot find its subject must not report it as clean.
     *
     * @return array{0: list<\PhpToken>, 1: int, 2: int} tokens, open index, close index
     */
    private static function carryLiteralBounds(): array
    {
        $path = dirname(__DIR__, 2).'/src/Chat.php';
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('the census cannot read its subject: '.$path.' is unreadable');
        }
        $tokens = \PhpToken::tokenize($source);
        $count = count($tokens);

        $mutate = null;
        for ($k = 0; $k < $count; $k++) {
            if (!$tokens[$k]->is(T_STRING) || $tokens[$k]->text !== 'mutate') {
                continue;
            }
            for ($b = $k - 1; $b >= 0; $b--) {
                if ($tokens[$b]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }
                if ($tokens[$b]->is(T_FUNCTION)) {
                    $mutate = $k;
                }
                break;
            }
            if ($mutate !== null) {
                break;
            }
        }
        if ($mutate === null) {
            throw new RuntimeException('no function named mutate() in src/Chat.php - the carry map moved or was renamed; re-derive this census in the same commit');
        }

        $open = null;
        for ($k = $mutate; $k < $count; $k++) {
            if (!$tokens[$k]->is(T_VARIABLE) || $tokens[$k]->text !== '$constructorProps') {
                continue;
            }
            $sawEquals = false;
            for ($m = $k + 1; $m < $count; $m++) {
                if ($tokens[$m]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }
                if (!$sawEquals) {
                    if ($tokens[$m]->text !== '=') {
                        break;
                    }
                    $sawEquals = true;
                    continue;
                }
                if ($tokens[$m]->text === '[') {
                    $open = $m;
                }
                break;
            }
            if ($open !== null) {
                break;
            }
        }
        if ($open === null) {
            throw new RuntimeException('no `$constructorProps = [` literal inside mutate() - the map variable was renamed or restructured; re-derive this census in the same commit');
        }

        $depth = 0;
        $close = null;
        for ($k = $open; $k < $count; $k++) {
            $text = $tokens[$k]->text;
            if ($text === '[' || $text === '(' || $text === '{' || $tokens[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($text === ']' || $text === ')' || $text === '}') {
                $depth--;
                if ($depth === 0) {
                    $close = $k;
                    break;
                }
            }
        }
        if ($close === null) {
            throw new RuntimeException('unbalanced brackets around the carry literal');
        }

        return [$tokens, $open, $close];
    }
}
