<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Attachments;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachments\MentionIndex;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\ScoringProfile;

/**
 * TUI-1 (plan_comparison): the per-project file list and its fuzzy ranking.
 *
 * Fixtures are real `git init` roots (the listing is literally
 * `ls-files -z --cached --others --exclude-standard`, so a fake FS cannot
 * exercise it) built under a private temp dir and dropped in tearDown.
 * Every test calls {@see MentionIndex::forget()} around itself: the memo is
 * process-global on purpose, and a leaked listing would make the suite
 * order-dependent.
 */
final class MentionIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        MentionIndex::forget();
        $this->root = \sys_get_temp_dir() . '/w2b-index-' . uniqid('r', true);
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/deep/nested', 0777, true);
        exec('git init --quiet ' . escapeshellarg($this->root) . ' 2>&1');
        foreach (['src/Chat.php', 'src/Renderer.php', 'src/input_parser.php', 'index.php', 'deep/nested/thing.php'] as $rel) {
            file_put_contents($this->root . '/' . $rel, '');
        }
    }

    protected function tearDown(): void
    {
        MentionIndex::forget();
        exec('rm -rf ' . escapeshellarg($this->root) . ' 2>&1');
    }

    public function testItListsTrackedAndUntrackedFilesInPathOrder(): void
    {
        // No `git add` anywhere: --others is what carries a fresh repo, and
        // it is also the only way a just-created file reaches the popup.
        $this->assertSame([
            'deep/nested/thing.php',
            'index.php',
            'src/Chat.php',
            'src/Renderer.php',
            'src/input_parser.php',
        ], MentionIndex::files($this->root));
    }

    public function testItHonoursTheStandardIgnores(): void
    {
        // The star is spelled through chr(42) on purpose: a glob-shaped
        // string literal here would join the GlobDialect differential
        // corpus and drift its pinned pair figure.
        file_put_contents($this->root . '/.gitignore', 'secret' . chr(42) . "\n");
        file_put_contents($this->root . '/secret.env', '');

        $listed = MentionIndex::files($this->root);

        $this->assertNotContains('secret.env', $listed);
        $this->assertContains('.gitignore', $listed, 'the ignore file itself is a candidate like any other');
    }

    public function testABasenameHitOutranksAnIncidentalPathHit(): void
    {
        $rows = MentionIndex::rank('cha', $this->root);

        $this->assertSame(['src/Chat.php'], array_column($rows, 'path'));
        $this->assertSame('Chat.php', $rows[0]['subject'], 'the basename carries the match, so the basename is the highlight subject');
        $this->assertSame([0, 1, 2], $rows[0]['indices'], 'c-h-a codepoints of Chat.php');
    }

    public function testAPartialInsideTheDirectoryOnlyHighlightsTheWholePath(): void
    {
        $rows = MentionIndex::rank('nest/th', $this->root);

        $this->assertSame(['deep/nested/thing.php'], array_column($rows, 'path'));
        $this->assertSame('deep/nested/thing.php', $rows[0]['subject'], 'no basename match exists, so the path is the subject');
    }

    public function testScoresBreakTiesByTheShorterPathFirst(): void
    {
        $rows = MentionIndex::rank('in', $this->root);
        $paths = array_column($rows, 'path');

        $this->assertContains('index.php', $paths);
        $this->assertContains('src/input_parser.php', $paths);
        $this->assertLessThan(array_search('src/input_parser.php', $paths, true), array_search('index.php', $paths, true));
    }

    public function testNothingRanksForAQueryNoFileContainsInOrder(): void
    {
        $this->assertSame([], MentionIndex::rank('zzqx', $this->root));
    }

    /**
     * TUI-1 (plan_comparison): `rank()` must APPLY its `$limit` — on the cold
     * memo-miss, where the store statement used to hand back the whole
     * ranking it had just written, and on every later call, where the memo
     * keeps a bounded stored window (up to POPUP_LIMIT+1 rows — five here,
     * this fixture is small) and each answer is sliced to its own limit. A
     * cold frame that showed every match while warm frames showed `POPUP_LIMIT`
     * moved the popup's wrap-count between the first `@` and the next.
     */
    public function testTheLimitIsAppliedColdAndWarmAndIsNotBakedIntoTheMemo(): void
    {
        $this->assertCount(5, MentionIndex::rankPaths('php', MentionIndex::files($this->root), 100), 'fixture: five tracked paths match "php", more than the limits below');

        $cold = MentionIndex::rank('php', $this->root, 3);
        $this->assertCount(3, $cold, 'the cold miss honoured neither the argument nor its own default shape');

        $warm = MentionIndex::rank('php', $this->root, 3);
        $this->assertSame($cold, $warm, 'the warm answer is byte-identical to the cold one');

        $tighter = MentionIndex::rank('php', $this->root, 2);
        $this->assertSame(\array_slice($cold, 0, 2), $tighter, 'a later call with a tighter limit slices the SAME stored window — the answer is the slice');

        $this->assertCount(5, MentionIndex::rank('php', $this->root), 'and the default limit still sees the whole fixture');
    }

    public function testTheEmptyPartialHeadsTheSortedListing(): void
    {
        $rows = MentionIndex::rank('', $this->root);

        $this->assertSame(array_slice(MentionIndex::files($this->root), 0, MentionIndex::POPUP_LIMIT), array_column($rows, 'path'));
        $this->assertSame([], $rows[0]['indices'], 'nothing is highlighted when nothing was typed');
    }

    public function testANonGitRootListsNothing(): void
    {
        $stray = $this->root . '-stray';
        mkdir($stray, 0777, true);
        file_put_contents($stray . ('/loose.txt'), '');

        $this->assertSame([], MentionIndex::files($stray));
        $this->assertSame([], MentionIndex::rank('loose', $stray));

        exec('rm -rf ' . escapeshellarg($stray) . ' 2>&1');
    }

    public function testTheListingIsAMemoAndForgetRefreshesIt(): void
    {
        $this->assertNotContains('late.txt', array_map(static fn(string $p): string => basename($p), MentionIndex::files($this->root)));

        file_put_contents($this->root . '/late.txt', '');
        $this->assertNotContains('late.txt', array_map(static fn(string $p): string => basename($p), MentionIndex::files($this->root)), 'the memo holds until it is dropped');

        MentionIndex::forget($this->root);
        $this->assertContains('late.txt', array_map(static fn(string $p): string => basename($p), MentionIndex::files($this->root)));
    }

    public function testAMissingRootListsNothing(): void
    {
        $this->assertSame([], MentionIndex::files($this->root . '-nowhere'));
    }

    /**
     * TUI-1 round 2: the byte-screened fast path must be a DIFFERENTIAL, not
     * a reinterpretation. On every fixture this class can keep cheap (all
     * survivors inside the disclosed candidate budget, no certified flood),
     * rank()'s full answer must be byte-identical to what the legacy
     * every-file matcher walk produced before the screen existed — rows,
     * scores, highlight indices and tie order included. That equivalence is
     * the whole license for the pruning branches; if the ceiling algebra
     * ever stops holding (a profile change, a fold divergence, a screenable
     * that is not), the leg below reddens rather than silently reshuffling
     * the popup. The non-ASCII partial exercises the fail-open branch, and
     * the two unscreenable fixture files (byte-wise names) exercise the
     * manual bucket the fast path refuses to certify.
     */
    public function testTheScreenedRankingIsByteIdenticalToTheLegacyWalkWithinBudget(): void
    {
        mkdir($this->root . '/idx', 0777, true);
        mkdir($this->root . '/misc', 0777, true);
        mkdir($this->root . '/ünïcode', 0777, true);
        file_put_contents($this->root . '/idx/aXa.php', '');
        file_put_contents($this->root . '/misc/Strangé.php', '');
        file_put_contents($this->root . '/ünïcode/fïle.php', '');

        foreach (['a', 'cha', 'CHAT', 'php', 'in', 'f', 'nest/th', 'input_pa', 'src/cha', 'deep/nested/th', 'zzqx', 'ünï', 'cod', 'aXa', 'cha.php'] as $partial) {
            $this->assertSame(
                $this->legacyWalkRank($partial, MentionIndex::files($this->root)),
                MentionIndex::rank($partial, $this->root),
                "fast path diverged from the legacy walk for {$partial}",
            );
        }
    }

    /**
     * TUI-1 round 2, the prune branch: once the certified tier alone fills
     * the stored window, every weaker tier is dropped UNSCORED — exact by
     * the ceiling algebra (2U+L and 3U are both strictly below 3L for the
     * canonical profile, widths ≥ 2). The differential must still hold at
     * that boundary: the window rows are exactly the legacy ranking's head,
     * each with the certified score and the earliest-occurrence indices.
     * And the MEMO entry now stores only the window (RANK_MEMO_TOP_ROWS),
     * never the full ranking — 64 entries of 101 rows is a couple of MB,
     * 64 entries of 50k rows was the hundred-MB finding.
     */
    public function testTheWindowBranchHeadsTheLegacyRankingAndBoundsTheMemo(): void
    {
        mkdir($this->root . '/win', 0777, true);
        for ($i = 0; $i < 101; $i++) {
            // Half the names carry the needle at basename offset 0 and half
            // at offset 1, so the window pins the offset arithmetic too.
            file_put_contents($this->root . '/win/' . ($i % 2 === 0 ? "fxx{$i}" : "qfxx{$i}") . '.php', '');
        }
        // Two unscreenable decoys join the merge from the other side of the
        // screen. The tie certifies at 3L (its fold keeps f-x-x adjacent)
        // and rides the legacy head order exactly like the screened rows.
        // The LOSER covers the query only scattered (ä between the x's), so
        // its sub-3L row must never materialize inside the window — yet its
        // path sorts EARLY in its length group, so if the equality filter
        // stopped discriminating it would displace a real 57-point row from
        // the head the legacy walk puts first. That is the mutation this
        // fixture is built to catch.
        mkdir($this->root . '/ünï', 0777, true);
        file_put_contents($this->root . '/ünï/fxxï.php', '');
        mkdir($this->root . '/wex', 0777, true);
        file_put_contents($this->root . '/wex/fxäx.txt', '');

        $profile = ScoringProfile::canonical();
        $cover = 3 * $profile->matchScore + 2 * $profile->adjacentBonus;
        $oracle = $this->legacyWalkRank('fxx', MentionIndex::files($this->root));

        $rows = MentionIndex::rank('fxx', $this->root);

        $this->assertCount(MentionIndex::POPUP_LIMIT, $rows, 'the answer is the window sliced to the popup limit');
        $this->assertSame(\array_slice($oracle, 0, MentionIndex::POPUP_LIMIT), $rows, 'the pruned head must equal the legacy head row for row');
        foreach ($rows as $row) {
            $this->assertSame(3 * $cover, $row['score'], 'every window row sits at the certified 3L');
            $this->assertStringContainsStringIgnoringCase('fxx', $row['subject'], 'and every one of them really contains it in the basename');
        }

        $memo = $this->currentRankMemo();
        $entry = $memo[\realpath($this->root) . "\0" . 'fxx'];
        $this->assertCount(MentionIndex::POPUP_LIMIT + 1, $entry, 'the store keeps exactly the widest slice any caller takes, not the full ranking');
        $this->assertSame(\array_slice($entry, 0, 5), MentionIndex::rank('fxx', $this->root, 5), 'a warm tighter limit slices that same stored window');
    }

    /**
     * TUI-1 round 2: a caller asking for MORE than the stored window (only
     * fs.search's over-limit clamp can; the popup never does) is served the
     * full fresh ranking and NOT memoized — a bounded store and a wider
     * answer cannot share one entry, and the rare wide caller is allowed to
     * pay cold rather than evict everybody else.
     */
    public function testAWiderThanWindowCallerIsServedButNeverMemoized(): void
    {
        $wide = MentionIndex::rank('php', $this->root, MentionIndex::POPUP_LIMIT + 50);

        $this->assertSame($this->legacyWalkRank('php', MentionIndex::files($this->root)), $wide);
        $this->assertArrayNotHasKey(\realpath($this->root) . "\0" . 'php', $this->currentRankMemo(), 'the wide answer bypassed the store');

        MentionIndex::rank('php', $this->root, 5);
        $this->assertArrayHasKey(\realpath($this->root) . "\0" . 'php', $this->currentRankMemo(), 'the ordinary call beside it still memoizes normally');

        // And the cap is load-bearing on the branch that DOES produce a
        // ranking wider than the window: 150 directory-only hits leave the
        // certified bucket short, the short branch walks them all, and what
        // lands in the memo must be the bounded window — not 150 rows.
        mkdir($this->root . '/in', 0777, true);
        for ($i = 0; $i < 150; $i++) {
            file_put_contents($this->root . '/in/named' . $i . '.txt', '');
        }
        MentionIndex::forget($this->root);

        $full = $this->legacyWalkRank('in', MentionIndex::files($this->root));
        $this->assertGreaterThan(MentionIndex::POPUP_LIMIT + 1, \count($full), 'fixture: the ranking outgrows the window');

        $served = MentionIndex::rank('in', $this->root);
        $this->assertSame(\array_slice($full, 0, MentionIndex::POPUP_LIMIT), $served, 'the popup gets its slice of the full legacy head');

        $entry = $this->currentRankMemo()[\realpath($this->root) . "\0" . 'in'];
        $this->assertCount(MentionIndex::POPUP_LIMIT + 1, $entry, 'the store kept the window, not the whole 150-row ranking');
        $this->assertSame(\array_slice($entry, 0, MentionIndex::POPUP_LIMIT), $served, 'warm and cold read the one bounded entry');
        $this->assertCount(\count($full), MentionIndex::rank('in', $this->root, 200), 'the wide caller is served the entire legacy ranking, bypassed');
    }

    /**
     * TUI-1 round 2: the entry cap still bounds the CACHE as a whole — the
     * store shape shrank, the eviction ladder is unchanged, and this pins
     * that the FIFO head is what leaves.
     */
    public function testTheRankMemoStillEvictsTheOldestEntryPastSixtyFour(): void
    {
        $max = (new \ReflectionClass(MentionIndex::class))->getConstant('RANK_MEMO_MAX');
        $this->assertSame(64, $max, 'the documented entry cap');
        $seeds = [];
        for ($i = 0; $i < $max; $i++) {
            $seeds['seedroot' . "\0" . 'q' . $i] = [['path' => 's', 'subject' => 's', 'indices' => [], 'score' => 0]];
        }
        (new \ReflectionProperty(MentionIndex::class, 'rankMemo'))->setValue(null, $seeds);

        $rows = MentionIndex::rank('cha', $this->root);

        $memo = $this->currentRankMemo();
        $this->assertSame(['src/Chat.php'], array_column($rows, 'path'), 'fixture: the cold rank returned its one row');
        $this->assertCount($max, $memo, 'the cache is still held at its entry cap');
        $this->assertArrayNotHasKey('seedroot' . "\0" . 'q0', $memo, 'the oldest seeded entry made room');
        $this->assertArrayHasKey('seedroot' . "\0" . 'q1', $memo);
        $this->assertArrayHasKey(\realpath($this->root) . "\0" . 'cha', $memo, 'and the new entry is the bounded window');
    }

    /**
     * Upstream's comparator compared $b's score on both sides, so ordering
     * fell through to path length and a certifiably worse row could lead
     * the popup (TUI-1 round 2 repair, disclosed behavior change). Under
     * score-major order the certified basename hit leads even though its
     * path is the LONGER of the two; the legacy walk agrees row for row.
     */
    public function testScoresDecideTheOrderAndNotThePathLength(): void
    {
        mkdir($this->root . '/cha', 0777, true);
        file_put_contents($this->root . '/cha/p.php', '');

        $rows = MentionIndex::rank('cha', $this->root);

        $this->assertSame(
            ['src/Chat.php', 'cha/p.php'],
            array_column($rows, 'path'),
            'the 3L certified row outranks the L directory row despite the longer path',
        );
        $this->assertSame($this->legacyWalkRank('cha', MentionIndex::files($this->root)), $rows);
    }

    /**
     * The legacy walk, frozen here on purpose: the point of the pins above
     * is that the production fast path agrees with what the UNBOUNDED
     * implementation did, and a copy that calls production to build the
     * expected side proves nothing.
     *
     * @param  list<string>  $files
     * @return list<array{path: string, subject: string, indices: list<int>, score: int}>
     */
    private function legacyWalkRank(string $partial, array $files): array
    {
        $matcher = SmithWatermanMatcher::new(requireFullQuery: true);
        $rows = [];
        foreach ($files as $path) {
            $base = basename($path);
            $rowBase = $matcher->match($partial, $base);
            if ($rowBase !== null) {
                $rowPath = $matcher->match($partial, $path);
                $rows[] = [
                    'path' => $path,
                    'subject' => $base,
                    'indices' => $rowBase->indices(),
                    'score' => 2 * $rowBase->score + ($rowPath?->score ?? 0),
                ];
                continue;
            }

            $rowPath = $matcher->match($partial, $path);
            if ($rowPath !== null) {
                $rows[] = ['path' => $path, 'subject' => $path, 'indices' => $rowPath->indices(), 'score' => $rowPath->score];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return [$b['score'], strlen($a['path']), $a['path']] <=> [$a['score'], strlen($b['path']), $b['path']];
        });

        return $rows;
    }

    /** @return array<string, list<array{path: string, subject: string, indices: list<int>, score: int}>> */
    private function currentRankMemo(): array
    {
        return (new \ReflectionProperty(MentionIndex::class, 'rankMemo'))->getValue() ?? [];
    }
}
