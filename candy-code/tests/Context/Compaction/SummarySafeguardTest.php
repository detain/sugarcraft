<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context\Compaction;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Code\Context\Compaction\SummarySafeguard;
use SugarCraft\Code\Context\Compaction\SummarySafeguardVerdict;
use SugarCraft\Code\Host\CompactionService;

/**
 * CTX-4 (plan_comparison.md): the coverage audit of a model-written compaction
 * summary, unit-pure. The boundary that matters is the HOLD one — collapse is
 * defined as a block missing more than half its model sections AND carrying
 * under half the file references, and every arm of that conjunction is pinned
 * alone so a loosening in either direction reddens exactly here: five missing
 * headings with the files kept is FILL, every heading present with the files
 * dropped is FILL, and only the two together refuse.
 *
 * The FILL side is pinned against the measured healthy shape: the 26-message
 * blocking-tier fixture answers with a subset of records and a block that
 * quotes neither the numbers nor the error text — today's ordinary landing —
 * and it must not HOLD. The 2,400-message 122%-of-source ramble never reaches
 * this class at all: {@see \SugarCraft\Code\Tests\Chat\SummaryRejectedWhenNotSmallerTest}
 * pins the rejection that throws it away before any audit runs.
 */
final class SummarySafeguardTest extends TestCase
{
    /** A block writing all eight model headings, carrying the given body lines. */
    private static function block(array $sections): string
    {
        $out = [];
        foreach ($sections as $heading => $body) {
            $out[] = '## ' . $heading . "\n" . $body;
        }

        return implode("\n", $out);
    }

    /** The fallback the landing would have written: two files, one quoted constraint, one error, one number. */
    private static function fallback(): StateSummaryTemplate
    {
        return StateSummaryTemplate::parse(self::block([
            'Goal' => 'fix the router',
            'Constraints' => 'keep `the router must never buffer` intact',
            'Progress' => '3 exchanges recorded',
            'Key decisions' => 'none',
            'Current work' => 'patching src/Router.php',
            'Next step' => 'run the suite',
            'Pending tasks' => 'none',
            'Errors and fixes' => '- Read: unexpected end of template at line 91234567',
            'Files read' => "- src/Router.php\n- src/Handler.php",
            'Files modified' => '- src/Router.php',
            'Latest unresolved user request' => 'ship it',
        ]));
    }

    /** The exchange texts the needles are mined from: one quoted constraint, one long number. */
    private static function sourceText(): string
    {
        return "user: keep `the router must never buffer` intact and watch port 91234567\n"
            . "assistant: patched the handler, sha 4f9a2b7c1d noted";
    }

    private static function assess(
        ?string $blockText,
        string $recordText = '',
        int $expected = 4,
        int $parsed = 4,
        ?StateSummaryTemplate $fallback = null,
        ?string $sourceText = null,
    ): SummarySafeguard {
        return SummarySafeguard::assess(
            $blockText === null ? null : StateSummaryTemplate::parse($blockText),
            $fallback ?? self::fallback(),
            $sourceText ?? self::sourceText(),
            $recordText,
            $expected,
            $parsed,
            $blockText ?? '',
        );
    }

    public function testACompleteBlockCarryingEveryNeedlePasses(): void
    {
        $block = self::block([
            'Goal' => 'fix the router',
            'Constraints' => 'keep `the router must never buffer` intact',
            'Progress' => '3 exchanges recorded',
            'Key decisions' => 'none',
            'Current work' => 'patching src/Router.php and src/Handler.php',
            'Next step' => 'run the suite, port 91234567, sha 4f9a2b7c1d',
            'Pending tasks' => 'none',
            'Errors and fixes' => '- Read: unexpected end of template at line 91234567',
        ]);

        $audit = self::assess($block);

        self::assertSame(SummarySafeguardVerdict::PASS, $audit->verdict);
        self::assertFalse($audit->isHold());
        self::assertSame('every section written and every tracked fact carried', $audit->summary());
    }

    public function testShortQuotesAndSmallNumbersAreNotNeedles(): void
    {
        // Same block, same section count — but the fallback it is audited
        // against quotes only a nine-character span, says 'port 8080' and
        // names a five-digit count: none of those are facts worth refusing
        // a compaction over, so they never enter the set at all and the
        // coverage stays 1.0 — PASS, not a sub-threshold FILL.
        $audit = self::assess(
            self::block([
                'Goal' => 'fix the router',
                'Constraints' => 'none',
                'Progress' => 'none',
                'Key decisions' => 'none',
                'Current work' => 'none',
                'Next step' => 'none',
                'Pending tasks' => 'none',
                'Errors and fixes' => 'none',
            ]),
            sourceText: 'user: keep `short one` on port 8080 with 12345 as the count',
            fallback: StateSummaryTemplate::parse(self::block([
                'Goal' => 'x',
                'Constraints' => 'keep `short one` intact',
                'Progress' => 'none',
                'Key decisions' => 'none',
                'Current work' => 'none',
                'Next step' => 'none',
                'Pending tasks' => 'none',
                'Errors and fixes' => 'none',
            ])),
        );

        self::assertSame(SummarySafeguardVerdict::PASS, $audit->verdict);
        self::assertSame(1.0, $audit->identifierCoverage);
    }

    public function testALostNumberOrQuoteOnlyEverFills(): void
    {
        // Every heading written, both files carried, but the quoted
        // constraint, the port and the sha dropped — a real quality loss the
        // advisory must be able to describe, and precisely NOT a refusal:
        // the block is sound, the heuristic fills nothing here, and the
        // measured healthy fixture looks like this.
        $block = self::block([
            'Goal' => 'fix the router',
            'Constraints' => 'buffering rule preserved',
            'Progress' => '3 exchanges recorded',
            'Key decisions' => 'none',
            'Current work' => 'patching src/Router.php and src/Handler.php',
            'Next step' => 'run the suite',
            'Pending tasks' => 'none',
            'Errors and fixes' => 'none',
        ]);

        $audit = self::assess($block);

        self::assertSame(SummarySafeguardVerdict::FILL, $audit->verdict);
        self::assertFalse($audit->isHold());
        self::assertStringContainsString('other key details', $audit->summary());
    }

    public function testAPartialBlockWithTheFilesKeptFillsAndNeverHolds(): void
    {
        // Five of eight model headings missing — past the section bound — but
        // every file reference and detail still carried: half the conjunction
        // alone is FILL. This is the arm the old records-side rule wrongly
        // refused over, and why collapse reads the BLOCK only.
        $block = self::block([
            'Goal' => 'fix the router, keep `the router must never buffer` intact',
            'Progress' => '3 exchanges recorded, patching src/Router.php and src/Handler.php',
            'Errors and fixes' => '- Read: unexpected end of template at line 91234567',
        ]);

        $audit = self::assess($block, sourceText: 'user: keep `the router must never buffer` intact and patch src/Router.php and src/Handler.php about 91234567');

        self::assertSame(SummarySafeguardVerdict::FILL, $audit->verdict);
        self::assertFalse($audit->isHold());
        self::assertGreaterThan(
            SummarySafeguard::COLLAPSE_MISSING_SECTIONS,
            count(StateSummaryTemplate::parse($block)->missingHeadings()),
            'the section bound IS crossed — it is the files alone that keep this out of HOLD',
        );
    }

    public function testCollapseIsMissingSectionsAndLostFilesTogether(): void
    {
        // Three headings written (five missing), and neither of the two file
        // references anywhere in the model's own text: the block is junk.
        $block = self::block([
            'Goal' => 'various work happened',
            'Progress' => 'some exchanges recorded',
            'Next step' => 'continue',
        ]);

        $audit = self::assess($block);

        self::assertSame(SummarySafeguardVerdict::HOLD, $audit->verdict);
        self::assertTrue($audit->isHold());
        self::assertStringContainsString('omitted 5 of 8 state sections', $audit->summary());
        self::assertStringContainsString('carried 0 of 2 file references', $audit->summary());
    }

    public function testNoBlockAtAllIsFillNotHold(): void
    {
        // A records-only reply has no block to be collapsed: the landing
        // writes the heuristic block, exactly as the model_empty route always
        // did. expected >= 2 with zero parsed records is NOT enough either —
        // the per-exchange lines are what the heuristic re-fills for.
        $audit = self::assess(null, parsed: 0);

        self::assertSame(SummarySafeguardVerdict::FILL, $audit->verdict);
        self::assertFalse($audit->isHold());
        self::assertStringContainsString('kept 0 of 4 exchange records', $audit->summary());
        self::assertStringContainsString('wrote no state block', $audit->summary());
    }

    public function testTheParsedRecordsFacetsCountAsModelText(): void
    {
        // The haystack is block PLUS record facet values: a file name the
        // model carried only in its per-exchange line is carried.
        $block = self::block([
            'Goal' => 'various work happened',
            'Progress' => 'some exchanges recorded',
            'Next step' => 'continue',
        ]);

        $withFiles = self::assess($block, recordText: 'asked about src/Router.php and src/Handler.php');
        self::assertSame(SummarySafeguardVerdict::FILL, $withFiles->verdict, 'files carried → the second leg fails → not collapse');
        self::assertSame(1.0, $withFiles->fileCoverage);
    }

    public function testTheCapKeepsFileReferencesBeforeAnythingElse(): void
    {
        // More numerics than the identifier cap holds, over an otherwise
        // collapsed block: the cap must never drop a FILE reference in favour
        // of a number, because the file arm is a HOLD input and numeric
        // coverage is not. 301 six-digit numbers plus the fallback's quote
        // and error fragment are more detail needles than fit — the sentence
        // must count exactly MAX_IDENTIFIERS minus the two files, and the
        // file arm must still read the fallback's full two.
        $numbers = implode(' ', array_map(static fn (int $i): string => (string) (100000 + $i), range(0, 300)));
        $block = self::block([
            'Goal' => 'various work happened',
            'Progress' => 'some exchanges recorded',
            'Next step' => 'continue',
        ]);

        $audit = self::assess($block, sourceText: $numbers);

        self::assertSame(SummarySafeguardVerdict::HOLD, $audit->verdict);
        self::assertLessThan(0.5, $audit->fileCoverage);
        self::assertStringContainsString('carried 0 of 2 file references', $audit->summary());
        $matched = preg_match('/carried \d+ of (\d+) other key details/u', $audit->summary(), $m);
        self::assertSame(1, $matched, 'the detail arm must name its bounded denominator: ' . $audit->summary());
        self::assertSame(SummarySafeguard::MAX_IDENTIFIERS - 2, (int) $m[1]);
    }

    public function testErrorFragmentNeedlesComeFromTheFallbackBullets(): void
    {
        // The heuristic's 'Errors and fixes' line '- Read: <fragment>' makes
        // its ': '-tail (bounded at ERROR_FRAGMENT_CHARS) a needle; a model
        // text carrying only the tool name misses, carrying the fragment hits.
        $needle = 'unexpected end of template at line 91234567';

        $missing = self::assess(
            self::block(['Goal' => 'fix the router, keep `the router must never buffer` intact', 'Progress' => '3 exchanges recorded', 'Errors and fixes' => '- Read: something else entirely happened']),
            sourceText: 'user: keep `the router must never buffer` intact and patch src/Router.php and src/Handler.php about 91234567',
        );
        $carried = self::assess(
            self::block(['Goal' => 'fix the router, keep `the router must never buffer` intact', 'Progress' => '3 exchanges recorded', 'Errors and fixes' => '- Read: ' . $needle]),
            sourceText: 'user: keep `the router must never buffer` intact and patch src/Router.php and src/Handler.php about 91234567',
        );

        self::assertStringContainsString('other key details', $missing->summary());
        self::assertGreaterThan($missing->identifierCoverage, $carried->identifierCoverage);
    }

    public function testTheAuditWrapperShortCircuitsAReplyWithNothingUsable(): void
    {
        // The model_empty shape — no block, no parsable records — is the
        // ordinary heuristic landing, not an audit verdict: the wrapper
        // returns an empty map and NO safeguard, so no route refuses or
        // advisories over a reply that claimed nothing to begin with.
        [$summaries, $audit] = CompactionService::auditCompactionReply(
            'just prose, no records and no state block',
            ['k1', 'k2', 'k3', 'k4'],
            self::fallback(),
            self::sourceText(),
        );

        self::assertSame([], $summaries);
        self::assertNull($audit);
    }

    public function testTheAuditWrapperReturnsTheSameSummariesThePlainParseDoes(): void
    {
        // PASS, FILL and HOLD verdicts all return byte-identical summary maps:
        // the audit MEASURES the model's text before the fill, it never edits
        // what the landing receives. The verdict's per-route consequence lives
        // in the landings, not here.
        foreach ([self::collapsedReply(), "1.\nasked: about routing", ''] as $reply) {
            self::assertSame(
                CompactionService::parseCompactionReply($reply, ['k1', 'k2', 'k3', 'k4'], self::fallback()),
                CompactionService::auditCompactionReply($reply, ['k1', 'k2', 'k3', 'k4'], self::fallback(), self::sourceText())[0],
            );
        }
    }

    /** A records line over a collapsed block: the HOLD shape at the reply level. */
    private static function collapsedReply(): string
    {
        return "1.\nasked: about routing\n<session-state>\n"
            . self::block([
                'Goal' => 'work happened',
                'Progress' => 'some exchanges',
                'Next step' => 'continue',
            ])
            . "\n</session-state>";
    }
}
