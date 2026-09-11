<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;

/**
 * E633-SLICE-1: a doc-block that quotes measured figures AND draws a
 * qualitative conclusion from them reds instead of rotting.
 *
 * The parent entry's acceptance test is "a mutation of each CONCLUSION, not of
 * the figure underneath it", so each arm below parses the SENTENCE out of the
 * live source and recomputes every number and multiplier it states from the
 * constants it cites — changing a constant, a figure, or the multiplier
 * without the other two reddens here. Scope is the src/ directories no other
 * round-63 lane owns (Support, Skills, Diagnostics; full verdicts and the
 * remainder ledger live in the round-63 E633 notes).
 *
 * VACUITY: every arm asserts its pattern matched before comparing (a deleted
 * claim escapes the pin only by also deleting the test's ability to silently
 * pass), and the two rewritten sentences are themselves pinned as prose —
 * E633's own lesson that an unpinned correction rots back into the claim it
 * corrected.
 *
 * @internal
 */
final class DocFigureProseDriftTest extends TestCase
{
    /**
     * ToolIpcFiles::STALE_AFTER_SECONDS justifies the hour against three named
     * budgets and a "~Nx the largest" multiplier. Every figure in that
     * sentence is re-derived live; at the time of writing 3600/120 is exactly
     * 30, so "~30x" holds — if EngineBackend's silence cap moves to 1800s the
     * sentence would claim 30x of a 2x margin and must red here instead.
     */
    public function testStalePayloadCutoffProseSurvivesItsConstants(): void
    {
        $doc = self::docBlockOf(ToolIpcFiles::class, 'STALE_AFTER_SECONDS');

        self::assertSame(
            1,
            preg_match(
                '/(\d+)s group deadline.*?(\d+)s tool timeout.*?\((\d+)s of silence\).*?An hour is.*?~(\d+)x the largest/s',
                $doc,
                $m,
            ),
            'the STALE_AFTER_SECONDS justification no longer names its three budgets and its '
            . 'multiplier in one sentence — rewrite the pin with the prose, do not delete it',
        );

        $deadline = (int) (new \ReflectionClass(Runtime::class))->getConstant('PARALLEL_TOOL_DEADLINE_SECONDS');
        $toolTimeout = (int) (new \ReflectionClass(Chat::class))->getConstant('PARALLEL_TOOL_TIMEOUT_SECONDS');
        $silence = (int) (new \ReflectionClass(EngineBackend::class))->getConstant('COMPLETE_TIMEOUT_SECONDS');
        $stale = (int) (new \ReflectionClass(ToolIpcFiles::class))->getConstant('STALE_AFTER_SECONDS');

        self::assertSame($deadline, (int) $m[1], 'prose group-deadline figure drifted from Runtime');
        self::assertSame($toolTimeout, (int) $m[2], 'prose tool-timeout figure drifted from Chat');
        self::assertSame($silence, (int) $m[3], 'prose silence-cap figure drifted from EngineBackend');

        $largest = max($deadline, $toolTimeout, $silence);
        self::assertSame(
            intdiv($stale, $largest),
            (int) $m[4],
            "the '~Nx the largest' multiplier no longer matches STALE_AFTER_SECONDS / max(budgets)",
        );
        self::assertSame(3600, $stale, 'the sentence still says "An hour" — keep the two in step');
    }

    /**
     * The sink clip must fit one datagram WITH margin — that is the pinned
     * form of the sentence, replacing the "order of magnitude" claim which
     * was FALSE as written (the worst case the very same paragraph states is
     * under 1,700 bytes; 8,192 is ~4.9x, not 10x).
     */
    public function testNoticeWorstCaseFitsOneDatagramWithPinnedMargin(): void
    {
        $maxChars = RuntimeNoticeSink::MAX_CHARS;
        $suffixLen = \strlen(\sprintf(
            RuntimeNoticeSink::OVERFLOW_FORMAT,
            \PHP_INT_MAX,
            's',
        ));
        // MAX_CHARS counts UTF-8 characters; the widest encoding is 4 bytes.
        $worstCaseBytes = $maxChars * 4 + $suffixLen;

        $datagram = (int) (new \ReflectionClass(RuntimeNoticeSink::class))->getConstant('DATAGRAM_BYTES');

        self::assertIsInt($datagram, 'DATAGRAM_BYTES vanished — the clip-size claim has no referent');
        self::assertLessThan(
            $datagram,
            $worstCaseBytes,
            'the worst-case notice no longer fits one datagram; the MAX_CHARS doc-block still '
            . 'promises it does',
        );
        self::assertGreaterThanOrEqual(
            4,
            intdiv($datagram, $worstCaseBytes),
            'the margin collapsed under 4x — the sentence says "with margin to spare", so either '
            . 'restore the margin or reword the sentence here and in RuntimeNoticeSink together',
        );

        $doc = self::docBlockOf(RuntimeNoticeSink::class, 'DATAGRAM_BYTES');
        self::assertStringNotContainsString(
            'order of magnitude',
            $doc,
            'the retracted 10x claim is back; the arithmetic above is ~5x and the prose must not '
            . 'oversell it (E633: the correction carried the same defect as the claim)',
        );
        self::assertStringContainsString('with margin to spare', $doc, 'the pinned sentence moved');
    }

    /**
     * MAX_DIRECTORIES is a runaway guard, not a sizing target — pinned as
     * prose, and the retired multiplier is asserted ABSENT so the "two orders
     * of magnitude" headroom claim (built on an unpinned "tens of directories"
     * premise) cannot creep back.
     */
    public function testSkillDirectoryCapIsFramedAsARunawayGuard(): void
    {
        $doc = self::docBlockOf(SkillLoader::class, 'MAX_DIRECTORIES');

        self::assertStringContainsString('runaway guard, not a sizing target', $doc);
        self::assertStringNotContainsString('orders of magnitude', $doc);
        self::assertStringNotContainsString('tens of directories', $doc);
    }


    /**
     * E685 (E633-SLICE-2): the trait headline reserves Read a quarter and
     * states the resulting multiples ("sixteen times" DEFAULT_MAX_INSTRUCTION_
     * BYTES, "four times" DEFAULT_MAX_OUTPUT_BYTES) - live arithmetic on three
     * constants, so every word is re-derived here rather than trusted. The two
     * de-digitalised corrections minted the same round (the size-agnostic
     * CLAUDE.md sentence; the digit-less Glob rationale) are pinned as prose:
     * E633's lesson that an unpinned correction rots back into the claim it
     * corrected.
     */
    public function testToolsReserveMultipliersSurviveTheirConstants(): void
    {
        $traitDoc = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/src/Tools/Concerns/TruncatesOutput.php',
        );

        self::assertSame(
            1,
            preg_match(
                '/default `\$\w+` is (\d+) MiB.{0,30}?reserve is (\d+).{0,12}?KiB.{0,12}?sixteen times the flat'
                . ' \{\@see DEFAULT_MAX_INSTRUCTION_BYTES\}.*?and four times this/s',
                $traitDoc,
                $m,
            ),
            'the reserve sentence no longer spells its MiB, its KiB reserve, and both multipliers'
            . ' in one sentence — rewrite the pin with the prose, do not delete it',
        );

        $readDefault = null;
        foreach ((new \ReflectionClass(Read::class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === 1_048_576) {
                    $readDefault = 1_048_576;
                    break 2;
                }
            }
        }
        self::assertNotNull(
            $readDefault,
            'nothing in Read defaults to 1 MiB any more — the headline still says "is 1 MiB",'
            . ' so the sentence and this probe must move together',
        );
        self::assertSame((int) $m[1], intdiv($readDefault, 1_048_576), 'prose MiB figure drifted from Read');
        self::assertSame((int) $m[2], intdiv(intdiv($readDefault, 4), 1024), 'prose reserve-KiB drifted from Read/4');

        $instruction = (int) (new \ReflectionClass(TruncatesOutput::class))->getConstant('DEFAULT_MAX_INSTRUCTION_BYTES');
        $output = (int) (new \ReflectionClass(TruncatesOutput::class))->getConstant('DEFAULT_MAX_OUTPUT_BYTES');
        $reserve = intdiv($readDefault, 4);
        self::assertSame(16, intdiv($reserve, $instruction), '"sixteen times" is no longer the true multiple of DEFAULT_MAX_INSTRUCTION_BYTES');
        self::assertSame(4, intdiv($reserve, $output), '"four times" is no longer the true multiple of DEFAULT_MAX_OUTPUT_BYTES');

        // pinned corrections (each false as a digit, de-digitalised this round)
        self::assertStringNotContainsString('9,611-byte `CLAUDE.md` verbatim', $traitDoc, 'the stale repo-CLAUDE.md digit is back — the file is 9,167 bytes at 07a53049b and any digit rots');
        self::assertStringContainsString('verbatim - comfortably true at any', $traitDoc, 'the corrective sentence moved');
        $globDoc = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Tools/BuiltIn/Glob.php');
        self::assertStringNotContainsString('112,000', $globDoc, 'the unlabeled whole-tree figure is back; the point needs no digit');
        self::assertStringContainsString('whole tree and discarding nearly all of it', $globDoc, 'the Glob corrective sentence moved');
    }

    private static function docBlockOf(string $class, string $constant): string
    {
        $reflection = new \ReflectionClass($class);
        $property = $reflection->getReflectionConstant($constant);

        self::assertNotFalse($property, "{$class}::{$constant} no longer exists");
        $doc = $property->getDocComment();

        self::assertIsString($doc, "{$class}::{$constant} lost its doc-block — the claim this pins was deleted, not fixed");

        return $doc;
    }
}
