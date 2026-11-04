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
     *
     * THE ARMS WIDENED 2026-11 (CCD-DOC-01): the pattern used to accuse only
     * the hyphen spelling "round-NN" and three-digit E numbers, so the prose
     * spellings the media and figure-fix campaigns actually shipped — "since
     * round 42", "corrected in round 43 (E78)", "plan W0 finding 3", "the W7
     * adapter" — walked straight through it. The round arm now takes either
     * separator and either case, the E arm takes two or three digits, and a
     * wave arm accuses a bare W-number with word boundaries on both sides.
     * A sweep over the whole walked corpus with the widened arms finds
     * exactly the fourteen standing citations below and no innocent
     * sentence, so the arms ship alongside a named roster instead of a red
     * page: DOC_TOKEN_SEAMS licenses each standing token per page, and the
     * roster is self-retiring — the moment a doc edit strips a token its row
     * goes red and must be deleted, re-arming the census on that shape. This
     * is the WIRE_TOKEN_SEAMS design mirrored from the census below.
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
        $seen = [];
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
                    $seen[$relative][$token] = true;
                    if (\in_array($token, self::DOC_TOKEN_SEAMS[$relative] ?? [], true)) {
                        continue; // the named self-retiring seam row (checked below)
                    }
                    $offenders[] = $relative . ':' . ($number + 1) . ':' . $token;
                }
            }
        }

        self::assertSame([], $offenders,
            'shipped docs cite campaign item IDs — keep the sentence, drop the token '
            . '(see docs/GLOSSARY.md for the policy the 2026-11 sweep applied)');

        // Fail-closed half of the exemption, same shape as the wire census:
        // a row buys silence only while the token is still on the page. When
        // a docs lane strips one, this leg reds and forces the row's deletion
        // — after which the widened arm re-arms on that token with no edit to
        // the scan above.
        foreach (self::DOC_TOKEN_SEAMS as $relative => $listed) {
            foreach ($listed as $token) {
                self::assertArrayHasKey($token, $seen[$relative] ?? [],
                    'the seam roster still licenses ' . $token . ' on ' . $relative
                    . ' — the citation was stripped; delete the row to re-arm the census');
            }
        }
    }

    /**
     * The docs seam roster: page => tokens still standing in its prose.
     * Deliberately narrow — a row buys silence for exactly one token on
     * exactly one page, and the leg above voids the licence when the reason
     * dies. The 2026-11 docs-cleanup lane (G3-B) strips these citations
     * sentence-by-sentence and deletes the matching rows in the same commit;
     * nothing else may. The roster is EMPTY now that every licensed citation
     * was stripped — rows re-enter freely: a future seam registers here for
     * exactly as long as its citation stands on the page.
     */
    private const DOC_TOKEN_SEAMS = [];

    /**
     * WIRE AND CATALOGUE STRINGS CARRY NO CAMPAIGN IDENTIFIERS EITHER.
     *
     * The docs census above polices prose; this one polices the two surfaces
     * that reach a machine or a translation catalogue verbatim: the protocol
     * schema's `describe()` strings (published into
     * docs/protocol/candy-code.v1.schema.json, where a client developer reads
     * them as the contract) and every single-quoted value in `lang/` (the
     * strings manual, shipped to users in twelve locales). Campaign tokens
     * slipped into either — the 2026-11 fix review caught nine live in the
     * wire descriptions — are dangling pointers no consumer can resolve.
     *
     * SCOPE: only string literals are walked (PhpToken's
     * T_CONSTANT_ENCAPSED_STRING), so the GLOSSARY-sanctioned campaign IDs
     * in code COMMENTS stay untouched, and the E-number arm additionally
     * refuses to accuse a brace-delimited codepoint (the private-use-area
     * regex `"\x{E000}-\x{F8FF}"` is protocol vocabulary, not a campaign).
     *
     * THE ONE EXEMPTION IS SELF-RETIRING. `web.composer.queue_reload_title`
     * in lang/en.php still carries its `(WEB-1a)` tail because the value
     * mirrors byte-exactly into the web package's generated English strings
     * file, which lives in a lane this census cannot regen. The exemption is
     * not a licence: it is only admitted while that literal still contains
     * the token, so the day the web lane strips it the row itself goes red
     * and the census re-arms. An exemption that cannot outlive its reason
     * cannot rot into a hole.
     */
    public function testNoShippedWireStringCarriesACampaignToken(): void
    {
        $root = \dirname(__DIR__, 2);
        $files = \array_map(
            static fn (string $path): string => \substr($path, \strlen($root) + 1),
            \array_merge(\glob($root . '/lang/*.php') ?: [], self::protocolSourceFiles($root))
        );
        self::assertGreaterThanOrEqual(14, \count($files),
            'the wire walk lost files — the census would pass vacuously');

        $pattern = self::wireTokenPattern();
        // Vacuity control: the arm must bite the exact shapes this lane just
        // swept out of the wire, and must spare the codepoint vocabulary.
        self::assertSame(1, \preg_match($pattern, "'C-SEC-5.b: which grant door'"), 'the wire arm must accuse a cluster ID');
        self::assertSame(0, \preg_match($pattern, "'\\x{E000}-\\x{F8FF}'"), 'the wire arm must spare a brace-delimited codepoint');
        $offenders = [];
        foreach ($files as $relative) {
            $seamKeys = \array_keys(self::WIRE_TOKEN_SEAMS[$relative] ?? []);
            foreach (\explode("\n", (string) \file_get_contents($root . '/' . $relative)) as $number => $line) {
                foreach ($seamKeys as $seamKey) {
                    if (\str_contains($line, "'" . $seamKey . "'")) {
                        continue 2; // the named self-retiring seam row (checked below)
                    }
                }
                foreach (\PhpToken::tokenize('<?php ' . $line) as $token) {
                    if ($token->id !== \T_CONSTANT_ENCAPSED_STRING) {
                        continue;
                    }
                    if (\preg_match_all($pattern, $token->text, $hits) < 1) {
                        continue;
                    }
                    foreach ($hits[0] as $token2) {
                        $offenders[] = $relative . ':' . ($number + 1) . ':' . $token2;
                    }
                }
            }
        }

        self::assertSame([], $offenders,
            'a shipped wire description or catalogue value carries a campaign ID — '
            . 'keep the sentence, drop the token, and regen the schema '
            . '(php scripts/gen-protocol-schema.php --write) in the same commit; '
            . 'see docs/GLOSSARY.md for the policy');

        // Fail-closed half of the exemption: the row above only buys silence
        // while the token is actually still there. When the web lane strips
        // it, this leg reds and forces the row's deletion — after which the
        // census re-arms on the value with no edit to the scan above.
        $en = (string) \file_get_contents($root . '/lang/en.php');
        foreach (self::WIRE_TOKEN_SEAMS['lang/en.php'] ?? [] as $key => $token) {
            $line = '';
            foreach (\explode("\n", $en) as $candidate) {
                if (\str_contains($candidate, "'" . $key . "'")) {
                    $line = $candidate;
                    break;
                }
            }
            self::assertNotSame('', $line, 'seam row names a key that no longer exists: lang/en.php ' . $key . ' — retire the row');
            self::assertStringContainsString($token, $line,
                'the exempted token is gone from lang/en.php ' . $key
                . ' — the web mirror no longer needs the seam; delete the row to re-arm the census');
        }
    }

    /**
     * The seam roster: file => [lang key => token still living in its value].
     * Deliberately narrow — a row buys silence for exactly one key in exactly
     * one file, and the check above voids the licence when the reason dies.
     */
    private const WIRE_TOKEN_SEAMS = [
        // The value mirrors byte-exactly into candy-code-web's generated
        // strings file; flipping it here without regenerating that mirror
        // (and its dist) breaks the web lane's own drift guard.
        'lang/en.php' => ['web.composer.queue_reload_title' => '(WEB-1a)'],
    ];

    /**
     * @return list<string> every PHP file under src/Protocol, absolute
     */
    private static function protocolSourceFiles(string $root): array
    {
        $paths = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/Protocol', \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        \sort($paths);

        return $paths;
    }

    /**
     * The docs union with the one wire-specific refinement: an E-number
     * inside braces is a Unicode codepoint, not a campaign (the lookbehind
     * eats `{`, the lookahead eats `}`, so the private-use-range literals in
     * the protocol's sanitizer descriptions speak freely).
     */
    private static function wireTokenPattern(): string
    {
        return '/(?<![0-9A-Za-z{])(?:'
            . '[CR]-[A-Z]{2,10}-[0-9]+[a-z]?(?:\.[a-z][0-9a-z]*)*'
            . '|(?:CTX|AGT|EXT|TOOL|PRV|SEC|SES|MCP|VIS|AUT|DST|OBS|GIT|CMD|PLG|INT|EDT|MOD|TL|SHL)-[0-9]+(?:[a-z](?:\.[a-z][0-9a-z]*)*)?(?:-P[0-9]+)?'
            . '|ISS-[0-9]{2,3}'
            . '|(?<!\+)E[0-9]{3}(?![0-9}])'
            . '|WEB-[0-9][a-z]?'
            . '|TUI-[0-9][a-z]?(?: stage [0-9]+)?'
            . '|plan_comparison'
            . ')(?![0-9A-Za-z}])/';
    }

    /**
     * The token union: cluster IDs (letter-suffixed and dotted steps), the
     * bare E-number family (guarded so a U+E000 codepoint mention survives,
     * and since CCD-DOC-01 widened to the two-digit rounds-era spelling too),
     * lane IDs in parentheses, the round counter in either separator and
     * either case, a bare wave number with the word boundaries carried by the
     * shared lookbehind and lookahead, and the plan-file names. The literal
     * carries parentheses throughout, so the glob-shape corpus cannot ingest
     * it; the one paren-free new arm (W-numbers) has no `*` or `?` either.
     */
    private static function campaignTokenPattern(): string
    {
        return '/(?<![0-9A-Za-z])(?:'
            . '[CR]-[A-Z]{2,10}-[0-9]+[a-z]?(?:\.[a-z][0-9a-z]*)*'
            . '|(?:CTX|AGT|EXT|TOOL|PRV|SEC|SES|MCP|VIS|AUT|DST|OBS|GIT|CMD|PLG|INT|EDT|MOD|TL|SHL)-[0-9]+(?:[a-z](?:\.[a-z][0-9a-z]*)*)?(?:-P[0-9]+)?'
            . '|ISS-[0-9]{2,3}'
            . '|(?<!\+)E[0-9]{2,3}(?![0-9])'
            . '|WEB-[0-9][a-z]?'
            . '|TUI-[0-9][a-z]?(?: stage [0-9]+)?'
            . '|plan_comparison'
            . '|\((?:[a-z][0-9]+[a-z]?|R[0-9]+)\)'
            . '|[Rr]ound[- ][0-9]+(?![0-9])'
            . '|W[0-9]+(?: wave| tier)?'
                        . ')(?![0-9A-Za-z])/';
    }
}
