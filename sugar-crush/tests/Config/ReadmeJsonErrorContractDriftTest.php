<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PhpToken;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\NonInteractive;

/**
 * `README.md` describes the `--output-format json` error contract in four
 * separate places, and for a whole round all four described a world the source
 * had left.
 *
 * WHAT HAPPENED. E84 gave `bin/sugarcrush`'s missing-autoload guard a JSON
 * document — `{"result":null,"error":{"type":"installation","message":"…"}}`
 * at exit 2 — where it had previously printed nothing on stdout. README.md
 * belonged to a different lane that round, so it went on saying the opposite in
 * (1) its "there are exactly two exceptions" paragraph, (2) its exit-code
 * table, (3) the sentence enumerating the `error.type` values and (4) the JSON
 * schema block's `"type":` union. Two of those were filed as E94 and E98; the
 * pair of them named all four spots only when read together.
 *
 * WHY THIS IS A TEST AND NOT JUST A REWRITE. Nothing asserted any of the four.
 * `grep -rn 'exactly two exceptions'` hit `README.md` and nothing else, which
 * is precisely why a shipped behaviour change and a document that contradicted
 * it coexisted through a full round with a green suite. The prose is the
 * PUBLISHED contract — a `| jq` consumer reads it and decides whether to parse
 * stdout at all — so the drift is not cosmetic: the old paragraph told that
 * consumer not to bother parsing on the one failure where parsing had just
 * started working.
 *
 * WHAT IS PINNED, AND IN BOTH DIRECTIONS. The error-type set and the type →
 * exit-code mapping are DERIVED from `src/` and `bin/` by token stream, never
 * written down here, and then all four README spots are held to them. A type
 * `src/` emits that the README omits reds; a type the README names that `src/`
 * never emits reds just as loudly, because a consumer branching on a type
 * nothing can produce writes dead code on our say-so.
 *
 * WHY A TOKEN STREAM AND NOT A REGEX OVER THE SOURCE. Because a regex cannot
 * tell an argument from a comment, and both files are unusually comment-heavy:
 * `bin/sugarcrush`'s guard carries roughly sixty lines of justification that
 * quote the very literals being scanned for, and
 * {@see NonInteractive::emitErrorDocument()}'s doc-block carries the five-type
 * table verbatim. Scanning the text would let the DOCUMENTATION satisfy an
 * assertion about the CODE — the same substitution this file exists to catch,
 * committed by the catcher.
 *
 * AND THE SCAN GOES RED ON WHAT IT CANNOT PARSE, rather than skipping it. An
 * `emitErrorDocument()` call whose type argument stops being a single string
 * literal, or one with no `return self::EXIT_*` closing its block, fails this
 * test with a message saying the contract can no longer be derived. A guard
 * that quietly ignores the unparseable has a hole shaped exactly like the next
 * change.
 *
 * THE RETURN-CODE WINDOW IS BOUNDED ON PURPOSE, and it took a known-answer probe
 * to find out that it had to be. The first version walked forward from a call
 * to the next `return self::EXIT_*` anywhere in the file. Deleting the
 * `backend` branch's own `return self::EXIT_FAILURE` left the test GREEN,
 * because the walk ran on and adopted the `encoding` branch's return three
 * statements later — the right answer read out of the wrong place. The walk now
 * stops when the enclosing block closes and when the next
 * `emitErrorDocument()` begins, and that same mutation reds.
 *
 * @internal
 */
final class ReadmeJsonErrorContractDriftTest extends TestCase
{
    /**
     * The two exit constants a document-emitting branch can return, resolved
     * from the class rather than restated, so a renumbering moves both the
     * derivation and the expectation together.
     *
     * @return array<string, int>
     */
    private function exitCodes(): array
    {
        return [
            'EXIT_OK' => NonInteractive::EXIT_OK,
            'EXIT_FAILURE' => NonInteractive::EXIT_FAILURE,
            'EXIT_CONFIG' => NonInteractive::EXIT_CONFIG,
        ];
    }

    private function repoFile(string $relative): string
    {
        $path = __DIR__ . '/../../' . $relative;
        $text = file_get_contents($path);
        self::assertIsString($text, $relative . ' is unreadable');

        return $text;
    }

    private function readme(): string
    {
        return $this->repoFile('README.md');
    }

    /**
     * Significant tokens only — whitespace, comments and doc-blocks dropped, so
     * a literal quoted in prose can never be mistaken for one passed to a call.
     *
     * @return list<PhpToken>
     */
    private function significantTokens(string $source): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
    }

    /**
     * Every `error.type` {@see NonInteractive::emitErrorDocument()} is called
     * with, mapped to the exit code its branch returns.
     *
     * @return array<string, int>
     */
    private function typesFromNonInteractive(): array
    {
        $tokens = $this->significantTokens($this->repoFile('src/Cli/NonInteractive.php'));
        $codes = $this->exitCodes();
        $map = [];
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            if (!$tokens[$i]->is(T_STRING) || $tokens[$i]->text !== 'emitErrorDocument') {
                continue;
            }
            if (($tokens[$i - 1]->text ?? '') !== '::') {
                continue;
            }
            self::assertSame(
                '(',
                $tokens[$i + 1]->text ?? '',
                'emitErrorDocument is referenced without being called; the contract cannot be derived',
            );

            [$args, $closeAt] = $this->argumentTokens($tokens, $i + 2);
            self::assertGreaterThanOrEqual(
                2,
                count($args),
                'emitErrorDocument() is called with fewer than two arguments',
            );

            $typeArg = $args[1];
            self::assertTrue(
                count($typeArg) === 1 && $typeArg[0]->is(T_CONSTANT_ENCAPSED_STRING),
                'emitErrorDocument() is called with a non-literal error type ('
                . implode(' ', array_map(static fn (PhpToken $t): string => $t->text, $typeArg))
                . '). README.md\'s JSON contract is derived from these literals, so it can no longer '
                . 'be checked — give this test a way to read the new shape rather than letting it skip.',
            );
            $type = trim($typeArg[0]->text, "'\"");

            $constant = $this->returnedExitConstant($tokens, $closeAt);
            self::assertNotNull(
                $constant,
                "no `return self::EXIT_*` closes the block that emits the '{$type}' document; "
                . 'the type -> exit-code mapping README.md states cannot be derived',
            );
            self::assertArrayHasKey($constant, $codes, "unknown exit constant self::{$constant}");

            $map[$type] = $codes[$constant];
        }

        self::assertNotSame([], $map, 'no emitErrorDocument() call sites found at all');

        return $map;
    }

    /**
     * Split a call's arguments into token lists.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array{list<list<PhpToken>>, int} the arguments, and the index of the closing `)`
     */
    private function argumentTokens(array $tokens, int $from): array
    {
        $n = count($tokens);
        $depth = 1;
        $args = [];
        $current = [];

        for ($j = $from; $j < $n; $j++) {
            $t = $tokens[$j];
            if ($t->text === '(' || $t->text === '[') {
                $depth++;
            }
            if ($t->text === ')' || $t->text === ']') {
                $depth--;
                if ($depth === 0) {
                    $args[] = $current;

                    return [$args, $j];
                }
            }
            if ($t->text === ',' && $depth === 1) {
                $args[] = $current;
                $current = [];
                continue;
            }
            $current[] = $t;
        }

        self::fail('unterminated argument list at token ' . $from);
    }

    /**
     * The `EXIT_*` constant returned from the block that contains $from.
     *
     * BOUNDED BOTH WAYS. The walk stops when the enclosing block closes and
     * when the next `emitErrorDocument()` call begins, so a branch can never
     * borrow a neighbour's return — see the class doc-block for the mutation
     * that proved an unbounded walk silently did exactly that.
     *
     * @param list<PhpToken> $tokens
     */
    private function returnedExitConstant(array $tokens, int $from): ?string
    {
        $n = count($tokens);
        $brace = 0;

        for ($k = $from + 1; $k < $n; $k++) {
            $t = $tokens[$k];
            if ($t->text === '{') {
                $brace++;
                continue;
            }
            if ($t->text === '}') {
                $brace--;
                if ($brace < 0) {
                    return null;
                }
                continue;
            }
            if ($t->is(T_STRING) && $t->text === 'emitErrorDocument') {
                return null;
            }
            if ($t->is(T_RETURN)
                && ($tokens[$k + 1]->text ?? '') === 'self'
                && ($tokens[$k + 2]->text ?? '') === '::'
                && str_starts_with($tokens[$k + 3]->text ?? '', 'EXIT_')) {
                return $tokens[$k + 3]->text;
            }
        }

        return null;
    }

    /**
     * The one type `bin/sugarcrush`'s autoload guard hand-rolls, and the exit
     * code it leaves with.
     *
     * The guard is a plain script, not a class — the whole point of it is that
     * no class can be loaded at that moment — so it is scanned for its literal
     * `'type' => …` pair and the `exit(<int>)` that follows.
     *
     * @return array{type: string, exit: int, message: string}
     */
    private function typeFromAutoloadGuard(): array
    {
        $tokens = $this->significantTokens($this->repoFile('bin/sugarcrush'));
        $n = count($tokens);
        $type = null;
        $at = null;

        for ($i = 0; $i < $n; $i++) {
            if (!$tokens[$i]->is(T_CONSTANT_ENCAPSED_STRING) || trim($tokens[$i]->text, "'\"") !== 'type') {
                continue;
            }
            if (($tokens[$i + 1]->text ?? '') !== '=>') {
                continue;
            }
            $value = $tokens[$i + 2] ?? null;
            self::assertTrue(
                $value !== null && $value->is(T_CONSTANT_ENCAPSED_STRING),
                "the autoload guard's `type` key no longer has a literal value; README.md's contract "
                . 'cannot be derived from it',
            );
            $type = trim($value->text, "'\"");
            $at = $i;
            break;
        }

        self::assertNotNull($type, "no `'type' => <literal>` pair in bin/sugarcrush's autoload guard");
        self::assertNotNull($at);

        $exit = null;
        for ($i = $at; $i < $n; $i++) {
            if ($tokens[$i]->is(T_EXIT)
                && ($tokens[$i + 1]->text ?? '') === '('
                && ($tokens[$i + 2] ?? null)?->is(T_LNUMBER)) {
                $exit = (int) $tokens[$i + 2]->text;
                break;
            }
        }
        self::assertNotNull($exit, 'no `exit(<int>)` follows the autoload guard document');

        // The message is the `$message = '…';` assignment the guard writes to
        // stderr and reuses as `error.message`.
        $message = null;
        for ($i = 0; $i < $n; $i++) {
            if ($tokens[$i]->is(T_VARIABLE)
                && $tokens[$i]->text === '$message'
                && ($tokens[$i + 1]->text ?? '') === '='
                && ($tokens[$i + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)) {
                $message = trim($tokens[$i + 2]->text, "'\"");
                break;
            }
        }
        self::assertNotNull($message, "no `\$message = '…';` literal in bin/sugarcrush's autoload guard");

        return ['type' => $type, 'exit' => $exit, 'message' => $message];
    }

    /**
     * Every `error.type` this project can put on stdout, mapped to its exit
     * code — the four routed through {@see NonInteractive} plus the one the
     * autoload guard hand-rolls.
     *
     * @return array<string, int>
     */
    private function shippedTypes(): array
    {
        $guard = $this->typeFromAutoloadGuard();
        $map = $this->typesFromNonInteractive();

        self::assertArrayNotHasKey(
            $guard['type'],
            $map,
            "the autoload guard's '{$guard['type']}' type is now also emitted by NonInteractive; "
            . 'either the guard was routed through the class after all, or a name collided',
        );
        $map[$guard['type']] = $guard['exit'];
        ksort($map);

        return $map;
    }

    /**
     * The JSON schema block's `"type":` union must list exactly the types the
     * source can emit — no more, no fewer.
     */
    public function testTheSchemaBlockUnionIsExactlyTheShippedErrorTypeSet(): void
    {
        $flat = (string) preg_replace('/\s+/', ' ', $this->readme());

        self::assertSame(
            1,
            preg_match('/\{"result": null, "error": \{"type": (.*?), "message"/u', $flat, $m),
            'README.md no longer carries the JSON error-document schema block in the shape this test '
            . 'reads (`{"result": null, "error": {"type": … , "message"`)',
        );

        preg_match_all('/"([a-z_]+)"/u', $m[1], $names);
        $inReadme = $names[1];
        sort($inReadme);

        $shipped = array_keys($this->shippedTypes());

        self::assertSame(
            $shipped,
            $inReadme,
            'README.md\'s schema union and the error types src/ actually emits disagree. Both '
            . 'directions are defects: a missing type tells a `| jq` consumer to treat a real failure '
            . 'as unparseable, and an extra one invites a branch nothing can ever reach.',
        );
    }

    /**
     * The enumerating sentence must map every shipped type to the exit code its
     * branch really returns.
     */
    public function testTheEnumeratingSentenceAgreesWithTheExitCodeEachBranchReturns(): void
    {
        $flat = (string) preg_replace('/\s+/', ' ', $this->readme());

        self::assertSame(
            1,
            preg_match('/`error\.type` is not the exit code renamed —(.*?)— it is how a consumer/u', $flat, $m),
            'README.md no longer carries the sentence enumerating the error types against their exit '
            . 'codes in the shape this test reads',
        );

        preg_match_all(
            '/((?:`[a-z_]+`(?:, | and )?)+) are (?:all|both) `(\d)`/u',
            $m[1],
            $clauses,
            PREG_SET_ORDER,
        );
        self::assertNotSame([], $clauses, 'the enumerating sentence names no type/exit-code clause at all');

        $stated = [];
        foreach ($clauses as $clause) {
            preg_match_all('/`([a-z_]+)`/u', $clause[1], $names);
            foreach ($names[1] as $name) {
                $stated[$name] = (int) $clause[2];
            }
        }
        ksort($stated);

        self::assertSame(
            $this->shippedTypes(),
            $stated,
            'README.md\'s "`usage` and `provider_configuration` are both `2`…" sentence and the exit '
            . 'codes the emitting branches return disagree',
        );
    }

    /**
     * The exit-code table's rows must name, per code, exactly the types that
     * reach that code.
     */
    public function testTheExitCodeTableRowsNameExactlyTheTypesThatReachThem(): void
    {
        $expected = [];
        foreach ($this->shippedTypes() as $type => $code) {
            $expected[$code][] = $type;
        }
        foreach ($expected as &$names) {
            sort($names);
        }
        unset($names);
        ksort($expected);

        $found = [];
        foreach (explode("\n", $this->readme()) as $line) {
            if (preg_match('/^\| `(\d)` \|/', $line, $row) !== 1) {
                continue;
            }
            if (preg_match('/`error\.type`: ((?:`[a-z_]+`(?:, )?)+)/u', $line, $types) !== 1) {
                // A code with no document behind it (`0` is the success path)
                // legitimately names none. A code that DOES have types must
                // say so, which the comparison below enforces.
                continue;
            }
            preg_match_all('/`([a-z_]+)`/u', $types[1], $names);
            $list = $names[1];
            sort($list);
            $found[(int) $row[1]] = $list;
        }
        ksort($found);

        self::assertSame(
            $expected,
            $found,
            'README.md\'s exit-code table and the error types that actually reach each code disagree. '
            . 'The table is where a CI author reads which failures are worth retrying, so a type '
            . 'filed under the wrong code is a retry loop or a missed one.',
        );
    }

    /**
     * The guard's own document — type and message — must be quoted in the
     * README as the bytes the guard really prints.
     *
     * Quoted rather than merely described because this is the one document a
     * consumer meets before anything else in the project has loaded, and the
     * README is the only place it is written down outside the guard itself.
     */
    public function testTheReadmeQuotesTheAutoloadGuardsRealDocument(): void
    {
        $guard = $this->typeFromAutoloadGuard();
        // The README wraps prose, so compare against the text with newlines
        // and the wrapping `> ` collapsed away.
        $flat = (string) preg_replace('/\s+/', ' ', str_replace("\n>", "\n", $this->readme()));

        self::assertStringContainsString(
            '"type":"' . $guard['type'] . '"',
            $flat,
            'README.md does not quote the autoload guard\'s document as the guard actually encodes it',
        );
        self::assertStringContainsString(
            $guard['message'],
            $flat,
            'README.md does not quote the autoload guard\'s `error.message` verbatim',
        );
    }

    /**
     * The retracted claim may appear only as a quotation of itself.
     *
     * STRUCTURAL, NOT PROXIMITY-BASED, and deliberately so — the same lesson
     * {@see ReadmeSettingsTierClaimTest} records. A "the correction must be
     * within N characters" rule is satisfied by a restored false sentence that
     * happens to sit inside the window and inherit the correction next to it.
     * The rule here is that the retracted wording lives on a `>` line or
     * nowhere: a blockquote cannot be mistaken for the document speaking in its
     * own voice.
     */
    public function testTheTwoExceptionsClaimSurvivesOnlyAsAQuotedRetraction(): void
    {
        $offending = [];
        $quoted = false;

        foreach (explode("\n", $this->readme()) as $number => $line) {
            if (stripos($line, 'exactly two exceptions') === false) {
                continue;
            }
            if (str_starts_with(ltrim($line), '>')) {
                $quoted = true;
                continue;
            }
            $offending[] = ($number + 1) . ': ' . trim($line);
        }

        self::assertSame(
            [],
            $offending,
            'README.md asserts, in its own voice, that the JSON contract has "exactly two exceptions". '
            . 'It has one. The second — a checkout with no vendor/autoload.php — emits '
            . 'a typed document now, and telling a consumer otherwise tells it not to parse stdout on '
            . 'the one failure where parsing works.',
        );
        self::assertTrue(
            $quoted,
            'README.md dropped the retracted "exactly two exceptions" claim instead of correcting it '
            . 'in place. Delete the reasoning and the next reader restores the sentence.',
        );
    }

    /**
     * The one remaining exception must still be documented as one, so this file
     * cannot be satisfied by deleting the section it checks.
     */
    public function testTheOneRemainingExceptionIsStillNamed(): void
    {
        $flat = (string) preg_replace('/\s+/', ' ', $this->readme());

        self::assertStringContainsString(
            'There is exactly one exception',
            $flat,
            'README.md no longer states how many exceptions the "stdout is always exactly one JSON '
            . 'object" contract has',
        );
        self::assertMatchesRegularExpression(
            '/exactly one exception.{0,400}`--output-format` value that is not `text` or `json`/su',
            $flat,
            'the remaining exception is no longer identified as an unimplemented --output-format value',
        );
    }
}
