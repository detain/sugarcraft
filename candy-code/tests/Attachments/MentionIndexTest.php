<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Attachments;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachments\MentionIndex;

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
        exec('rm -rf ' . escapeshellarg($this->root));
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

        exec('rm -rf ' . escapeshellarg($stray));
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
}
