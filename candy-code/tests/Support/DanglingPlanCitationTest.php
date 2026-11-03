<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * SHIPPED SOURCE MUST NOT POINT AT PLAN DOCUMENTS THAT DO NOT SHIP.
 *
 * The 2026 development campaigns left hundreds of citations of the shape
 * "crush_code Phase 5 item 4" (filename half included) inside `src/` and
 * `bin/` doc-blocks. The campaign LABELS are policy-allowed in comments —
 * `docs/GLOSSARY.md` says comments and changelogs carry campaign IDs and
 * user-facing prose does not — but the FILENAME half of each citation is a
 * repo-relative pointer into planning documents that no clone of the
 * published package ever receives. A reader following "see the feat plan,
 * section 8 E1" by that filename finds nothing: the pointer, not the
 * provenance it encodes, was the broken half.
 *
 * THE SWEEP (M1, 2026-11) stripped the filenames and kept every sentence:
 * the bare "Phase 5 item 4" / "§1 E2" labels stay, which is exactly the
 * GLOSSARY shape. This census keeps it that way — a new comment naming a
 * plan document by its `.md` filename reds here at the commit that would
 * have shipped it, which is the moment the citation is cheapest to fix and
 * the only moment the author is known.
 *
 * WHY THE PATTERN LITERAL CANNOT INDICT THIS FILE: the expression it walks
 * for is `crush_(?:code|feat)` followed by a literal `.md`; fed back at its
 * own source text the alternation never resolves (the characters following
 * `crush_` here are `(?:`), and this doc-block names the two campaigns only
 * WITHOUT their extensions. A census that cannot accuse itself needs no
 * exemption row, and an instrument with no exemption row cannot be bought
 * with one.
 */
final class DanglingPlanCitationTest extends TestCase
{
    use SourceFileWalkTrait;

    /**
     * The roots a published candy-code ships (the two the sweep walked);
     * `examples/` and `workflows/` are deliberately outside the claim — the
     * review scoped M1 to shipped source, and widening later is a claim
     * change, not a bug fix.
     */
    private const CENSUS_ROOTS = ['src', 'bin', 'tests'];

    /**
     * Floor for the walked population: the census would go vacuously green
     * on a walker that found nothing (a renamed root is silent by the walk
     * trait's documented contract), so the walk itself is pinned to a size
     * the package is comfortably above and can only grow past.
     */
    private const MIN_WALKED_FILES = 400;

    /** The docs census must see the real manual, not an empty directory. */
    private const MIN_DOC_FILES = 18;

    public function testTheCitationPatternBitesTheSweptAwayShapes(): void
    {
        // Self-proof that the pattern bites: without this leg a broken
        // pattern (typo, mis-escape) would census nothing and pass forever —
        // the vacuous-green shape the population floor above cannot see.
        // The fixtures are assembled from pieces so this file never carries
        // a complete citation either (see class doc-block).
        $codePlan = 'crush_' . 'code' . '.' . 'md';
        $featPlan = 'crush_' . 'feat' . '.' . 'md';

        self::assertSame(1, preg_match(self::citationPattern(), 'as of ' . $codePlan . ' Phase 1 item 2'));
        self::assertSame(1, preg_match(self::citationPattern(), $featPlan . "'s roster"));
        self::assertSame(0, preg_match(self::citationPattern(), 'crush_' . '(code|feat) and no suffix at all'));
    }

    public function testNoShippedSourceFileCitesAPlanDocumentByFilename(): void
    {
        $package = \dirname(__DIR__, 2);
        $files = self::everySourceFileIn($package, self::CENSUS_ROOTS);

        self::assertGreaterThan(self::MIN_WALKED_FILES, count($files), 'the shipped-source walk found too few files — the census would be vacuous');

        $offenders = [];
        foreach ($files as $relative => $absolute) {
            $contents = (string) \file_get_contents($absolute);
            $hits = preg_match_all(self::citationPattern(), $contents);
            if ($hits > 0) {
                $offenders[] = $relative . ' (' . $hits . ')';
            }
        }

        self::assertSame([], $offenders, 'shipped source cites a campaign plan document by filename — that document does not ship with the package; keep the bare Phase/section/ID label (docs/GLOSSARY.md comment policy) and drop the filename');
    }

    /**
     * The pattern as one quoted string: its alternation arm means the
     * literal cannot match its own file text (class doc-block), so no
     * self-exemption is needed or possible.
     */
    private static function citationPattern(): string
    {
        return '/crush_(?:code|feat)\\.md/';
    }

    /**
     * USER-FACING DOCS CARRY NO CAMPAIGN IDENTIFIERS.
     *
     * docs/GLOSSARY.md draws the line: comments and changelogs may carry the
     * campaigns' item IDs, user-facing prose may not — a reader of a shipped
     * manual has no way to resolve \"behind C-MCP-3.c\" and every such token
     * is a sentence that stopped talking to the reader. The 2026-11 sweep
     * stripped 98 of these citations from README.md and the docs pages
     * (keeping each sentence intact); this census fails the commit that
     * lets one back in.
     *
     * SCOPE AND EXEMPTIONS: the walk covers README.md plus every docs page,
     * skipping fenced code blocks (a fence quotes foreign tool output, e.g.
     * a flake8 error code, and the manual merely transcribes it), and
     * GLOSSARY.md is exempt by construction — it is the policy page whose
     * whole job is naming the tokens the rest of the fleet must not ship.
     * The walk floor is a stated file count so a silently-broken iterator
     * cannot pass this leg vacuously.
     */
    public function testNoShippedDocCitesACampaignIdentifier(): void
    {
        $root = \dirname(__DIR__, 2);
        $files = \array_values(\array_filter(
            \array_merge([$root . '/README.md'], \glob($root . '/docs/*.md') ?: []),
            static fn (string $path): bool => \basename($path) !== 'GLOSSARY.md'
        ));
        self::assertGreaterThanOrEqual(self::MIN_DOC_FILES, \count($files),
            'the docs walk lost pages — the census would pass vacuously');

        $pattern = self::campaignTokenPattern();
        $offenders = [];
        foreach ($files as $path) {
            $relative = \substr($path, \strlen($root) + 1);
            $inFence = false;
            foreach (\explode("\n", (string) \file_get_contents($path)) as $number => $line) {
                if (\str_starts_with(\ltrim($line), '```')) {
                    $inFence = !$inFence;
                    continue;
                }
                $prose = \preg_replace('/`[^`]*`/', ' ', $line) ?? ''; // backtick spans quote protocol literals ((b2) markers) — the census polices prose
                if ($inFence || \preg_match_all($pattern, $prose, $hits) < 1) {
                    continue;
                }
                foreach ($hits[0] as $token) {
                    $offenders[] = $relative . ':' . ($number + 1) . ':' . $token;
                }
            }
        }

        self::assertSame([], $offenders,
            'shipped docs cite campaign item IDs — keep the sentence, drop the token '
            . '(see docs/GLOSSARY.md for the policy the 2026-11 sweep applied)');
    }

    /**
     * The token union: cluster IDs (letter-suffixed and dotted steps), the
     * bare E-number family (guarded so a U+E000 codepoint mention survives),
     * lane IDs in parentheses, and the plan-file names. The literal carries
     * parentheses throughout, so the glob-shape corpus cannot ingest it.
     */
    private static function campaignTokenPattern(): string
    {
        return '/(?<![0-9A-Za-z])(?:'
            . '[CR]-[A-Z]{2,10}-[0-9]+[a-z]?(?:\.[a-z][0-9a-z]*)*'
            . '|(?:CTX|AGT|EXT|TOOL|PRV|SEC|SES|MCP|VIS|AUT|DST|OBS|GIT|CMD|PLG|INT|EDT|MOD|TL|SHL)-[0-9]+(?:[a-z](?:\.[a-z][0-9a-z]*)*)?(?:-P[0-9]+)?'
            . '|ISS-[0-9]{2,3}'
            . '|(?<!\+)E[0-9]{3}(?![0-9])'
            . '|WEB-[0-9][a-z]?'
            . '|TUI-[0-9][a-z]?(?: stage [0-9]+)?'
            . '|plan_comparison'
            . '|\((?:[a-z][0-9]+[a-z]?|R[0-9]+)\)'
            . '|round-[0-9]+(?![0-9])'
            . ')(?![0-9A-Za-z])/';
    }
}
