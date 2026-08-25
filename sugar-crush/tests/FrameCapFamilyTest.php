<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;

/**
 * THE FRAME-CAP FAMILY DERIVES ITS BOUND. THIS FILE PINS THAT IT KEEPS DOING SO.
 *
 * WHAT THIS FILE SAID WHEN IT WAS WRITTEN (round 58), and it was true then:
 * `EngineBackend::MAX_FRAME_BYTES` was `private`, so PHP could not initialise a
 * `const` from it and the three framing classes each spelled
 * `64 * 1024 * 1024` under a doc-block calling the number "inherited rather than
 * invented". The inheritance was PROSE. This file's whole job was to stand in
 * for a derivation nobody could write while that constant stayed private: read
 * it by reflection and refuse any divergence.
 *
 * ⚠️ AND THE REASON IT STAYED PRIVATE WAS NOT THE LANGUAGE. An earlier draft of
 * this paragraph said this file stood in for "a derivation the language would
 * not let anyone write", which misnames the constraint, and the paragraph that
 * recorded the real one was deleted rather than rewritten. It said:
 *
 *     WHY A TEST AND NOT A LANGUAGE-LEVEL DERIVATION. The honest fix would be
 *     for each class to name the engine's constant directly. It cannot: PHP
 *     8.3.6 refuses to read a `private` constant from another class at compile
 *     time, and `ReflectionClass::getConstant()` — which DOES read private
 *     constants, verified on this host — is a runtime call and cannot
 *     initialise a `const`. Promoting the engine's constant to `public` is the
 *     alternative, and it is a change to a file this lane does not own.
 *
 * WHAT IS TRUE NOW: the blocker was the round-58 lane's FILE LIST, not PHP. The
 * constant is `public`, promoting it took one word, and each of the three
 * framers writes `private const MAX_FRAME_BYTES = EngineBackend::MAX_FRAME_BYTES;`.
 * A constant expression naming another class's constant is resolved by PHP, so
 * the three members that exist today CANNOT disagree with the engine. The old
 * headline assertion — that four literals happen to be equal — has become a
 * formality for them.
 *
 * WHY THE DELETED PARAGRAPH STILL EARNS ITS PLACE: both of its measurements are
 * load-bearing and neither is recorded anywhere else. That
 * `getConstant()` reads a PRIVATE constant is why
 * {@see self::testTheConstantReaderIsAliveInBothPolarities()} can assert the
 * visibility rather than merely observing that the read worked (re-measured on
 * PHP 8.3.6: it answers `int(7)` for a private constant); and that a `const`
 * cannot be initialised from a runtime call is why the visibility, and not a
 * static factory, is the thing this family depends on.
 *
 * WHY THIS FILE STILL EARNS ITS PLACE, WHICH IS THE ONLY QUESTION THAT MATTERS
 * AFTER A FIX TURNS A GUARD INTO A TAUTOLOGY. The family can still come apart,
 * and the ways it can are worth naming exactly, because an earlier draft of
 * this paragraph claimed there was only one and shipped that claim into three
 * `src/` doc-blocks:
 *
 *   1. A FOURTH framer that copies one of those doc-blocks along with the
 *      arithmetic. Nothing about promoting the engine's constant prevents
 *      somebody writing `private const MAX_FRAME_BYTES = 64 * 1024 * 1024;` in
 *      a new file tomorrow, and the prose it would copy claims membership of a
 *      family it would not actually be in.
 *   2. A member that derives from the WRONG `::MAX_FRAME_BYTES`.
 *   3. 🔴 A member whose declaration THE ROSTER SCANNER CANNOT READ — which was
 *      not a hypothetical. Until round 59 the walk took the name to be the
 *      first token after `const`, so `private const int MAX_FRAME_BYTES = …`,
 *      the grouped spelling, the nullable and qualified typed spellings, and a
 *      comment between keyword and name were all reported as NO DECLARATION AT
 *      ALL. MEASURED: with one of the three real framers rewritten to the typed
 *      spelling and a copied literal, `vendor/bin/phpunit` came out rc 0 over
 *      10 270 tests. "Exactly one way" was the claim; it was wrong, and it was
 *      wrong in the direction that costs the most.
 *
 * The three assertions are complementary rather than redundant:
 *
 *   - {@see self::testEveryClaimantDerivesTheCapRatherThanSpellingIt()} is what
 *     catches (1). It is a fact about the SOURCE, and reflection cannot see it:
 *     `64 * 1024 * 1024` and `EngineBackend::MAX_FRAME_BYTES` produce an
 *     identical `int` at runtime.
 *   - {@see self::testEveryClaimantAgreesWithTheEngine()} is what catches (2) —
 *     a `::MAX_FRAME_BYTES` belonging to some other class. It is a fact about
 *     the VALUE, and the token scan cannot see it.
 *   - {@see self::testEveryFileDeclaringTheCapReachesTheRoster()} is what
 *     catches (3), and it is the only one of the three that covers a defect in
 *     the INSTRUMENT rather than in `src/`. It requires the parser to agree,
 *     file by file, with a second scanner that decides the same question
 *     without parsing anything — so the next unreadable spelling reds instead
 *     of vanishing.
 *
 * A derivation chain that passes through a second framer is caught anyway: that
 * intermediate is itself a claimant, so if IT spells a literal it is reported in
 * its own right.
 *
 * AND MEMBERSHIP IS DERIVED TOO. The first version of this file listed the
 * family by hand, which left the same defect one level up: a fourth framer that
 * copied the doc-block and the literal joined the family invisibly and was
 * never compared to the engine. {@see self::claimants()} reads the roster off
 * the declarations in `src/`, and
 * {@see self::testTheRosterScannerAgreesWithASourceWhoseAnswerIsKnown()} pushes
 * a known-answer source through the same scanner, because a roster that is
 * derived can go quietly small in a way a hand list cannot.
 *
 * @see \SugarCraft\Crush\Tests\MCP\McpFrameCapTest for what each framer does at
 *      the cap, and for the assertion of the concrete NUMBER; this file only
 *      pins that the family all means the same one, and means it by derivation.
 */
final class FrameCapFamilyTest extends TestCase
{
    /**
     * The name the initialiser of a derived declaration has to end in.
     *
     * Built by concatenation and never spelled whole, so a future textual sweep
     * over this tree for the constant cannot rewrite the string this file
     * matches ON — the fixtures below assemble their sources the same way, for
     * the same reason.
     */
    private const CONSTANT = 'MAX_' . 'FRAME_' . 'BYTES';

    /**
     * Every class in `src/` that declares its own `MAX_FRAME_BYTES`, minus the
     * engine, which is the SOURCE of the number rather than a claimant.
     *
     * WHAT THIS WAS: a hand-written list of three class names.
     *
     * WHAT IS TRUE NOW: this file's whole thesis is that prose claiming
     * derivation is not derivation — and while the VALUES were derived, family
     * MEMBERSHIP was still a written-down list. A fourth framer that copied the
     * doc-block and the literal joined the family invisibly and was never
     * compared to the engine, which is the same defect one level up.
     *
     * The roster is therefore taken from the declaration itself. MEASURED on
     * PHP 8.3.6 before the change, so that it was a refactor and not a
     * behavioural bet: `src/` holds exactly four `MAX_FRAME_BYTES`
     * declarations, and excluding the engine reproduced the previous hand list
     * exactly. That count is NOT asserted anywhere — a cardinality measured
     * over `src/` is invalidated by the next thing merged into it.
     *
     * The scanner is a token walk rather than a regex because a `const` inside
     * a nested class-like has to be attributed to the right class, and it is
     * pushed through a source whose answer is known in
     * {@see self::testTheRosterScannerAgreesWithASourceWhoseAnswerIsKnown()} —
     * an emptiness or near-emptiness that no known-positive fixture backs is
     * indistinguishable from a dead instrument.
     *
     * @return array<class-string, string> class => the text of its initialiser
     */
    private static function claimants(): array
    {
        $root = \dirname(__DIR__) . '/src';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $out = [];
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            foreach (self::declarersIn((string) file_get_contents($f->getPathname())) as $row) {
                if ($row['class'] === EngineBackend::class) {
                    continue;
                }
                $out[$row['class']] = $row['init'];
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * The classes in one source that declare a `MAX_FRAME_BYTES` of their own,
     * each paired with THE TEXT OF ITS INITIALISER.
     *
     * THE INITIALISER IS CARRIED BECAUSE REFLECTION CANNOT SEE IT. A `const`
     * whose value is `64 * 1024 * 1024` and one whose value is
     * `EngineBackend::MAX_FRAME_BYTES` are the same `int` by the time any
     * runtime check can look, so the difference between a family that derives
     * and a family that merely agrees this week is a fact about the SOURCE and
     * has to be read off the token stream.
     *
     * A name this walk cannot assemble is NOT dropped: it comes back as the
     * empty string, and the caller asserts every entry is a real class. A
     * scanner that quietly discards what it cannot parse has a hole shaped
     * exactly like the next member of the family.
     *
     * ⚠️ The `;` that ends a declaration is matched only as a BARE CHARACTER
     * token. A `;` inside a string literal arrives as part of an array token,
     * and treating the two alike is how a walk over `token_get_all()` stops
     * early on source it can otherwise read perfectly well.
     *
     * @return list<array{class:string, init:string}>
     */
    private static function declarersIn(string $src): array
    {
        $tokens = token_get_all($src);
        $n = \count($tokens);
        $namespace = '';
        $class = '';
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $name = '';
                for ($k = $i + 1; $k < $n; $k++) {
                    $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                    if ($txt === ';' || $txt === '{') {
                        break;
                    }
                    $name .= $txt;
                }
                $namespace = trim($name, " \t\n\\");

                continue;
            }

            if (\in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                for ($k = $i + 1; $k < $n; $k++) {
                    if (\is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        continue;
                    }
                    // `Foo::class` and an anonymous class both reach something
                    // that is not a bare name; leave $class as it was rather
                    // than adopting a wrong one.
                    if (\is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
                        $class = $tokens[$k][1];
                    }

                    break;
                }

                continue;
            }

            if ($token[0] !== T_CONST) {
                continue;
            }
            if (self::isImportRatherThanDeclaration($tokens, $i)) {
                continue;
            }
            foreach (self::constDeclarationsAt($tokens, $i, $n) as $declaration) {
                if ($declaration['name'] !== self::CONSTANT) {
                    continue;
                }
                $out[] = [
                    'class' => $class === '' ? '' : ($namespace === '' ? $class : $namespace . '\\' . $class),
                    'init' => $declaration['init'],
                ];
            }
        }

        return $out;
    }

    /**
     * Whether the `const` at `$constIndex` is an IMPORT (`use const A\B;`)
     * rather than a declaration.
     *
     * An import names a constant it does not define, so counting one as a
     * declarer would put a class on the roster that frames against somebody
     * else's number — correct code answered with a red naming the wrong file,
     * which is the shape a guard should never have.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function isImportRatherThanDeclaration(array $tokens, int $constIndex): bool
    {
        for ($k = $constIndex - 1; $k >= 0; $k--) {
            $token = $tokens[$k];
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return \is_array($token) && $token[0] === T_USE;
        }

        return false;
    }

    /**
     * Every constant declared by the one `const` STATEMENT that begins at
     * `$constIndex`, each as a name paired with THE TEXT OF ITS INITIALISER.
     *
     * WHAT THIS REPLACED, AND WHY THE OLD SHAPE WAS NOT ENOUGH. Until round 59
     * this walk read the name as the first non-whitespace token after `const`
     * and then took everything between the following `=` and the terminating
     * `;`. That is one of the five spellings PHP 8.3 accepts, and MEASURED on
     * PHP 8.3.6 by driving the method itself, the other four were reported as
     * NO DECLARATION AT ALL — silently, which is the one outcome
     * {@see self::declarersIn()}'s own doc-block forbids:
     *
     *   - `const int X = …` — a typed class constant, PHP 8.3's own feature.
     *   - `const ?int X = …` and `const \Foo\Bar X = …` — the nullable and
     *     qualified spellings of the same thing.
     *   - `const A = 1, X = …` — a grouped declaration.
     *   - `const /* c *\/ X = …` — a comment between keyword and name.
     *
     * The first of those is not hypothetical style in this tree: `Compactor`
     * and `Agents\AgentManager` already write `private const array …`. A member
     * of this family spelled that way left the roster with the WHOLE SUITE
     * GREEN — measured, rc 0 over 10 270 tests — so the guard this file leads
     * with was reporting on a population it could not see all of.
     *
     * THE RULE THAT COVERS ALL FIVE, and does not have to be extended again for
     * the next one: whatever sits between `const` and the `=` is a type or
     * nothing, so THE NAME IS THE LAST NON-TRIVIA TOKEN BEFORE THE BARE `=`.
     * The initialiser then runs to the bare `,` or `;` AT DEPTH ZERO.
     *
     * ⚠️ DEPTH ZERO IS NOT DECORATION. A grouped declaration separates its
     * members with a bare `,`, and so does `[1, 2]` — MEASURED on PHP 8.3.6,
     * `token_get_all()` emits both as the same bare `,` string token. Splitting
     * on the first one would read `private const array A = [1, 2], X = …` as a
     * constant `A` initialised to `[1` followed by a member named `2`. The
     * reviewer's prescription for this fix said to resume after the terminating
     * bare `,`; that is the hole, and it is why the walk counts brackets.
     *
     * ⚠️ AND EVERY COMPARISON IS GATED ON `is_string()`, SEPARATELY. A `;`, a
     * `,`, a `{` or a `}` inside a string literal arrives as part of an ARRAY
     * token whose TEXT reads that way, and treating the two alike is how a walk
     * over `token_get_all()` stops early on source it can otherwise read
     * perfectly well. (This is the justification the superseded
     * `initialiserAfter()` carried, and it did not stop being true.)
     *
     * ⚠️ COMMENTS ARE DROPPED FROM THE INITIALISER TEXT, and that is a guard
     * and not tidiness. The reader used to append the text of every token,
     * comments included, so
     * `= /* EngineBackend::MAX_FRAME_BYTES *\/ 64 * 1024 * 1024` READ as a
     * derivation and passed {@see
     * self::testEveryClaimantDerivesTheCapRatherThanSpellingIt()} while
     * spelling the arithmetic. An exemption a sentence can buy is no exemption,
     * and in a tree whose house style explains every constant in a comment the
     * author re-literalising the value is the one most likely to write it.
     *
     * A declaration with no `=` before its terminator is NOT dropped: it comes
     * back with an empty initialiser, so the derivation guard reds on a shape
     * this walk could not read rather than passing over it.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     *
     * @return list<array{name:string, init:string}>
     */
    private static function constDeclarationsAt(array $tokens, int $constIndex, int $n): array
    {
        $out = [];
        $k = $constIndex + 1;

        while ($k < $n) {
            // THE NAME: the last non-trivia token before the bare `=`, so a
            // type of any spelling in front of it is skipped without this walk
            // having to enumerate the spellings.
            $name = '';
            $sawEquals = false;

            for (; $k < $n; $k++) {
                $token = $tokens[$k];
                if (\is_string($token)) {
                    if ($token === '=') {
                        $sawEquals = true;
                        $k++;

                        break;
                    }
                    if ($token === ';') {
                        break;
                    }

                    // A nullable type's `?`, which is the only bare token PHP
                    // accepts between `const` and the name.
                    continue;
                }
                if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $name = $token[1];
            }

            if (!$sawEquals) {
                if ($name !== '') {
                    $out[] = ['name' => $name, 'init' => ''];
                }

                break;
            }

            // THE INITIALISER: to the bare `,` or `;` at bracket depth zero.
            $text = '';
            $depth = 0;
            $more = false;

            for (; $k < $n; $k++) {
                $token = $tokens[$k];
                if (\is_string($token)) {
                    if ($depth === 0 && $token === ';') {
                        $k++;

                        break;
                    }
                    if ($depth === 0 && $token === ',') {
                        $more = true;
                        $k++;

                        break;
                    }
                    if ($token === '(' || $token === '[' || $token === '{') {
                        $depth++;
                    } elseif ($token === ')' || $token === ']' || $token === '}') {
                        $depth--;
                    }
                    $text .= $token;

                    continue;
                }
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $text .= $token[1];
            }

            $out[] = ['name' => $name, 'init' => trim((string) preg_replace('/\s+/', '', $text))];

            if (!$more) {
                break;
            }
        }

        return $out;
    }

    /**
     * Whether `$src` declares a constant of this name, DECIDED BY A
     * DELIBERATELY DIFFERENT RULE from the one {@see self::declarersIn()} uses.
     *
     * THIS IS THE COMPLETENESS CROSS-CHECK, AND IT IS THE THING THAT WOULD HAVE
     * CAUGHT ROUND 59'S DEFECT. `declarersIn()` parses; this does not. It asks
     * only whether a token spelling this name appears inside a `const`
     * STATEMENT without a `::` in front of it — a question no type, no
     * grouping, no comment and no future declaration syntax can change the
     * answer to, because it never looks at the shape of the declaration at all.
     *
     * Two instruments that answer the same question by different means can be
     * required to AGREE, per file, with no cardinality asserted anywhere
     * (a count taken over `src/` is void the moment anything merges into it).
     * When the parser meets a spelling it cannot read, the two disagree and
     * {@see self::testEveryFileDeclaringTheCapReachesTheRoster()} names the
     * file. That is the difference between a hole reporting itself and a hole
     * that comes out green.
     *
     * ⚠️ IT IS DELIBERATELY BLIND TO PROSE. A doc-block naming the constant is
     * a `T_COMMENT`, never a `T_STRING`, so the four files in `src/` that
     * discuss the family at length cannot trip it — an exemption keyed on
     * STRUCTURE rather than text, which is the only kind worth having.
     */
    private static function declaresTheConstantLoosely(string $src): bool
    {
        $tokens = token_get_all($src);
        $n = \count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_CONST) {
                continue;
            }
            if (self::isImportRatherThanDeclaration($tokens, $i)) {
                continue;
            }

            $depth = 0;
            $afterDoubleColon = false;

            for ($k = $i + 1; $k < $n; $k++) {
                $inner = $tokens[$k];
                if (\is_string($inner)) {
                    if ($depth === 0 && $inner === ';') {
                        break;
                    }
                    if ($inner === '(' || $inner === '[' || $inner === '{') {
                        $depth++;
                    } elseif ($inner === ')' || $inner === ']' || $inner === '}') {
                        $depth--;
                    }
                    $afterDoubleColon = false;

                    continue;
                }
                if (\in_array($inner[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($inner[0] === T_STRING && $inner[1] === self::CONSTANT && !$afterDoubleColon) {
                    return true;
                }
                $afterDoubleColon = $inner[0] === T_DOUBLE_COLON;
            }
        }

        return false;
    }

    /**
     * THE ROSTER SCANNER, PUSHED THROUGH SOURCES WHOSE ANSWERS ARE KNOWN, IN
     * BOTH POLARITIES AND ON BOTH OF THE THINGS IT READS.
     *
     * `claimants()` DERIVES family membership instead of listing it, which
     * means the roster can go quietly wrong in a way the old hand list could
     * not: a scanner that matches nothing produces a small, plausible, entirely
     * green roster, and the tests below then iterate over it asserting nothing
     * at all. So the scanner answers fixtures here, in the same test file.
     *
     * The last two rows are the important ones. A `const MAX_FRAME_BYTES` that
     * belongs to no class at all comes back as an EMPTY NAME rather than being
     * dropped, so {@see self::testTheDerivedRosterIsRealClasses()} turns it red;
     * and the INITIALISER is read distinctly for the two spellings that the
     * derivation guard has to tell apart, because a scanner that returned the
     * same text for both would make that guard unable to fail.
     */
    public function testTheRosterScannerAgreesWithASourceWhoseAnswerIsKnown(): void
    {
        $const = self::CONSTANT;

        $this->assertSame(
            [['class' => 'Demo\\Framing\\Framer', 'init' => '1']],
            self::declarersIn(
                "<?php\nnamespace Demo\\Framing;\n"
                . "final class Framer { private const " . $const . " = 1; private const OTHER = 2; }\n"
                . "final class NotAFramer { private const SOMETHING_ELSE = 3; }\n",
            ),
            'the roster scanner is not attributing the declaration to the class it is written '
            . 'in, so every name claimants() derives is suspect',
        );

        $this->assertSame(
            [['class' => 'Demo\\Framing\\Second', 'init' => '2']],
            self::declarersIn(
                "<?php\nnamespace Demo\\Framing;\n"
                . "final class First { private const NOPE = 1; }\n"
                . "final class Second { private const " . $const . " = 2; }\n",
            ),
            'the roster scanner attributes a declaration to the FIRST class in the file rather '
            . 'than the one it is written in',
        );

        $this->assertSame(
            [],
            self::declarersIn("<?php\nnamespace Demo;\nfinal class Plain { private const OTHER = 1; }\n"),
            'the roster scanner reports a class that declares no such constant, so claimants() '
            . 'would compare the engine against classes that are not in the family',
        );

        $this->assertSame(
            [['class' => '', 'init' => '1']],
            self::declarersIn("<?php\nnamespace Demo;\nconst " . $const . " = 1;\n"),
            'a declaration this scanner cannot attribute to a class is being DROPPED rather '
            . 'than surfaced as an unusable name, which is how a real family member goes '
            . 'unnoticed',
        );

        // THE TWO SPELLINGS THE DERIVATION GUARD EXISTS TO SEPARATE. If the
        // initialiser read came back identical for both — empty, say, or the
        // constant's own name — that guard could neither pass on the good one
        // nor fail on the bad one, and would be a dead instrument reporting
        // whatever the roster happened to contain.
        $this->assertSame(
            [['class' => 'Demo\\Framer', 'init' => 'EngineBackend::' . $const]],
            self::declarersIn(
                "<?php\nnamespace Demo;\nfinal class Framer { private const " . $const
                . " = EngineBackend::" . $const . "; }\n",
            ),
            'a DERIVED initialiser is not being read off the token stream, so the derivation '
            . 'guard cannot tell a reference from a literal',
        );

        $this->assertSame(
            [['class' => 'Demo\\Framer', 'init' => '64*1024*1024']],
            self::declarersIn(
                "<?php\nnamespace Demo;\nfinal class Framer { private const " . $const
                . " = 64 * 1024 * 1024; }\n",
            ),
            'a LITERAL initialiser is not being read off the token stream, so the derivation '
            . 'guard has nothing to fail on and the family can quietly regain a copied number',
        );

        // AND A `;` INSIDE A STRING LITERAL, which is a token whose TEXT ends a
        // declaration while the token itself does not. Gating the comparison on
        // is_string() is the whole of the fix; without it this walk stops at the
        // semicolon inside the string and reports a truncated initialiser.
        $this->assertSame(
            [['class' => 'Demo\\Framer', 'init' => '"a;b"']],
            self::declarersIn(
                "<?php\nnamespace Demo;\nfinal class Framer { private const " . $const
                . " = \"a;b\"; }\n",
            ),
            'a semicolon inside a string literal is ending the initialiser walk, so any '
            . 'declaration carrying one is read short and judged on a fragment',
        );

        // THE FOUR SPELLINGS PHP 8.3 ACCEPTS THAT THIS WALK USED TO REPORT AS
        // NO DECLARATION AT ALL. Each of these is a real family member hiding
        // from the roster, and the whole suite was green with one of them in
        // `src/` — measured, rc 0 over 10 270 tests. `const int` is not a
        // hypothetical style in this tree either: `Compactor` and
        // `Agents\AgentManager` already write `private const array`.
        $shapes = [
            'a TYPED class constant, which is PHP 8.3\'s own feature and the spelling this '
                . 'tree already uses elsewhere' => 'private const int ' . $const . ' = 64 * 1024 * 1024;',
            'a NULLABLE typed constant' => 'private const ?int ' . $const . ' = 64 * 1024 * 1024;',
            'a constant typed by a QUALIFIED name' => 'private const \\Demo\\Cap ' . $const
                . ' = 64 * 1024 * 1024;',
            'a GROUPED declaration, where the member is not the first name after the keyword'
                => 'private const OTHER = 1, ' . $const . ' = 64 * 1024 * 1024;',
            'a COMMENT between the keyword and the name' => 'private const /* the cap */ '
                . $const . ' = 64 * 1024 * 1024;',
        ];

        foreach ($shapes as $why => $declaration) {
            $this->assertSame(
                [['class' => 'Demo\\Framer', 'init' => '64*1024*1024']],
                self::declarersIn("<?php\nnamespace Demo;\nfinal class Framer { " . $declaration . " }\n"),
                'the roster scanner cannot express ' . $why . ', so a framer written that way '
                . 'leaves the family SILENTLY and every guard in this file stays green while '
                . 'the cap it frames at is a copy',
            );
        }

        // A GROUPED DECLARATION WHOSE EARLIER MEMBER IS AN ARRAY. MEASURED on
        // PHP 8.3.6: `token_get_all()` emits the `,` separating `[1, 2]`'s
        // elements as the same BARE `,` token that separates the members, so a
        // walk that splits on the first one reads a constant named `2`. This is
        // the hole in the prescription that commissioned the fix, and it is
        // pinned rather than argued.
        $this->assertSame(
            [['class' => 'Demo\\Framer', 'init' => '64*1024*1024']],
            self::declarersIn(
                "<?php\nnamespace Demo;\nfinal class Framer { private const array SIZES = [1, 2], "
                . $const . " = 64 * 1024 * 1024; }\n",
            ),
            'a bare `,` INSIDE an array initialiser is ending a member of a grouped declaration, '
            . 'so the walk splits in the wrong place and the member it then reports is not a '
            . 'constant that exists',
        );

        // AND A COMMENT INSIDE THE INITIALISER, which is the exemption a
        // sentence used to buy. The reader appended the text of every token,
        // so `= /* <the engine's constant> *\/ 64 * 1024 * 1024` READ as a
        // derivation and satisfied the guard below while spelling the
        // arithmetic. Both polarities, because a reader that dropped the whole
        // initialiser would pass this row and fail the family.
        $this->assertSame(
            [['class' => 'Demo\\Framer', 'init' => '64*1024*1024']],
            self::declarersIn(
                "<?php\nnamespace Demo;\nfinal class Framer { private const " . $const
                . " = /* EngineBackend::" . $const . " */ 64 * 1024 * 1024; }\n",
            ),
            'a COMMENT naming the engine is being read as part of the initialiser, so the '
            . 'derivation guard can be satisfied by a sentence while the value is a copied '
            . 'literal -- an exemption keyed on prose, in a tree whose house style is to '
            . 'explain every constant in a comment',
        );

        $this->assertSame(
            [['class' => 'Demo\\Framer', 'init' => 'EngineBackend::' . $const]],
            self::declarersIn(
                "<?php\nnamespace Demo;\nfinal class Framer { private const " . $const
                . " = /* the engine's bound */ EngineBackend::" . $const . "; }\n",
            ),
            'dropping comments from the initialiser has taken the real derivation with them, '
            . 'so a correctly derived member now reds',
        );

        // A `,`, A `}` AND A `{` INSIDE A STRING LITERAL, which are tokens
        // whose TEXT would end or nest a declaration while the tokens
        // themselves do neither. Every comparison in the walk is gated on
        // is_string() separately, and this is the row that proves it.
        $this->assertSame(
            [['class' => 'Demo\\Framer', 'init' => '"a}b,c{"']],
            self::declarersIn(
                "<?php\nnamespace Demo;\nfinal class Framer { private const " . $const
                . " = \"a}b,c{\"; }\n",
            ),
            'a brace or comma inside a string literal is being counted as bracket depth or as '
            . 'a member separator, so a declaration carrying one is read short and judged on '
            . 'a fragment',
        );

        // AN IMPORT IS NOT A DECLARATION. `use const A\MAX_FRAME_BYTES;` names
        // a constant it does not define; counting it would put a class on the
        // roster that frames against somebody else's number and red with a
        // message naming the wrong file.
        $this->assertSame(
            [],
            self::declarersIn(
                "<?php\nnamespace Demo;\nuse const Other\\" . $const
                . ";\nfinal class Framer { private const OTHER = 1; }\n",
            ),
            'a `use const` IMPORT is being counted as a declaration, so a file that merely '
            . 'names the constant joins the family',
        );
    }

    /**
     * TWO INSTRUMENTS, DIFFERENT RULES, REQUIRED TO AGREE FILE BY FILE — which
     * is what catches the NEXT spelling the parser cannot read.
     *
     * WHY THIS EXISTS. Round 58 built {@see self::declarersIn()} and round 59
     * promoted it to this file's headline assertion, writing a guarantee about
     * it into three `src/` doc-blocks. Both rounds missed that the walk read
     * the name as the first token after `const`, so four of PHP 8.3's five
     * declaration spellings came back as NO DECLARATION — and a family member
     * written in one of them left the roster with the WHOLE SUITE GREEN.
     * Teaching the parser those four shapes fixes the four; it does nothing
     * about the fifth, whatever it turns out to be.
     *
     * So the parser is not asked to be complete. It is asked to AGREE with
     * {@see self::declaresTheConstantLoosely()}, which decides the same
     * question without parsing anything: is there a token spelling this name
     * inside a `const` statement, with no `::` in front of it. No type, no
     * grouping, no comment and no syntax PHP has not shipped yet can change
     * that answer, because it never looks at the declaration's shape.
     *
     * NO CARDINALITY IS ASSERTED. Not the number of files, not the number of
     * declarations — a count taken over `src/` is void the moment anything
     * merges into it, which is exactly how a figure like that ends up wrong in
     * prose an hour later. The assertion is agreement, per file, plus the
     * requirement that the loose scanner found SOMETHING, because two
     * instruments that both answer "no" agree perfectly and mean nothing.
     */
    public function testEveryFileDeclaringTheCapReachesTheRoster(): void
    {
        $root = \dirname(__DIR__) . '/src';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $looseOnly = [];
        $parsedOnly = [];
        $seen = 0;

        foreach ($it as $f) {
            if (!$f instanceof \SplFileInfo || !$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $text = (string) file_get_contents($f->getPathname());
            $loose = self::declaresTheConstantLoosely($text);
            $parsed = self::declarersIn($text) !== [];
            if ($loose) {
                $seen++;
            }
            if ($loose && !$parsed) {
                $looseOnly[] = substr($f->getPathname(), \strlen(\dirname(__DIR__)) + 1);
            }
            if ($parsed && !$loose) {
                $parsedOnly[] = substr($f->getPathname(), \strlen(\dirname(__DIR__)) + 1);
            }
        }

        sort($looseOnly);
        sort($parsedOnly);

        $this->assertSame(
            [],
            $looseOnly,
            'these files declare the frame cap and the roster parser cannot see the '
            . 'declaration, so they are framing against a number nothing in this file checks. '
            . 'THE FIX IS IN constDeclarationsAt(), NOT HERE: teach it the spelling and pin '
            . 'that spelling as its own fixture in '
            . 'testTheRosterScannerAgreesWithASourceWhoseAnswerIsKnown(). Do NOT exempt the '
            . 'file -- an exemption written for correct code is a licence, and this is exactly '
            . 'where the next member of the family would hide',
        );

        $this->assertSame(
            [],
            $parsedOnly,
            'the roster parser reports a declaration in these files that the loose scanner '
            . 'cannot find at all. One of the two is wrong about the same source, so neither '
            . 'verdict in this file is worth anything until they agree',
        );

        $this->assertGreaterThan(
            0,
            $seen,
            'the loose scanner found no declaration anywhere in src/, so its agreement with '
            . 'the parser is the agreement of two instruments that both answer "no" -- which '
            . 'is what a dead scanner looks like from here',
        );
    }

    /**
     * The loose scanner answers sources whose answers are known, in both
     * polarities.
     *
     * It is the control on the control, and it needs one for the same reason
     * the parser does: a scanner that always answers `false` agrees with the
     * parser on every file that parses and never reports the one that does not,
     * which is precisely the failure it was built to catch. The negatives
     * matter as much — a scanner that always answers `true` would demand a
     * roster row from every file in `src/`.
     */
    public function testTheLooseScannerAgreesWithSourcesWhoseAnswersAreKnown(): void
    {
        $const = self::CONSTANT;

        $positives = [
            'the plain declaration' => 'final class F { private const ' . $const . ' = 1; }',
            'a typed declaration' => 'final class F { private const int ' . $const . ' = 1; }',
            'a grouped declaration' => 'final class F { private const A = 1, ' . $const . ' = 2; }',
            'a declaration whose value derives' => 'final class F { private const ' . $const
                . ' = Engine::' . $const . '; }',
            'a namespace-level declaration' => 'const ' . $const . ' = 1;',
        ];

        foreach ($positives as $why => $body) {
            $this->assertTrue(
                self::declaresTheConstantLoosely("<?php\nnamespace Demo;\n" . $body . "\n"),
                'the loose scanner cannot see ' . $why . ', so it agrees with the parser by '
                . 'being blind in the same place -- and the cross-check it exists to provide '
                . 'is worth nothing',
            );
        }

        $negatives = [
            'a mere READ of somebody else\'s constant' => 'final class F { public function f(): int '
                . '{ return Engine::' . $const . '; } }',
            'an IMPORT of the name' => 'use const Other\\' . $const . ';',
            'a class declaring some other constant' => 'final class F { private const OTHER = 1; }',
            'a DOC-BLOCK discussing the constant at length, which every file in this family does'
                => "/**\n * The cap is " . $const . ", see Engine::" . $const
                . ".\n */\nfinal class F { private const OTHER = 1; }",
        ];

        foreach ($negatives as $why => $body) {
            $this->assertFalse(
                self::declaresTheConstantLoosely("<?php\nnamespace Demo;\n" . $body . "\n"),
                'the loose scanner reports a declaration for ' . $why . ', so it would demand a '
                . 'roster row from a file that declares nothing and red on correct code',
            );
        }
    }

    /**
     * Every name the derivation produces is a class that actually exists, and
     * there is at least one of them.
     *
     * Both halves are load-bearing and neither is a cardinality: an empty
     * roster is what a dead directory walk returns, and a roster holding a name
     * that resolves to nothing is what a mis-assembled namespace returns. The
     * COUNT is deliberately not asserted — a number measured over `src/` is
     * invalidated by the next thing merged into it.
     */
    public function testTheDerivedRosterIsRealClasses(): void
    {
        $claimants = self::claimants();

        $this->assertNotSame(
            [],
            $claimants,
            'the family derivation found nothing at all, so the tests below iterate over an '
            . 'empty list and assert nothing. That is a broken walk over src/, not a family '
            . 'that has disbanded',
        );

        foreach (array_keys($claimants) as $class) {
            // ALL FOUR KINDS declarersIn() TRACKS, not just the one word in its
            // name. MEASURED on PHP 8.3.6: class_exists() answers false for an
            // interface and for a trait (true for an enum), so a family member
            // legitimately declared in either would red HERE, with a message
            // blaming the scanner for source it read perfectly well. An
            // exemption written for correct code is a licence; the classifier
            // was the defect.
            $this->assertTrue(
                class_exists($class) || interface_exists($class)
                    || trait_exists($class) || enum_exists($class),
                'the family derivation produced "' . $class . '", which names no class, '
                . 'interface, trait or enum. Either the namespace/class assembly in '
                . 'declarersIn() is wrong, or a MAX_FRAME_BYTES was declared somewhere this '
                . 'scanner cannot attribute it to a class-like',
            );
        }

        $this->assertArrayNotHasKey(
            EngineBackend::class,
            $claimants,
            'the engine is the SOURCE of the number, not a claimant of it. With it in the roster '
            . 'testEveryClaimantAgreesWithTheEngine() compares the engine to itself and one row '
            . 'of it passes unconditionally',
        );
    }

    /**
     * The reader is checked before it is believed.
     *
     * `getConstant()` answers `false` for a name that does not exist, and
     * `false === false` is exactly what a comparison between two DEAD reads
     * looks like. So the source is required to be a positive int, and the
     * reader is required to still be able to say "no".
     */
    public function testTheConstantReaderIsAliveInBothPolarities(): void
    {
        $engine = new \ReflectionClass(EngineBackend::class);

        $source = $engine->getConstant(self::CONSTANT);
        $this->assertIsInt($source, 'the engine no longer declares MAX_FRAME_BYTES as an int');
        $this->assertGreaterThan(0, $source);

        $this->assertFalse(
            $engine->getConstant(self::CONSTANT . '_NO_SUCH_CONSTANT'),
            'getConstant() answered something other than false for a constant that does not '
            . 'exist, so a false it returns elsewhere can no longer be read as "absent" and '
            . 'the comparisons below could be comparing two failures to each other',
        );

        // WHAT THIS ASSERTED BEFORE, and it was the right assertion at the
        // time: that the engine's constant was still PRIVATE, with a failure
        // message telling whoever changed that to replace the literals with the
        // constant itself. That is precisely what happened, so the assertion
        // inverted rather than disappeared — and it has to stay in SOME
        // polarity, because the visibility is now load-bearing in the other
        // direction: the moment it narrows again, the three initialisers in
        // src/ stop compiling and the whole family has to go back to copied
        // literals. This is the assertion that names that consequence.
        $this->assertTrue(
            $engine->getReflectionConstant(self::CONSTANT)->isPublic(),
            'EngineBackend::MAX_FRAME_BYTES is no longer public. Three classes initialise '
            . 'their own cap from it BY NAME, and PHP cannot read a private constant from '
            . 'another class -- so narrowing this breaks LspConnection, StdioMcpServer and '
            . 'ClaudeCodeMcpClient. MEASURED on PHP 8.3.6, it breaks them LATE: a constant '
            . 'expression naming another class\'s constant is evaluated lazily on first '
            . 'access, so all three classes still LOAD, and what throws is the read -- '
            . '"Error: Cannot access private constant" -- raised inside whichever framing '
            . 'path happened to check its bound first. That is why this is asserted here '
            . 'rather than left to the type system. If the visibility must narrow, every '
            . 'claimant has to go back to spelling the arithmetic, and '
            . 'testEveryClaimantDerivesTheCapRatherThanSpellingIt() below is the test that '
            . 'then has to change with it',
        );

        $this->assertNotSame([], self::claimants());
    }

    /**
     * THE HEADLINE, AND THE ONE WAY THE FAMILY CAN STILL COME APART: every
     * member NAMES a `::MAX_FRAME_BYTES` rather than spelling the arithmetic.
     *
     * Reflection is blind to this. `64 * 1024 * 1024` and
     * `EngineBackend::MAX_FRAME_BYTES` are indistinguishable once PHP has
     * evaluated them, so
     * {@see self::testEveryClaimantAgreesWithTheEngine()} is GREEN on a fourth
     * framer that copies the number today — and stays green right up until
     * somebody moves the engine's cap, at which point that framer silently
     * frames at the old bound while its doc-block claims it inherits.
     *
     * WHY A `::` REFERENCE IS ENOUGH, rather than requiring the engine BY NAME.
     * A member could legitimately derive through another framer, and that
     * intermediate is itself a claimant on this roster — so if IT spells a
     * literal, it is reported in its own right, and if it does not, the chain
     * ends at the engine. The VALUE check in the sibling test closes the
     * remaining case, a reference to some unrelated class's constant that
     * happens to be spelled `MAX_FRAME_BYTES`.
     */
    public function testEveryClaimantDerivesTheCapRatherThanSpellingIt(): void
    {
        $claimants = self::claimants();
        $this->assertNotSame([], $claimants, 'the roster walk is dead; see testTheDerivedRosterIsRealClasses()');

        foreach ($claimants as $class => $init) {
            // NO `\\s*` AFTER THE `::`. constDeclarationsAt() returns the
            // initialiser with every whitespace byte already stripped, so a
            // tolerance for spacing here would be matching against something
            // that cannot occur -- a metacharacter that reads as leniency the
            // guard does not actually have.
            $this->assertMatchesRegularExpression(
                '/::' . self::CONSTANT . '\b/',
                $init,
                $class . '::MAX_FRAME_BYTES is written as its own value (`' . $init . '`) rather '
                . 'than derived from another class\'s constant. THE FIX IS TO NAME THE ENGINE, '
                . 'not to relax this: write `= EngineBackend::MAX_FRAME_BYTES` and import '
                . '\\SugarCraft\\Crush\\Backend\\EngineBackend. A copied number agrees with the '
                . 'engine on the day it is copied and disagrees the day the engine moves, and '
                . 'no runtime assertion anywhere can tell the two spellings apart -- which is '
                . 'the entire reason this test reads the source. If this class genuinely is not '
                . 'a member of the family, stop it declaring a constant of this NAME, because '
                . 'that declaration is what claimants() reads membership from, and delete the '
                . 'inheritance claim from its doc-block in the same commit.',
            );
        }
    }

    /**
     * The other half: every member's value equals the engine's, and every
     * member spells the constant ITSELF rather than inheriting one from a
     * parent — an inherited constant would compare equal while the class it is
     * written in is not a member of this family at all.
     *
     * WHAT THIS WAS: the headline of this file, back when it stood in for a
     * derivation PHP would not let anyone write.
     *
     * WHAT IS TRUE NOW: for the three members that exist today it is a
     * formality, because their initialisers name the engine and PHP resolves
     * that itself. Saying so plainly matters — a guard that has quietly become
     * a tautology is worth less than nothing, since it reads as coverage.
     *
     * WHY IT STILL EARNS ITS PLACE: it is not a tautology for every source the
     * roster can produce. A member that derives from the WRONG
     * `::MAX_FRAME_BYTES` satisfies the derivation guard above and fails here,
     * and a member that spells the arithmetic satisfies THIS one on the day it
     * is written and fails above. The pair covers what neither does alone.
     */
    public function testEveryClaimantAgreesWithTheEngine(): void
    {
        $source = (new \ReflectionClass(EngineBackend::class))->getConstant(self::CONSTANT);

        foreach (array_keys(self::claimants()) as $class) {
            $ref = new \ReflectionClass($class);
            $constant = $ref->getReflectionConstant(self::CONSTANT);

            $this->assertNotFalse(
                $constant,
                $class . ' was derived from a MAX_FRAME_BYTES declaration in src/, but '
                . 'reflection cannot find that constant on the class. The token scanner in '
                . 'declarersIn() and the running PHP disagree about this file, so the roster is '
                . 'not to be trusted -- fix the scanner, do not trim the roster.',
            );

            $this->assertSame(
                $class,
                $constant->getDeclaringClass()->getName(),
                $class . ' inherits MAX_FRAME_BYTES rather than declaring it, so this row is '
                . 'measuring a parent and would keep agreeing with the engine while ' . $class
                . ' itself framed at some other bound. Reaching this from a DERIVED roster also '
                . 'means declarersIn() attributed a declaration to the wrong class',
            );

            $this->assertSame(
                $source,
                $constant->getValue(),
                $class . '::MAX_FRAME_BYTES and EngineBackend::MAX_FRAME_BYTES have diverged. '
                . 'Since every claimant is required to DERIVE its cap, reaching this means the '
                . 'derivation names some OTHER class\'s MAX_FRAME_BYTES -- a reference that '
                . 'satisfies the token guard and still frames at the wrong bound. THE FIX IS TO '
                . 'NAME THE ENGINE: a framer that accepts a payload the engine will refuse '
                . 'turns a clean over-size rejection into a truncated frame one process further '
                . 'down.',
            );
        }
    }
}
