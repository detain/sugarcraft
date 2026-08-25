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
 * for a derivation the language would not let anyone write: read the engine's
 * private constant by reflection and refuse any divergence.
 *
 * WHAT IS TRUE NOW: the engine's constant is `public`, and each of the three
 * framers writes `private const MAX_FRAME_BYTES = EngineBackend::MAX_FRAME_BYTES;`.
 * A constant expression naming another class's constant is resolved by PHP, so
 * the three members that exist today CANNOT disagree with the engine. The
 * old headline assertion — that four literals happen to be equal — has become a
 * formality for them.
 *
 * WHY THIS FILE STILL EARNS ITS PLACE, WHICH IS THE ONLY QUESTION THAT MATTERS
 * AFTER A FIX TURNS A GUARD INTO A TAUTOLOGY. The family can still come apart,
 * in exactly one way: a FOURTH framer that copies one of those doc-blocks along
 * with the arithmetic. Nothing about promoting the engine's constant prevents
 * somebody writing `private const MAX_FRAME_BYTES = 64 * 1024 * 1024;` in a new
 * file tomorrow, and the prose it would copy claims membership of a family it
 * would not actually be in. So the assertion this file leads with changed from
 * "the values agree" to "every member DERIVES", and the two are complementary
 * rather than redundant:
 *
 *   - {@see self::testEveryClaimantDerivesTheCapRatherThanSpellingIt()} is what
 *     catches the new literal. It is a fact about the SOURCE, and reflection
 *     cannot see it: `64 * 1024 * 1024` and `EngineBackend::MAX_FRAME_BYTES`
 *     produce an identical `int` at runtime.
 *   - {@see self::testEveryClaimantAgreesWithTheEngine()} is what catches a
 *     member deriving from the WRONG constant — a `::MAX_FRAME_BYTES` belonging
 *     to some other class. It is a fact about the VALUE, and the token scan
 *     cannot see it.
 *
 * Neither subsumes the other, and a derivation chain that passes through a
 * second framer is caught anyway: that intermediate is itself a claimant, so if
 * IT spells a literal it is reported in its own right.
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
            for ($k = $i + 1; $k < $n; $k++) {
                if (\is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    continue;
                }
                if (\is_array($tokens[$k]) && $tokens[$k][0] === T_STRING
                    && $tokens[$k][1] === self::CONSTANT) {
                    $out[] = [
                        'class' => $class === '' ? '' : ($namespace === '' ? $class : $namespace . '\\' . $class),
                        'init' => self::initialiserAfter($tokens, $k, $n),
                    ];
                }

                break;
            }
        }

        return $out;
    }

    /**
     * The text between the `=` that follows a constant's name and the bare `;`
     * that ends the declaration, whitespace collapsed.
     *
     * Returns the empty string when there is no `=` before the terminator,
     * which is not a shape PHP accepts for a `const` — surfacing it as empty
     * rather than as something plausible keeps
     * {@see self::testEveryClaimantDerivesTheCapRatherThanSpellingIt()} red on
     * a declaration this walk could not read, per the same rule the empty class
     * name follows.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function initialiserAfter(array $tokens, int $nameIndex, int $n): string
    {
        $text = '';
        $started = false;

        for ($k = $nameIndex + 1; $k < $n; $k++) {
            $token = $tokens[$k];
            // A `;` or `=` that IS punctuation, as opposed to an array token
            // whose text merely reads that way inside a string literal.
            if (\is_string($token) && $token === ';') {
                break;
            }
            if (!$started) {
                if (\is_string($token) && $token === '=') {
                    $started = true;
                }

                continue;
            }
            $text .= \is_array($token) ? $token[1] : $token;
        }

        return $started ? trim((string) preg_replace('/\s+/', '', $text)) : '';
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
            $this->assertTrue(
                class_exists($class),
                'the family derivation produced "' . $class . '", which is not a class. Either the '
                . 'namespace/class assembly in declarersIn() is wrong, or a MAX_FRAME_BYTES was '
                . 'declared somewhere this scanner cannot attribute it to a class',
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
            $this->assertMatchesRegularExpression(
                '/::\s*' . self::CONSTANT . '\b/',
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
