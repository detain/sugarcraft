<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspClient;

/**
 * TWO METHODS WITH THE SAME BODY MADE A MUTATION VERDICT UNTRUSTWORTHY, WHICH IS
 * A SHARPER COST THAN "DUPLICATION IS UNTIDY".
 *
 * {@see LspClient} carried five pairs — `definitions`/`definitionsFor`,
 * `references`/`referencesFor`, `hover`/`hoverFor`, `symbols`/`symbolsFor`,
 * `codeActions`/`codeActionsFor` — whose tails were character-identical and sat
 * twenty-odd lines apart. MEASURED cost, recorded when it happened: a mutation
 * targeting `referencesFor()`'s `isConnected()` branch, anchored on that tail,
 * silently landed in `references()` instead and SURVIVED. That read as a hole in
 * a sound test. Re-anchored on `referencesFor()`'s own offset, the same two
 * mutations both KILLED. A reviewer who stopped at the first verdict would have
 * recorded a good guard as vacuous.
 *
 * The pairs are now one body each: `x()` forwards to `xFor($this->language, …)`.
 * This file is what stops them growing back.
 *
 * ⚠️ IT COMPARES TAILS, NOT WHOLE BODIES, AND THE FIRST DRAFT OF THIS FILE DID
 * NOT — WHICH MADE IT WORTH NOTHING. That draft flagged two methods whose whole
 * normalised bodies matched. Run against the PRE-FIX `LspClient` it reported
 * ZERO, because the pairs never were identical from the first token: `x()` began
 * `$conn = $this->connections[$this->language];` and `xFor()` began with a null
 * guard. Only the TAILS coincided. An assertion of absence that was already true
 * before the fix is not evidence of the fix; it is rule 25's fixture-expecting-0
 * in a new place, and it was caught by running the scanner against the old file
 * rather than by reading it.
 *
 * SO THE MEASURE IS THE LENGTH OF THE COMMON TRAILING TOKEN RUN, and the
 * threshold has a generator. Over every method pair in `LspClient`, normalised
 * token streams, PHP 8.3.6:
 *
 *     pre-fix   the five real pairs      74, 75, 84, 89, 89 tokens
 *               highest non-pair         14   (definitionsFor / referencesFor)
 *     post-fix  highest pair of any kind 14   (the same two)
 *
 * {@see MAX_SHARED_TAIL_TOKENS} sits in the middle of that 60-token gap. The 14
 * is not noise to be tuned away: `definitionsFor()` and `referencesFor()` are
 * genuinely parallel general implementations that differ in the LSP method name
 * and the fallback tag, and collapsing THOSE would need a parameterised inner
 * helper — a different change with a different argument.
 *
 * ⚠️ THE CENSUS IS STRUCTURAL, NOT TEXTUAL. It works on a normalised token
 * stream — comments and whitespace dropped — so re-indenting a copy or giving it
 * a different comment buys no exemption, and this file's own prose about the
 * pattern cannot be mistaken for the pattern.
 *
 * ⚠️ AND IT HAS A KNOWN-POSITIVE, BECAUSE "no duplicates" IS AN ASSERTION OF
 * ABSENCE. Every row below that expects nothing first runs a synthetic source
 * built to the ACTUAL pre-fix shape — different heads, identical tails — through
 * the SAME extractor and requires it to be reported.
 */
final class LspClientBodyDuplicationTest extends TestCase
{
    /**
     * The longest trailing run of identical tokens two method bodies may share
     * before the pair is reported. See this file's doc-block for the measured
     * separation this number sits in the middle of — it is a THRESHOLD WITH A
     * GENERATOR, not a value tuned until the suite went green.
     */
    private const MAX_SHARED_TAIL_TOKENS = 40;

    /**
     * The token kinds that OPEN a brace besides the bare `{` byte.
     *
     * `T_CURLY_OPEN` is `"{$x}"`; `T_DOLLAR_OPEN_CURLY_BRACES` is `"${x}"`,
     * deprecated since PHP 8.2 and still tokenized on this host (8.3.6). Both
     * close with a bare `}`, which is why a scanner counting only the bare byte
     * loses a level — see the note at the depth counter.
     *
     * @var list<int>
     */
    private const BRACE_OPENERS = [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES];

    public function testNoTwoMethodsInLspClientShareABody(): void
    {
        // ---- Known-positive first: everything after this is an assertion of
        // absence, which a dead extractor satisfies perfectly.
        // The fixture is built to the PRE-FIX SHAPE and not to a simpler one:
        // `alpha` and `beta` have DIFFERENT first statements and an identical
        // tail, which is exactly what defeated this file's first draft. `gamma`
        // shares the tail's last few tokens only, so it must NOT be reported —
        // that is the half that proves the threshold is doing work rather than
        // the extractor answering "everything".
        $fixture = <<<'PHP'
            <?php
            class F {
                public function alpha(string $u, int $l): array {
                    $a = $this->x[$this->lang];
                    $k = self::key('m', $l);
                    if ($this->c->has($u, $k)) { return $this->c->get($u, $k) ?? []; }
                    if ($a->isConnected()) { $r = $a->go($u, $l); $this->c->set($u, $k, $r); return $r; }
                    $r = $this->grep($u, $l, 'm');
                    $this->c->set($u, $k, $r);
                    return $r;
                }
                /** a different comment, different indentation, same tail */
                public function beta(string $lang, string $u, int $l): array
                {
                        $a = $this->x[$lang] ?? null;
                        if ($a === null) { throw new \InvalidArgumentException($lang); }
                        $k = self::key('m', $l);
                        if ($this->c->has($u, $k)) { return $this->c->get($u, $k) ?? []; }
                        if ($a->isConnected()) { $r = $a->go($u, $l); $this->c->set($u, $k, $r); return $r; }
                        $r = $this->grep($u, $l, 'm');
                        $this->c->set($u, $k, $r);
                        return $r;
                }
                public function gamma(string $u, int $l): array {
                    $r = $this->other($u, $l);
                    $this->c->set($u, 'z', $r);
                    return $r;
                }
            }
            PHP;

        $this->assertSame(
            [['alpha', 'beta']],
            self::duplicateBodyPairsIn($fixture),
            'the extractor cannot find the ONE long shared tail in a source built to contain '
            . 'exactly one — so "LspClient has none" below would be the answer a DEAD scanner '
            . 'gives, not evidence. If `gamma` is in this list instead, the threshold is too '
            . 'low and the census will red on honestly parallel code.',
        );

        // ---- The real census.
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(LspClient::class))->getFileName(),
        );

        $this->assertNotSame(
            [],
            self::methodBodiesIn($source),
            'no methods were extracted from LspClient at all',
        );

        $this->assertSame(
            [],
            self::duplicateBodyPairsIn($source),
            'two methods of LspClient share a long trailing run of tokens again. A mutation '
            . 'anchored on that run cannot be attributed to either of them — which is the '
            . 'measured defect this file exists for, not a style complaint. Collapse one onto '
            . 'the other: the `…For($language, …)` member is the general one, and `x()` '
            . 'forwards to it with $this->language. Do NOT resolve this by raising '
            . 'MAX_SHARED_TAIL_TOKENS — the doc-block records the 60-token gap it sits in, '
            . 'and a number moved to fit an offender is not a threshold.',
        );
    }

    /**
     * THE SCANNER'S OWN BRACE COUNTING, PINNED, BECAUSE ITS FIRST DRAFT GOT IT
     * WRONG AND EVERY OTHER ROW IN THIS FILE DEPENDS ON IT.
     *
     * PHP opens a brace with three tokens and closes it with one. A scanner
     * counting depth on the bare `{` byte alone loses a level at
     * `T_DOLLAR_OPEN_CURLY_BRACES` (text `${`) and ends the body at the first
     * interpolated string — MEASURED on PHP 8.3.6 against this file's first
     * draft, which returned `'$x = 1; $s = "${x'` for a method whose body
     * continued for two more statements.
     *
     * ⚠️ THE FIXTURES ARE BUILT BY CONCATENATION AND THE OPENERS ARE NEVER
     * SPELLED IN THIS FILE'S PROSE, deliberately: a sweep over the pattern would
     * otherwise eat the row that documents it, which has happened twice in this
     * tree. The token CONSTANTS are named (a sibling guard requires it and is
     * right to); the string forms are assembled below.
     *
     * ⚠️ AND EACH ARM CARRIES A TAIL MARKER, so "the body was read" is checked
     * by finding the LAST statement rather than by the body being non-empty. An
     * empty-versus-truncated distinction is exactly what the first draft failed.
     */
    public function testTheScannerCountsEveryBraceOpenerPhpUses(): void
    {
        $dollar = '$';
        $arms = [
            'bare'          => '1',
            'curly_open'    => '"{' . $dollar . 'x}"',
            'dollar_open'   => '"' . $dollar . '{x}"',
            'nested_call'   => '"{' . $dollar . 'this->x()}"',
        ];

        foreach ($arms as $name => $expression) {
            $php = '<?php class F { public function m(): string { $x = 1; $s = ' . $expression . '; '
                . 'return $s . "TAIL_' . strtoupper($name) . '"; } }';

            $bodies = self::methodBodiesIn($php);

            $this->assertArrayHasKey('m', $bodies, "the {$name} arm produced no method at all");
            $this->assertStringContainsString(
                'TAIL_' . strtoupper($name),
                $bodies['m'],
                "the body was truncated before its last statement on the {$name} arm. A brace "
                . 'opener the depth counter does not recognise is closed by a bare `}` it does, '
                . 'so the walk loses a level and the body ends early — which makes two methods '
                . 'differing only AFTER an interpolated string compare equal, and hides a real '
                . 'pair. See BRACE_OPENERS.',
            );
        }
    }

    /**
     * AND IT REFUSES WHAT IT CANNOT PARSE rather than reporting a clean census
     * over half a file (rule 14). Without this row, the guard above is satisfied
     * by a scanner that silently drops anything awkward.
     */
    public function testTheScannerRefusesASourceItCannotParse(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not close/');

        self::methodBodiesIn('<?php class F { public function m(): void { $x = 1;');
    }

    /**
     * The FORWARD is the shape, in both directions. Without this row, deleting
     * `definitions()` outright would satisfy the census above.
     *
     * @return void
     */
    public function testEveryUnsuffixedAccessorForwardsToItsForTwin(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(LspClient::class))->getFileName(),
        );
        $bodies = self::methodBodiesIn($source);

        foreach (['definitions', 'references', 'hover', 'symbols', 'codeActions'] as $name) {
            $this->assertArrayHasKey($name, $bodies, "LspClient::{$name}() is gone");
            $this->assertArrayHasKey($name . 'For', $bodies, "LspClient::{$name}For() is gone");

            $body = $bodies[$name];
            $this->assertStringStartsWith(
                'return $this->' . $name . 'For($this->language,',
                $body,
                "LspClient::{$name}() is no longer a forward to its `…For()` twin. If it has "
                . 'grown its own body, the two will drift and a mutation of either becomes '
                . 'unattributable — see this file\'s doc-block for the measurement.',
            );
            $this->assertSame(
                1,
                substr_count($body, ';'),
                "LspClient::{$name}() does more than forward",
            );
        }
    }

    // =========================================================================
    // The scanner
    // =========================================================================

    /**
     * Every method in `$php`, mapped to its body as a normalised token stream —
     * comments and whitespace collapsed, so indentation and commentary cannot
     * make two identical bodies look different or vice versa.
     *
     * ⚠️ IT REFUSES WHAT IT CANNOT PARSE rather than dropping it: a method whose
     * braces do not balance throws, because a scanner that quietly skips the
     * unparseable has a hole shaped exactly like the next defect.
     *
     * @return array<string, string>
     */
    private static function methodBodiesIn(string $php): array
    {
        $tokens = token_get_all($php);
        $count = count($tokens);
        $bodies = [];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue;   // a closure or an arrow fn — it has no name to key on
            }
            $name = $tokens[$j][1];

            $k = $j;
            while ($k < $count && $tokens[$k] !== '{' && $tokens[$k] !== ';') {
                $k++;
            }
            if ($k >= $count || $tokens[$k] === ';') {
                continue;   // an abstract or interface declaration — no body
            }

            $depth = 0;
            $body = '';
            $closed = false;
            for (; $k < $count; $k++) {
                $token = $tokens[$k];
                $text = is_array($token) ? $token[1] : $token;

                // ⚠️ THREE OPENERS, ONE CLOSER, AND COUNTING TEXT ALONE WAS A
                // MEASURED DEFECT IN THIS FILE'S FIRST DRAFT. PHP opens a brace
                // with a bare `{`, with `T_CURLY_OPEN` (whose text IS `{`, so it
                // happened to work) and with `T_DOLLAR_OPEN_CURLY_BRACES` — whose
                // text is `${`, which `$text === '{'` does not match. All three
                // close with a BARE `}`. MEASURED on PHP 8.3.6 against the old
                // predicate:
                //
                //     public function a(): string { $x = 1; $s = "${x}";
                //         return $s . 'TAIL_MARKER'; }
                //     ->  body '$x = 1; $s = "${x'        (TRUNCATED)
                //
                // The `}` closing the interpolation decremented a depth the `${`
                // had never incremented, so the body ended at the first
                // interpolated string. Two methods differing only AFTER such a
                // string would then compare equal, and a real pair could be
                // missed. Caught by `Tests\Support\InterpolationOpenerTokenTest`,
                // which exists because this is the third scanner to get it wrong.
                $opens = $text === '{'
                    || (is_array($token) && \in_array($token[0], self::BRACE_OPENERS, true));

                if ($opens) {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                }
                if ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $closed = true;
                        break;
                    }
                }
                if (is_array($tokens[$k])
                    && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $body .= ' ';

                    continue;
                }
                $body .= $text;
            }

            if (!$closed) {
                throw new \RuntimeException(
                    "the body of {$name}() does not close — this scanner refuses a source it "
                    . 'cannot parse rather than reporting a clean census over half a file',
                );
            }

            $bodies[$name] = trim((string) preg_replace('/ +/', ' ', $body));
        }

        return $bodies;
    }

    /**
     * Pairs of method names in `$php` sharing more than
     * {@see MAX_SHARED_TAIL_TOKENS} identical trailing tokens, each pair sorted,
     * the list sorted.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function duplicateBodyPairsIn(string $php): array
    {
        $streams = [];
        foreach (self::methodBodiesIn($php) as $name => $body) {
            $streams[$name] = self::tokenListOf($body);
        }

        $names = array_keys($streams);
        sort($names);
        $pairs = [];

        foreach ($names as $a) {
            foreach ($names as $b) {
                if (strcmp($a, $b) >= 0) {
                    continue;
                }
                if (self::commonTailLength($streams[$a], $streams[$b]) > self::MAX_SHARED_TAIL_TOKENS) {
                    $pairs[] = [$a, $b];
                }
            }
        }
        sort($pairs);

        return $pairs;
    }

    /**
     * A normalised body string as a list of tokens, splitting on whitespace and
     * on the punctuation PHP uses to separate statements, so that a differently
     * spaced copy produces the same list.
     *
     * @return list<string>
     */
    private static function tokenListOf(string $body): array
    {
        $parts = preg_split('/(?<=[;{}()\[\],])|(?=[;{}()\[\],])|\s+/', $body);

        return array_values(array_filter(
            array_map(static fn (string $p): string => trim($p), $parts ?: []),
            static fn (string $p): bool => $p !== '',
        ));
    }

    /**
     * How many tokens two lists share at their END.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function commonTailLength(array $a, array $b): int
    {
        $i = count($a) - 1;
        $j = count($b) - 1;
        $shared = 0;

        while ($i >= 0 && $j >= 0 && $a[$i] === $b[$j]) {
            $shared++;
            $i--;
            $j--;
        }

        return $shared;
    }
}
