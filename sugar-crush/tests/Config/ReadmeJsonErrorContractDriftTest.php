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
 * WHAT THE FIRST VERSION OF THIS FILE DERIVED, AND WHY THAT WAS NOT ENOUGH.
 * It scanned two producers — {@see NonInteractive::emitErrorDocument()}'s call
 * sites and `bin/sugarcrush`'s hand-rolled guard — and its own doc-block called
 * the result "every `error.type` this project can put on stdout". It was not.
 * {@see \SugarCraft\Crush\Cli\Subcommands} builds contract documents through an
 * `emitDocument()` of its own and emits two more types, `not-found` (`session
 * delete <id>` on an id the store does not hold) and `mcp-config` (`mcp list`
 * on a trusted `.mcp.json` that could not be read or decoded) — both MEASURED
 * from the real binary, not inferred:
 *
 *     $ sugarcrush session delete nope --output-format json
 *     {"result":null,"error":{"type":"not-found","message":"no such session: nope"}}   rc=1
 *     $ sugarcrush mcp list --output-format json      # trusted, malformed .mcp.json
 *     {"result":null,"error":{"type":"mcp-config","message":"… (Syntax error)"}}       rc=1
 *
 * So the guard did the worst thing a guard can do: it pinned an incomplete set
 * as complete, and REJECTED a truthful README. Adding either real type to the
 * union turned this file red with "an extra one invites a branch nothing can
 * ever reach". The window was the defect, not the mutation — the second time
 * that lesson has been learned in this file.
 *
 * SO THE SCAN NOW READS ALL OF `src/` AND `bin/`, not a hand-picked pair of
 * files, and it reads them in every shape a contract document is actually
 * written in this package:
 *
 *   A. `…::emitErrorDocument($fmt, '<type>', …)` — the one-shot path.
 *   B. `'error' => ['type' => '<type>', …]` — an array literal handed to an
 *      encoder, which is how {@see \SugarCraft\Crush\Cli\Subcommands} and the
 *      autoload guard both write theirs.
 *   C. a raw JSON string literal containing `"error":{"type":"<type>"` — the
 *      last-resort documents that exist for the case where the encoder itself
 *      failed, so they cannot go through an encoder.
 *
 * Whole-tree rather than per-file because a census whose file list was written
 * from the cases already known cannot report a case that is somewhere else, and
 * that is exactly how the first version came to be wrong. Cost is 36 ms for 288
 * files / 395k tokens on PHP 8.3.6, measured; a prefilter would buy nothing
 * worth the hole it opens.
 *
 * WHY A TOKEN STREAM AND NOT A REGEX OVER THE SOURCE. Because a regex cannot
 * tell an argument from a comment, and these files are unusually comment-heavy:
 * `bin/sugarcrush`'s guard carries roughly sixty lines of justification that
 * quote the very literals being scanned for, and
 * {@see NonInteractive::emitErrorDocument()}'s doc-block carries its type table
 * verbatim. Scanning the text would let the DOCUMENTATION satisfy an assertion
 * about the CODE — the same substitution this file exists to catch, committed
 * by the catcher.
 *
 * AND EVERY SCAN GOES RED ON WHAT IT CANNOT PARSE, rather than skipping it.
 * A shape-A call whose type argument stops being a single string literal, a
 * shape-B envelope with no literal `type` key, a shape-C literal naming a type
 * no shape-A or shape-B site produces, an envelope built by string
 * interpolation, or a site with no `return …::EXIT_*` and no `exit(<int>)`
 * closing it — each fails with a message saying the contract can no longer be
 * derived. So does a README type name the extractors cannot tokenise. That last
 * one is not hypothetical: the first version's alphabet was `[a-z_]+`, and
 * given a union containing `"not-found"` it returned the other names and said
 * nothing. A guard that quietly ignores the unparseable has a hole shaped
 * exactly like the next change — and in this file the hole was shaped exactly
 * like the two types it was missing.
 *
 * THE RETURN-CODE WINDOW IS BOUNDED ON PURPOSE, and it took a known-answer probe
 * to find out that it had to be. The first version walked forward from a call
 * to the next `return self::EXIT_*` anywhere in the file. Deleting the
 * `backend` branch's own `return self::EXIT_FAILURE` left the test GREEN,
 * because the walk ran on and adopted the `encoding` branch's return three
 * statements later — the right answer read out of the wrong place. The walk now
 * stops at the next producer site of any shape and at the end of the function
 * it started in, and that same mutation reds.
 *
 * @internal
 */
final class ReadmeJsonErrorContractDriftTest extends TestCase
{
    /**
     * A well-formed `error.type` name.
     *
     * Hyphens included because two shipped types carry one. This pattern is the
     * alphabet every extractor in this file shares, so widening it widens the
     * README side and the source side together and they cannot drift apart.
     */
    private const TYPE_NAME = '[a-z][a-z0-9_-]*';

    /**
     * The exit constants a document-emitting branch can return, resolved from
     * the class rather than restated, so a renumbering moves both the
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

    private function repoPath(string $relative): string
    {
        return __DIR__ . '/../../' . $relative;
    }

    /**
     * NOT an assertion, deliberately: this is called once per file per test
     * over 289 shipped sources, and an `assertIsString()` here would put well
     * over a thousand bookkeeping assertions on the suite's count for a
     * condition that is not what any of these tests are about. An unreadable
     * source still fails the test — loudly, and with the path.
     */
    private function repoFile(string $relative): string
    {
        $text = @file_get_contents($this->repoPath($relative));
        if (!is_string($text)) {
            self::fail($relative . ' is unreadable');
        }

        return $text;
    }

    private function readme(): string
    {
        return $this->repoFile('README.md');
    }

    /**
     * Every PHP source file the shipped package executes, relative to the
     * package root.
     *
     * `src/` and `bin/` and nothing else: `tests/` legitimately writes envelope
     * literals as fixtures and expectations, and folding those in would let a
     * test's own expectation register as a shipped type.
     *
     * @return list<string>
     */
    private function shippedSourceFiles(): array
    {
        $files = ['bin/sugarcrush'];
        $root = $this->repoPath('src');
        /** @var iterable<\SplFileInfo> $walk */
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = 'src/' . substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
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
     * Token index of the `}` closing the body of each `function` in $tokens.
     *
     * This is what bounds every exit-code walk. The alternative the first
     * version used — "stop when the enclosing BLOCK closes" — cannot express
     * the autoload guard, whose envelope sits inside `if ($format === 'json')`
     * while the `exit(2)` it belongs to is two lines after that block ends. A
     * function is the smallest scope that always contains both.
     *
     * @param list<PhpToken> $tokens
     *
     * @return list<array{int, int}> `[functionKeywordIndex, closingBraceIndex]`
     */
    private function functionRanges(array $tokens): array
    {
        $n = count($tokens);
        $ranges = [];

        for ($i = 0; $i < $n; $i++) {
            if (!$tokens[$i]->is(T_FUNCTION)) {
                continue;
            }
            // Skip the (optional) name, the parameter list and any return type
            // to reach the body's `{` — or the `;` of an abstract/interface
            // declaration, which has no body to walk.
            $j = $i + 1;
            while ($j < $n && $tokens[$j]->text !== '(') {
                $j++;
            }
            if ($j >= $n) {
                continue;
            }
            [, $close] = $this->argumentTokens($tokens, $j + 1);
            $k = $close + 1;
            while ($k < $n && $tokens[$k]->text !== '{' && $tokens[$k]->text !== ';') {
                $k++;
            }
            if ($k >= $n || $tokens[$k]->text === ';') {
                continue;
            }
            $depth = 0;
            for ($m = $k; $m < $n; $m++) {
                if ($tokens[$m]->text === '{') {
                    $depth++;
                } elseif ($tokens[$m]->text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $ranges[] = [$i, $m];
                        break;
                    }
                }
            }
        }

        return $ranges;
    }

    /**
     * Index of the token that ends the innermost function containing $at, or
     * the end of the stream for code that is not in a function at all.
     *
     * @param list<array{int, int}> $ranges
     */
    private function enclosingFunctionEnd(array $ranges, int $at, int $streamLength): int
    {
        $end = $streamLength;
        $width = PHP_INT_MAX;
        foreach ($ranges as [$start, $close]) {
            if ($at >= $start && $at <= $close && ($close - $start) < $width) {
                $width = $close - $start;
                $end = $close;
            }
        }

        return $end;
    }

    /**
     * Every place in one file that puts an `error.type` on stdout, in shapes A
     * and B, with the token index the site starts at.
     *
     * Shape C is derived separately: those literals name a type but carry no
     * exit code of their own, because the branch that reaches them has already
     * decided one.
     *
     * @param list<PhpToken> $tokens
     *
     * @return list<array{type: string, at: int}>
     */
    private function producerSites(array $tokens, string $where): array
    {
        $n = count($tokens);
        $sites = [];

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];

            // Shape A — ::emitErrorDocument($format, '<type>', …)
            if ($t->is(T_STRING) && $t->text === 'emitErrorDocument' && ($tokens[$i - 1]->text ?? '') === '::') {
                self::assertSame(
                    '(',
                    $tokens[$i + 1]->text ?? '',
                    "{$where}: emitErrorDocument is referenced without being called; the contract "
                    . 'cannot be derived',
                );
                [$args] = $this->argumentTokens($tokens, $i + 2);
                self::assertGreaterThanOrEqual(
                    2,
                    count($args),
                    "{$where}: emitErrorDocument() is called with fewer than two arguments",
                );
                $sites[] = ['type' => $this->literalType($args[1], $where, 'emitErrorDocument()'), 'at' => $i];
                continue;
            }

            // Shape B — 'error' => ['type' => '<type>', …]
            if (!$t->is(T_CONSTANT_ENCAPSED_STRING) || trim($t->text, "'\"") !== 'error') {
                continue;
            }
            if (($tokens[$i + 1]->text ?? '') !== '=>' || ($tokens[$i + 2]->text ?? '') !== '[') {
                continue;
            }
            [$entries] = $this->argumentTokens($tokens, $i + 3);
            $type = null;
            foreach ($entries as $entry) {
                if (count($entry) < 3) {
                    continue;
                }
                if (!$entry[0]->is(T_CONSTANT_ENCAPSED_STRING) || trim($entry[0]->text, "'\"") !== 'type') {
                    continue;
                }
                self::assertSame('=>', $entry[1]->text, "{$where}: malformed `type` entry in an error envelope");
                $type = $this->literalType(array_slice($entry, 2), $where, "the `'error' => […]` envelope");
            }
            self::assertNotNull(
                $type,
                "{$where}: an `'error' => […]` envelope literal has no `'type' => <literal>` entry. "
                . "README.md's JSON contract is derived from these literals, so it can no longer be "
                . 'checked — give this test a way to read the new shape rather than letting it skip.',
            );
            $sites[] = ['type' => $type, 'at' => $i];
        }

        return $sites;
    }

    /**
     * The single string literal $tokens is, or a failure naming what it is
     * instead.
     *
     * @param list<PhpToken> $tokens
     */
    private function literalType(array $tokens, string $where, string $what): string
    {
        self::assertTrue(
            count($tokens) === 1 && $tokens[0]->is(T_CONSTANT_ENCAPSED_STRING),
            "{$where}: {$what} carries a non-literal error type ("
            . implode(' ', array_map(static fn (PhpToken $t): string => $t->text, $tokens))
            . "). README.md's JSON contract is derived from these literals, so it can no longer be "
            . 'checked — give this test a way to read the new shape rather than letting it skip.',
        );

        return trim($tokens[0]->text, "'\"");
    }

    /**
     * The exit code the branch containing the producer at $from leaves with:
     * `return …::EXIT_*` or `exit(<int>)`, whichever comes first.
     *
     * BOUNDED BOTH WAYS. The walk stops at the next producer site of any shape
     * and at the end of the enclosing function, so a branch can never borrow a
     * neighbour's return — see the class doc-block for the mutation that proved
     * an unbounded walk silently did exactly that.
     *
     * @param list<PhpToken> $tokens
     * @param list<int>      $otherSites
     */
    private function exitCodeAfter(array $tokens, int $from, int $until, array $otherSites): ?int
    {
        $codes = $this->exitCodes();

        for ($k = $from + 1; $k <= $until && $k < count($tokens); $k++) {
            if (in_array($k, $otherSites, true)) {
                return null;
            }
            $t = $tokens[$k];
            if ($t->is(T_EXIT)
                && ($tokens[$k + 1]->text ?? '') === '('
                && ($tokens[$k + 2] ?? null)?->is(T_LNUMBER)
                && ($tokens[$k + 3]->text ?? '') === ')') {
                return (int) $tokens[$k + 2]->text;
            }
            if (!$t->is(T_RETURN)) {
                continue;
            }
            if (($tokens[$k + 2]->text ?? '') !== '::' || !str_starts_with($tokens[$k + 3]->text ?? '', 'EXIT_')) {
                continue;
            }
            $constant = $tokens[$k + 3]->text;
            self::assertArrayHasKey($constant, $codes, "unknown exit constant {$constant}");

            return $codes[$constant];
        }

        return null;
    }

    /**
     * Every `error.type` this project can put on stdout, mapped to the exit
     * code the branch emitting it leaves with — derived from all of `src/` and
     * `bin/`, in all three shapes, never written down here.
     *
     * @return array<string, int>
     */
    private function shippedTypes(): array
    {
        $map = [];
        $rawLiterals = [];

        foreach ($this->shippedSourceFiles() as $relative) {
            $tokens = $this->significantTokens($this->repoFile($relative));
            $ranges = $this->functionRanges($tokens);
            $sites = $this->producerSites($tokens, $relative);
            $indexes = array_column($sites, 'at');

            foreach ($sites as $site) {
                $others = array_values(array_filter($indexes, static fn (int $i): bool => $i !== $site['at']));
                $code = $this->exitCodeAfter(
                    $tokens,
                    $site['at'],
                    $this->enclosingFunctionEnd($ranges, $site['at'], count($tokens)),
                    $others,
                );
                self::assertNotNull(
                    $code,
                    "{$relative}: no `return …::EXIT_*` and no `exit(<int>)` closes the block that "
                    . "emits the '{$site['type']}' document; the type -> exit-code mapping README.md "
                    . 'states cannot be derived',
                );
                if (isset($map[$site['type']])) {
                    self::assertSame(
                        $map[$site['type']],
                        $code,
                        "{$relative}: the '{$site['type']}' document is emitted at two different exit "
                        . 'codes, so README.md cannot state one for it',
                    );
                }
                $map[$site['type']] = $code;
            }

            $rawLiterals = [...$rawLiterals, ...$this->rawEnvelopeTypes($tokens, $relative)];
        }

        self::assertNotSame([], $map, 'no error-document producer found at all');

        // Shape C carries no exit code of its own — it is the fallback for a
        // branch that already chose one — so it cannot ADD a type. It can only
        // name one the structured shapes already produce, and if it names
        // anything else that is a producer this scan has not found.
        foreach ($rawLiterals as [$type, $relative]) {
            self::assertArrayHasKey(
                $type,
                $map,
                "{$relative}: a raw JSON envelope literal emits the error type '{$type}', which no "
                . '`emitErrorDocument()` call and no `\'error\' => […]` literal produces. Either a '
                . 'fourth document shape exists or this one is unreachable; the contract cannot be '
                . 'derived until it is one or the other.',
            );
        }

        ksort($map);

        return $map;
    }

    /**
     * Shape C: raw JSON envelope literals, and the file each was found in.
     *
     * A `%s` in the type position is a placeholder the caller fills with a type
     * it was handed, so it names nothing new and is accepted as such; anything
     * else must be a well-formed name. An envelope assembled by INTERPOLATION
     * cannot be read this way at all, so it fails rather than passing unseen.
     *
     * @param list<PhpToken> $tokens
     *
     * @return list<array{string, string}>
     */
    private function rawEnvelopeTypes(array $tokens, string $where): array
    {
        $found = [];

        foreach ($tokens as $token) {
            if (!str_contains($token->text, '"error":{"type":')) {
                continue;
            }
            self::assertTrue(
                $token->is(T_CONSTANT_ENCAPSED_STRING),
                "{$where}: an error envelope is built by string interpolation, so the type it emits "
                . 'cannot be read as a literal and README.md\'s contract cannot be derived from it',
            );
            preg_match_all('/"error":\{"type":"([^"]*)"/u', $token->text, $matches);
            foreach ($matches[1] as $type) {
                if ($type === '%s') {
                    continue;
                }
                self::assertMatchesRegularExpression(
                    '/^' . self::TYPE_NAME . '$/u',
                    $type,
                    "{$where}: a raw JSON envelope literal names '{$type}', which is not a well-formed "
                    . 'error type; README.md\'s contract cannot be derived from it',
                );
                $found[] = [$type, $where];
            }
        }

        return $found;
    }

    /**
     * The autoload guard's own document, read out of `bin/sugarcrush`.
     *
     * @return array{type: string, exit: int, message: string}
     */
    private function autoloadGuardDocument(): array
    {
        $relative = 'bin/sugarcrush';
        $tokens = $this->significantTokens($this->repoFile($relative));
        $ranges = $this->functionRanges($tokens);
        $sites = $this->producerSites($tokens, $relative);

        self::assertCount(
            1,
            $sites,
            'bin/sugarcrush no longer hand-rolls exactly one error document. It is expected to carry '
            . 'the autoload guard\'s and nothing else — every other failure it can reach goes through '
            . 'NonInteractive, which is the single definition of the shape.',
        );
        $site = $sites[0];
        $end = $this->enclosingFunctionEnd($ranges, $site['at'], count($tokens));

        $exit = $this->exitCodeAfter($tokens, $site['at'], $end, []);
        self::assertNotNull($exit, 'no `exit(<int>)` closes bin/sugarcrush\'s autoload guard');

        // BOUNDED TO THE GUARD, not to the file. `$message` is a common enough
        // name that a first-match-wins scan over the whole script would happily
        // read a future one from somewhere else and report it as the guard's;
        // it is currently unambiguous only because nothing else in the file
        // uses the name, which is luck rather than construction.
        $start = $site['at'];
        foreach ($ranges as [$open, $close]) {
            if ($site['at'] >= $open && $site['at'] <= $close && $close === $end) {
                $start = $open;
                break;
            }
        }

        $message = null;
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]->is(T_VARIABLE)
                && $tokens[$i]->text === '$message'
                && ($tokens[$i + 1]->text ?? '') === '='
                && ($tokens[$i + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)) {
                self::assertNull(
                    $message,
                    'bin/sugarcrush\'s autoload guard assigns `$message` a literal more than once, so '
                    . 'which one reaches `error.message` cannot be read off the source',
                );
                $message = trim($tokens[$i + 2]->text, "'\"");
            }
        }
        self::assertNotNull($message, 'no `$message = \'…\';` literal in bin/sugarcrush\'s autoload guard');

        return ['type' => $site['type'], 'exit' => $exit, 'message' => $message];
    }

    /**
     * The `error.type` names in a backticked README list, or a failure naming
     * the element that is not one.
     *
     * THE STRICTNESS IS THE POINT. A `preg_match_all` over the list would
     * return the elements it recognises and say nothing about the rest, which
     * is how the first version of this file came to read a five-name union out
     * of a seven-name one and call the README consistent.
     *
     * @return list<string>
     */
    private function namesInList(string $fragment, string $delimiter, string $where): array
    {
        $names = [];
        foreach (preg_split('/,\s*|\s+and\s+|\s*\|\s*/u', trim($fragment)) ?: [] as $element) {
            $element = trim($element);
            if ($element === '') {
                continue;
            }
            self::assertSame(
                1,
                preg_match(
                    '/^' . preg_quote($delimiter, '/') . '(' . self::TYPE_NAME . ')'
                    . preg_quote($delimiter, '/') . '$/u',
                    $element,
                    $m,
                ),
                "README.md's {$where} contains \"{$element}\", which this test cannot read as an "
                . 'error-type name. It is not being skipped: a name the extractor cannot see is a '
                . 'name the drift check cannot compare, which is the failure mode this file exists '
                . 'to prevent.',
            );
            $names[] = $m[1];
        }
        sort($names);

        return $names;
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

        self::assertSame(
            array_keys($this->shippedTypes()),
            $this->namesInList($m[1], '"', 'JSON schema union'),
            'README.md\'s schema union and the error types src/ and bin/ actually emit disagree. Both '
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
            '/((?:`' . self::TYPE_NAME . '`(?:, | and )?)+) are (?:all|both) `(\d)`/u',
            $m[1],
            $clauses,
            PREG_SET_ORDER,
        );
        self::assertNotSame([], $clauses, 'the enumerating sentence names no type/exit-code clause at all');

        $stated = [];
        foreach ($clauses as $clause) {
            foreach ($this->namesInList($clause[1], '`', 'enumerating sentence') as $name) {
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
            if (preg_match('/`error\.type`: ((?:`' . self::TYPE_NAME . '`(?:, )?)+)/u', $line, $types) !== 1) {
                // A code with no document behind it (`0` is the success path)
                // legitimately names none. A code that DOES have types must
                // say so, which the comparison below enforces.
                continue;
            }
            $found[(int) $row[1]] = $this->namesInList($types[1], '`', 'exit-code table');
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
        $guard = $this->autoloadGuardDocument();
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
     * The types NOT routed through {@see NonInteractive::emitErrorDocument()}
     * must be named as such wherever a count of them is given.
     *
     * There were two documents claiming `installation` was the only one, and
     * both were written when it was. `not-found` and `mcp-config` are two more,
     * so "the one type this method never emits" is now false in the method's
     * own doc-block and was false in README's enumerating sentence.
     */
    public function testTheOutsiderTypesAreNamedWhereverTheyAreCounted(): void
    {
        $shipped = $this->shippedTypes();

        $tokens = $this->significantTokens($this->repoFile('src/Cli/NonInteractive.php'));
        $emitted = array_column($this->producerSites($tokens, 'src/Cli/NonInteractive.php'), 'type');
        $outsiders = array_values(array_diff(array_keys($shipped), $emitted));
        sort($outsiders);

        self::assertNotSame(
            [],
            $outsiders,
            'every shipped type now routes through NonInteractive, so the documents that call some of '
            . 'them out as exceptions describe an empty set and should say so',
        );

        $flatReadme = (string) preg_replace('/\s+/', ' ', $this->readme());
        $docBlock = $this->repoFile('src/Cli/NonInteractive.php');

        foreach ($outsiders as $type) {
            self::assertStringContainsString(
                '`' . $type . '`',
                $flatReadme,
                "README.md never names `{$type}`, which no NonInteractive branch emits — so a reader "
                . 'following the README to find where it comes from has nowhere to go',
            );
            self::assertStringContainsString(
                $type,
                $docBlock,
                "src/Cli/NonInteractive.php's own type table omits '{$type}', which the package emits "
                . 'from elsewhere. That table is the closest thing the source has to a contract index.',
            );
        }

        self::assertStringNotContainsString(
            'the one type this method never emits',
            $docBlock,
            'NonInteractive::emitErrorDocument() still calls `installation` the only type it never '
            . 'emits. There are ' . count($outsiders) . ': ' . implode(', ', $outsiders) . '.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/`installation` is the newest of the five/u',
            $flatReadme,
            'README.md still counts five error types. There are ' . count($shipped) . '.',
        );
    }

    /**
     * The retracted claim may appear only as a quotation of itself.
     *
     * STRUCTURAL, NOT PROXIMITY-BASED, and deliberately so — the same lesson
     * {@see ReadmeSettingsTierClaimTest} records. A "the correction must be
     * within N characters" rule is satisfied by a restored false sentence that
     * happens to sit inside the window and inherit the correction next to it.
     * The rule here is that the retracted wording lives in a blockquote or
     * nowhere: a blockquote cannot be mistaken for the document speaking in its
     * own voice.
     *
     * PARAGRAPH-SCOPED, NOT LINE-SCOPED, and that distinction was a survival.
     * The first version iterated raw lines, so it saw `exactly two` and
     * `exceptions.` as different strings whenever the sentence happened to wrap
     * — and README.md is hard-wrapped at 80 columns, so re-wrapping a paragraph
     * is not an exotic edit, it is what editing this file DOES. Re-adding the
     * retracted sentence in body voice across a line break survived; the same
     * sentence on one line was caught. The scan now splits on blank lines,
     * classifies a paragraph as quoted when every one of its lines begins with
     * `>`, and flattens whitespace before looking.
     */
    public function testTheTwoExceptionsClaimSurvivesOnlyAsAQuotedRetraction(): void
    {
        $offending = [];
        $quoted = false;

        foreach (preg_split('/\n\s*\n/', $this->readme()) ?: [] as $index => $paragraph) {
            $lines = array_values(array_filter(
                explode("\n", $paragraph),
                static fn (string $l): bool => trim($l) !== '',
            ));
            if ($lines === []) {
                continue;
            }
            $isQuote = true;
            foreach ($lines as $line) {
                if (!str_starts_with(ltrim($line), '>')) {
                    $isQuote = false;
                    break;
                }
            }
            $flat = (string) preg_replace('/\s+/', ' ', implode(' ', array_map(
                static fn (string $l): string => ltrim(ltrim($l), '>'),
                $lines,
            )));
            if (stripos($flat, 'exactly two exceptions') === false) {
                continue;
            }
            if ($isQuote) {
                $quoted = true;
                continue;
            }
            $offending[] = 'paragraph ' . ($index + 1) . ': ' . trim($flat);
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
