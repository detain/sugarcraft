<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;

/**
 * "INHERITED RATHER THAN INVENTED" WAS PROSE, AND PROSE IS NOT DERIVATION.
 *
 * The framing classes carry a `MAX_FRAME_BYTES` doc-block saying the number is
 * taken from {@see \SugarCraft\Crush\Backend\EngineBackend}. Each then
 * spells `64 * 1024 * 1024` as its own literal, because
 * `EngineBackend::MAX_FRAME_BYTES` is `private` and PHP cannot write
 * `const X = EngineBackend::MAX_FRAME_BYTES;` against a private constant. So
 * the claim of membership had nothing behind it: raise the engine's cap and the
 * whole family silently stops agreeing with the thing its comments cite, while
 * every existing test stays green.
 *
 * MEASURED before this file existed: `McpFrameCapTest` pins that the two NDJSON
 * framers agree with EACH OTHER and that the value is `64 * 1024 * 1024` — its
 * own fourth literal — and `LspConnectionFrameCapTest` pins a fifth. Nothing
 * anywhere compared any of them to the engine. The desynchronisation this file
 * exists to catch was invisible in both directions.
 *
 * WHY A TEST AND NOT A LANGUAGE-LEVEL DERIVATION. The honest fix would be for
 * each class to name the engine's constant directly. It cannot: PHP 8.3.6
 * refuses to read a `private` constant from another class at compile time, and
 * `ReflectionClass::getConstant()` — which DOES read private constants,
 * verified on this host — is a runtime call and cannot initialise a `const`.
 * Promoting the engine's constant to `public` is the alternative, and it is a
 * change to a file this lane does not own. Until then this test IS the
 * derivation, and the doc-blocks cite it rather than asserting an inheritance
 * that nothing checks.
 *
 * AND MEMBERSHIP IS DERIVED TOO. The first version of this file listed the
 * family by hand, which left the same defect one level up: a fourth framer that
 * copied the doc-block and the literal joined the family invisibly and was
 * never compared to the engine. {@see self::claimants()} now reads the roster
 * off the declarations in `src/`, and {@see
 * self::testTheRosterScannerAgreesWithASourceWhoseAnswerIsKnown()} pushes a
 * known-answer source through the same scanner, because a roster that is
 * derived can go quietly small in a way a hand list cannot.
 *
 * @see \SugarCraft\Crush\Tests\MCP\McpFrameCapTest for what each framer does at
 *      the cap; this file only pins that they all mean the same number.
 */
final class FrameCapFamilyTest extends TestCase
{
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
     * exactly.
     *
     * The scanner is a token walk rather than a regex because a `const` inside
     * a nested class-like has to be attributed to the right class, and it is
     * pushed through a source whose answer is known in
     * {@see self::testTheRosterScannerAgreesWithASourceWhoseAnswerIsKnown()} —
     * an emptiness or near-emptiness that no known-positive fixture backs is
     * indistinguishable from a dead instrument.
     *
     * @return list<class-string>
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
            foreach (self::declarersIn((string) file_get_contents($f->getPathname())) as $class) {
                if ($class === EngineBackend::class) {
                    continue;
                }
                $out[] = $class;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * The fully-qualified names of the classes in one source that declare a
     * `MAX_FRAME_BYTES` constant of their own.
     *
     * A name this walk cannot assemble is NOT dropped: it comes back as the
     * empty string, and the caller asserts every entry is a real class. A
     * scanner that quietly discards what it cannot parse has a hole shaped
     * exactly like the next member of the family.
     *
     * @return list<string>
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
                    && $tokens[$k][1] === 'MAX_FRAME_BYTES') {
                    $out[] = $class === '' ? '' : ($namespace === '' ? $class : $namespace . '\\' . $class);
                }

                break;
            }
        }

        return $out;
    }

    /**
     * THE ROSTER SCANNER, PUSHED THROUGH A SOURCE WHOSE ANSWER IS KNOWN.
     *
     * `claimants()` now DERIVES family membership instead of listing it, which
     * means the roster can go quietly wrong in a way the old hand list could
     * not: a scanner that matches nothing produces a small, plausible, entirely
     * green roster, and `testEveryClaimantAgreesWithTheEngine()` then iterates
     * over it asserting nothing at all. So the scanner answers a fixture here,
     * in the same test file, in both polarities.
     *
     * The last row is the important one: a `const MAX_FRAME_BYTES` that belongs
     * to no class at all comes back as an EMPTY NAME rather than being dropped,
     * so `testTheDerivedRosterIsRealClasses()` turns it red. A scanner that
     * silently discards what it cannot attribute has a hole shaped exactly like
     * the next member of the family.
     */
    public function testTheRosterScannerAgreesWithASourceWhoseAnswerIsKnown(): void
    {
        $const = 'MAX_' . 'FRAME_' . 'BYTES';

        $this->assertSame(
            ['Demo\\Framing\\Framer'],
            self::declarersIn(
                "<?php\nnamespace Demo\\Framing;\n"
                . "final class Framer { private const " . $const . " = 1; private const OTHER = 2; }\n"
                . "final class NotAFramer { private const SOMETHING_ELSE = 3; }\n",
            ),
            'the roster scanner is not attributing the declaration to the class it is written '
            . 'in, so every name claimants() derives is suspect',
        );

        $this->assertSame(
            ['Demo\\Framing\\Second'],
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
            [''],
            self::declarersIn("<?php\nnamespace Demo;\nconst " . $const . " = 1;\n"),
            'a declaration this scanner cannot attribute to a class is being DROPPED rather '
            . 'than surfaced as an unusable name, which is how a real family member goes '
            . 'unnoticed',
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
            'the family derivation found nothing at all, so testEveryClaimantAgreesWithTheEngine() '
            . 'below iterates over an empty list and asserts nothing. That is a broken walk over '
            . 'src/, not a family that has disbanded',
        );

        foreach ($claimants as $class) {
            $this->assertTrue(
                class_exists($class),
                'the family derivation produced "' . $class . '", which is not a class. Either the '
                . 'namespace/class assembly in declarersIn() is wrong, or a MAX_FRAME_BYTES was '
                . 'declared somewhere this scanner cannot attribute it to a class',
            );
        }

        $this->assertNotContains(
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

        $source = $engine->getConstant('MAX_FRAME_BYTES');
        $this->assertIsInt($source, 'the engine no longer declares MAX_FRAME_BYTES as an int');
        $this->assertGreaterThan(0, $source);

        $this->assertFalse(
            $engine->getConstant('MAX_FRAME_BYTES_NO_SUCH_CONSTANT'),
            'getConstant() answered something other than false for a constant that does not '
            . 'exist, so a false it returns elsewhere can no longer be read as "absent" and '
            . 'the comparisons below could be comparing two failures to each other',
        );

        $this->assertTrue(
            $engine->getReflectionConstant('MAX_FRAME_BYTES')->isPrivate(),
            'EngineBackend::MAX_FRAME_BYTES is no longer private, so the family can now name it '
            . 'directly: replace each `64 * 1024 * 1024` literal with the constant itself and '
            . 'this test becomes a formality rather than the only thing holding the family '
            . 'together',
        );

        $this->assertNotSame([], self::claimants());
    }

    /**
     * The claim itself: every member equals the engine, and every member spells
     * the constant ITSELF rather than inheriting one from a parent — an
     * inherited constant would compare equal while the class it is written in
     * is not a member of this family at all.
     */
    public function testEveryClaimantAgreesWithTheEngine(): void
    {
        $source = (new \ReflectionClass(EngineBackend::class))->getConstant('MAX_FRAME_BYTES');

        foreach (self::claimants() as $class) {
            $ref = new \ReflectionClass($class);
            $constant = $ref->getReflectionConstant('MAX_FRAME_BYTES');

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
                . 'The doc-block on ' . $class . ' says the number is inherited from the '
                . 'engine, and until this test existed nothing checked that. THE FIX IS TO '
                . 'MOVE BOTH, not to relax this: a framer that accepts a payload the engine '
                . 'will refuse turns a clean over-size rejection into a truncated frame one '
                . 'process further down. If the divergence is deliberate, delete the '
                . 'inheritance claim from that class\'s doc-block in the same commit AND stop '
                . 'that class declaring its own MAX_FRAME_BYTES, which is what claimants() reads '
                . 'membership from -- there is no hand list left to edit.',
            );
        }
    }
}
