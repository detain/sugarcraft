<?php

declare(strict_types=1);

/**
 * Guard for `tools/check-root-rows.php` (docs-fix GO-EXEC, zz-guard-impl).
 *
 * Same discipline as CheckDocsAssetsTest: the polarity that matters most is
 * RED. A seed corpus whose checker stopped matching would certify the exact
 * two incident classes it exists for — a weld reverting root rows to pre-drain
 * wording, and root-drain rows never applied at all — forever, quietly, on
 * master. So every finding class (absent, dead-present, missing, ambiguous,
 * no-summary, not-in-seed, not-in-readme) gets a planted-defect fixture that
 * must report and NAME the offender, plus green arms proving the legs do not
 * simply accuse everything. The repo-wide arm is the drift pin: the moment a
 * root row or the seed moves without its counterpart, this file reddens the
 * `Generated docs pages` job. The advisory arm (root-rows' retirement notes)
 * is never-red by design, so it carries a planted polarity pair here instead —
 * the same topology `GenDocsTest` uses for gen-docs' own `note:` lines.
 *
 * HOW TO RUN: see the long note at the top of CheckPathReposTest.php —
 * `sugar-core/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php
 * --no-configuration tools/tests` is the dev-box shape.
 */

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../check-root-rows.php';

final class RootRowsCheckTest extends TestCase
{
    use TmpTree;

    private const GREEN_README = "| <img src=\"media/icons/sugar-demo.png\" width=\"48\" alt=\"\"> | **[SugarDemo](sugar-demo/)** | Demo row wording fresh. |\n";
    private const GREEN_MATCHUPS = "| **SugarDemo** | `sugar-demo/` | port | Demo row wording fresh. |\n";
    private const GREEN_INDEX = "<a class=\"lib-card\" href=\"lib/sugar-demo.html\"><h3>SugarDemo</h3><p class=\"summary\">Demo row wording fresh.</p></a>\n";
    private const STALE_WORDING = 'Demo row wording stale.';
    private const NEEDLE = 'Demo row wording fresh.';
    private const DEAD = 'Box-drawing layout engine';
    private const GIT_DAEMON_DEAD = 'Git daemon, and HTTP';

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('rootrows-');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testTheRepositoryItselfPassesWithZeroFindings(): void
    {
        $findings = \CheckRootRows::derive(dirname(__DIR__, 2));

        $this->assertSame([], $findings, 'root rows must match the seed corpus on master: ' . implode(' | ', $findings));
    }

    public function testAGreenFixtureTreePasses(): void
    {
        $this->writeFixture(self::GREEN_README, self::GREEN_MATCHUPS, self::GREEN_INDEX, '{"slugs":{"sugar-demo":{"readme":{"needles":["' . self::NEEDLE . '"],"dead":[]}}}}');

        $this->assertSame([], $this->deriveFixture());
    }

    public function testAnUnparseableSeedRefusesToCertify(): void
    {
        $this->writeFixture(self::GREEN_README, self::GREEN_MATCHUPS, self::GREEN_INDEX, 'this is not json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a JSON object/');
        \CheckRootRows::derive($this->tmpDir);
    }

    public function testAMissingSeedFileRefusesToCertify(): void
    {
        @mkdir($this->tmpDir . '/docs', 0o777, true);
        @mkdir($this->tmpDir . '/prompt_kit', 0o777, true);
        file_put_contents($this->tmpDir . '/README.md', self::GREEN_README);
        file_put_contents($this->tmpDir . '/docs/MATCHUPS.md', self::GREEN_MATCHUPS);
        file_put_contents($this->tmpDir . '/docs/index.html', self::GREEN_INDEX);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/seed file not found/');
        \CheckRootRows::derive($this->tmpDir);
    }

    public function testAnUnknownFileKeyRefusesToCertify(): void
    {
        $this->writeFixture(self::GREEN_README, self::GREEN_MATCHUPS, self::GREEN_INDEX, '{"slugs":{"sugar-demo":{"matchups2":{"needles":["' . self::NEEDLE . '"]}}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown file key/');
        \CheckRootRows::derive($this->tmpDir);
    }

    public function testARevertedReadmeRowNamesTheSlugAndTheMissingNeedle(): void
    {
        $reverted = str_replace(self::NEEDLE, self::STALE_WORDING, self::GREEN_README);
        $this->writeFixture($reverted, self::GREEN_MATCHUPS, self::GREEN_INDEX, $this->seedArming(['readme']));

        $this->assertSame(
            ['FAIL readme sugar-demo: absent: ' . self::NEEDLE],
            $this->deriveFixture(),
            'a reverted row must name itself instead of passing vacuously',
        );
    }

    public function testADeadPhrasePlantedInItsOwnRowIsCaught(): void
    {
        $poisoned = str_replace(self::NEEDLE, self::NEEDLE . ' ' . self::DEAD . '.', self::GREEN_README);
        $this->writeFixture($poisoned, self::GREEN_MATCHUPS, self::GREEN_INDEX, $this->seedArming(['readme']));

        $this->assertSame(
            ['FAIL readme sugar-demo: dead-present: ' . self::DEAD],
            $this->deriveFixture(),
        );
    }

    public function testADeadPhraseOutsideItsOwnRowStaysInvisible(): void
    {
        $poisoned = self::GREEN_MATCHUPS . '| **SugarOther** | `sugar-other/` | port | ' . self::DEAD . ". |\n";
        $this->writeFixture(self::GREEN_README, $poisoned, self::GREEN_INDEX, $this->seedArming(['matchups']));

        $this->assertSame([], $this->deriveFixture(), 'dead phrases are row-scoped, never whole-file');
    }

    public function testAMissingRowAndADuplicatedRowAreBothFailClosed(): void
    {
        $seed = '{"slugs":{"sugar-demo":{"readme":{"needles":["' . self::NEEDLE . '"]}},"sugar-ghost":{"readme":{"needles":["Anything."]}}}}';
        $this->writeFixture(self::GREEN_README . self::GREEN_README, self::GREEN_MATCHUPS, self::GREEN_INDEX, $seed);

        $findings = $this->deriveFixture();

        $this->assertContains('FAIL readme sugar-demo: ambiguous: sugar-demo', $findings);
        $this->assertContains('FAIL readme sugar-ghost: missing: sugar-ghost', $findings);
    }

    public function testTheIndexAnchorOnlyReadsLibCardTilesNotDecoyHrefs(): void
    {
        $revertedCard = '<a class="lib-card" href="lib/sugar-demo.html"><h3>SugarDemo</h3><p class="summary">' . self::STALE_WORDING . "</p></a>\n";
        $index = "<img src=\"icons/sugar-demo.png\" data-href=\"lib/sugar-demo.html\">\n" . $revertedCard;
        $this->writeFixture(self::GREEN_README, self::GREEN_MATCHUPS, $index, $this->seedArming(['index']));

        $this->assertSame(
            ['FAIL index sugar-demo: absent: ' . self::NEEDLE],
            $this->deriveFixture(),
            'a bare-href decoy outside the card must not certify the wrong row',
        );

        $this->writeFixture(self::GREEN_README, self::GREEN_MATCHUPS, self::GREEN_INDEX, $this->seedArming(['index']));
        $this->assertSame([], $this->deriveFixture(), 'the intact card must pass');
    }

    public function testAnIndexCardWithoutASummaryParagraphFailsClosed(): void
    {
        $index = '<a class="lib-card" href="lib/sugar-demo.html"><h3>SugarDemo</h3></a>' . "\n"
            . '<a class="lib-card" href="lib/sugar-next.html"><p class="summary">The next card owns this summary.</p></a>' . "\n";
        $seed = '{"slugs":{"sugar-demo":{"index":{"needles":["' . self::NEEDLE . '"]}}}}';
        $this->writeFixture(self::GREEN_README, self::GREEN_MATCHUPS, $index, $seed);

        $this->assertContains('FAIL index sugar-demo: no-summary: sugar-demo', $this->deriveFixture());
    }

    public function testTheRosterIsHeldSetEqualInBothDirections(): void
    {
        $this->writeFixture(self::GREEN_README, self::GREEN_MATCHUPS, self::GREEN_INDEX, '{"slugs":{"sugar-phantom":{"readme":{"needles":["Nothing."]}}}}');

        $findings = $this->deriveFixture();

        $this->assertContains('FAIL roster sugar-demo: not-in-seed: sugar-demo', $findings);
        $this->assertContains('FAIL roster sugar-phantom: not-in-readme: sugar-phantom', $findings);
        $this->assertContains('FAIL readme sugar-phantom: missing: sugar-phantom', $findings);
    }

    public function testWhitespaceReflowWithinARowIsTolerated(): void
    {
        $reflowed = str_replace('Demo row wording fresh.', 'Demo   row  wording   fresh.', self::GREEN_README);
        $this->writeFixture($reflowed, self::GREEN_MATCHUPS, self::GREEN_INDEX, $this->seedArming(['readme']));

        $findings = $this->deriveFixture();

        $this->assertSame([], $findings, 'run-together whitespace inside the located row is the same text');
    }

    /**
     * The staleness arm (zz-guard-proposal.md :39): a seeded dead phrase that no
     * root file prints any more is a retirement candidate, noted and never red.
     *
     * THREE LEGS, because the arm can fail in three directions. On the real tree
     * it must name the current vestigial set EXACTLY — four entries today (the
     * git-server index row plus the three panels rows), with sugar-image/matchups
     * 'Picker facade' correctly silent because docs/index.html still prints it; a
     * seed or root-doc edit that changes that set reddens this pin, which is how
     * the arm stays honest instead of decorative. On a planted fixture it must
     * NAME the vestigial phrase — deleting the loop would otherwise pass every
     * equality below vacuously — while derive() stays empty, pinning the
     * never-red polarity. And a phrase that survives only in an unseeded
     * neighbour row must NOT be noted, pinning the whole-file liveness rule that
     * distinguishes a fossil from a guard that still has work to do.
     */
    public function testADeadPhraseAbsentFromEveryRootFileIsNotedForRetirement(): void
    {
        $notes = \CheckRootRows::advisories(dirname(__DIR__, 2));
        $this->assertSame([
            'note: index candy-git-server: retire-dead: ' . self::GIT_DAEMON_DEAD,
            'note: readme sugar-panels: retire-dead: ' . self::DEAD,
            'note: matchups sugar-panels: retire-dead: ' . self::DEAD,
            'note: index sugar-panels: retire-dead: ' . self::DEAD,
        ], $notes, 'the vestigial dead-phrase set is a drift pin: retire the seed row or heal the wording, in this commit');
        $this->assertSame([], \CheckRootRows::derive(dirname(__DIR__, 2)), 'an advisory is never a finding');

        $this->writeFixture(self::GREEN_README, self::GREEN_MATCHUPS, self::GREEN_INDEX, $this->seedArming(['readme']));
        $this->assertSame(
            ['note: readme sugar-demo: retire-dead: ' . self::DEAD],
            \CheckRootRows::advisories($this->tmpDir),
            'a dead phrase absent everywhere must be named, not quietly ignored',
        );
        $this->assertSame([], $this->deriveFixture(), 'the vestigial arm cannot redden the run');

        $live = self::GREEN_MATCHUPS . '| **SugarOther** | `sugar-other/` | port | ' . self::DEAD . ". |\n";
        $this->writeFixture(self::GREEN_README, $live, self::GREEN_INDEX, $this->seedArming(['readme']));
        $this->assertSame(
            [],
            \CheckRootRows::advisories($this->tmpDir),
            'a phrase still printed anywhere stays a live guard',
        );
        $this->assertSame([], $this->deriveFixture());
    }

    private function seedArming(array $files): string
    {
        $claims = [];
        foreach ($files as $file) {
            $claims[$file] = ['needles' => [self::NEEDLE], 'dead' => $file === 'readme' ? [self::DEAD] : []];
        }

        return (string) json_encode(['slugs' => ['sugar-demo' => $claims]]);
    }

    /**
     * @param array<int, string> $files
     */
    private function writeFixture(string $readme, string $matchups, string $index, string $seed): void
    {
        @mkdir($this->tmpDir . '/docs', 0o777, true);
        @mkdir($this->tmpDir . '/prompt_kit', 0o777, true);
        file_put_contents($this->tmpDir . '/README.md', $readme);
        file_put_contents($this->tmpDir . '/docs/MATCHUPS.md', $matchups);
        file_put_contents($this->tmpDir . '/docs/index.html', $index);
        file_put_contents($this->tmpDir . '/prompt_kit/root-rows-seed.json', $seed);
    }

    /**
     * @return list<string>
     */
    private function deriveFixture(): array
    {
        return \CheckRootRows::derive($this->tmpDir);
    }
}
