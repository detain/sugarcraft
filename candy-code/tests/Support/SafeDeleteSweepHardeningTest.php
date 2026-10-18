<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Commands\ShareCommand;
use SugarCraft\Code\Sessions\BackgroundSupervisor;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\Support\SafeDelete;
use SugarCraft\Code\Support\ToolIpcFiles;

/**
 * ROOTFIX lane: the sweep/prune family must never aim at '/', never follow a
 * planted symlink out of its base, and never empty a directory it cannot
 * prove it created.
 *
 * The 2026-10 root-run forensics (fix-1017 rootscan) found the failures this
 * replaces: `is_dir()`-follows-links recursion deleting the TARGET'S CONTENTS
 * of an escape planted inside a worktree, uid checks waving '/' through under
 * root (root owns it), and courtesy prunes unloading whatever sat at a
 * sandbox path regardless of who put it there. Each routine here gets a
 * positive control — a foreign link that SURVIVES and a stale regular file
 * that still goes — so neutering the guard reddens exactly that pin, and the
 * creator-side sentinel gates the two prunes that empty whole directories
 * (the suite home sandbox and stale IPC dirs).
 */
final class SafeDeleteSweepHardeningTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/sc_safedelete_' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::purgeOwnFixtureTree($this->base);
    }

    public function testTheBaseGateRefusesEmptyTheRootAndSelfContainedPaths(): void
    {
        $this->assertFalse(SafeDelete::baseIsSane(''));
        $this->assertFalse(SafeDelete::baseIsSane('/'), 'the root is never a sweep base, even though under root its uid check would pass');
        $this->assertFalse(SafeDelete::baseIsSane($this->base . '/nowhere'));

        mkdir($this->base . '/child');
        $this->assertTrue(SafeDelete::baseIsSane($this->base . '/child'));

        // A symlink AT the base position resolves to its target — a link to
        // '/' is '/' for the purposes of this gate, and is refused.
        symlink('/', $this->base . '/root-link');
        $this->assertFalse(SafeDelete::baseIsSane($this->base . '/root-link'));
    }

    public function testLinksEscapingTheBaseAndDanglingLinksAreNotDeletable(): void
    {
        $outside = sys_get_temp_dir() . '/sc_safedelete_outside_' . bin2hex(random_bytes(6));
        mkdir($outside, 0o700, true);
        file_put_contents($outside . '/victim.txt', 'do not delete me');

        file_put_contents($this->base . '/plain.txt', 'mine');
        symlink($outside . '/victim.txt', $this->base . '/escape.txt');
        symlink($this->base . '/plain.txt', $this->base . '/inner.txt');
        symlink($outside . '/never-existed', $this->base . '/dangling.txt');

        $this->assertTrue(SafeDelete::deletable($this->base . '/plain.txt', $this->base));
        $this->assertTrue(SafeDelete::deletable($this->base . '/inner.txt', $this->base), 'a link that stays inside is ours');
        $this->assertFalse(SafeDelete::deletable($this->base . '/escape.txt', $this->base), 'the planted escape is the shape this predicate exists for');
        $this->assertFalse(SafeDelete::deletable($this->base . '/dangling.txt', $this->base), 'a link that resolves nowhere proves nothing');

        self::purgeOwnFixtureTree($outside);
    }

    public function testTheSentinelIsStampedOnlyBySaneBasesAndReadBackStrictly(): void
    {
        $this->assertFalse(SafeDelete::stampSentinel('/'), 'refused stamp keeps the later prune locked out');
        $this->assertFalse(SafeDelete::carriesSentinel('/'));
        $this->assertFalse(SafeDelete::carriesSentinel($this->base));

        $this->assertTrue(SafeDelete::stampSentinel($this->base));
        $this->assertTrue(SafeDelete::carriesSentinel($this->base));

        // A planted LINK wearing the marker name proves nothing: carries()
        // wants a plain file there.
        unlink($this->base . '/' . SafeDelete::SENTINEL);
        symlink('/etc/hostname', $this->base . '/' . SafeDelete::SENTINEL);
        $this->assertFalse(SafeDelete::carriesSentinel($this->base . '/'));
    }

    public function testTheExportSweepPrunesPlainStaleFilesAndSurvivesTheEscape(): void
    {
        $stale = time() - 172800;
        $outside = $this->base . '_outside_victim.txt';
        file_put_contents($outside, 'export sweep must not reach me');
        file_put_contents($this->base . '/old-export.md', 'gone soon');
        touch($this->base . '/old-export.md', $stale);
        symlink($outside, $this->base . '/escape-export.md');
        symlink($this->base . '/never-exists', $this->base . '/dangling-export.md');
        // (no touch() on links: it FOLLOWS, updating or even creating the
        // target — the sweep skips them by is_link long before age is asked)

        $removed = ShareCommand::sweepExpiredExports(1, time(), $this->base);

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($this->base . '/old-export.md');
        $this->assertFileExists($this->base . '/escape-export.md', 'the link name is foreign-shaped: the sweep leaves it alone');
        // is_link, NOT assertFileExists — file_exists() resolves through the
        // link and a DANGLING one answers false whatever the sweep did.
        $this->assertTrue(is_link($this->base . '/dangling-export.md'));
        $this->assertFileExists($outside);
        unlink($outside);
    }

    public function testTheExportSweepRefusesARootBaseEntirely(): void
    {
        $this->assertSame(0, ShareCommand::sweepExpiredExports(1, time(), '/'));
    }

    public function testSessionPruneAbortsAroundAPlantedEscapeAndRemovesCleanTrees(): void
    {
        $home = $this->base . '/home';
        mkdir($home . '/subagents/gone-escape', 0o700, true);
        mkdir($home . '/subagents/gone-clean', 0o700, true);
        $old = time() - 7200;
        file_put_contents($home . '/subagents/gone-escape/run.jsonl', '{}');
        file_put_contents($home . '/subagents/gone-clean/run.jsonl', '{}');
        // newestMtime reads the SESSION DIRECTORY itself first: a dir created
        // this second is inside the 3600s grace window and would be skipped
        // for that reason alone, silently vacuating this pin.
        touch($home . '/subagents/gone-escape/run.jsonl', $old);
        touch($home . '/subagents/gone-clean/run.jsonl', $old);
        touch($home . '/subagents/gone-clean', $old);
        // The victim sits OUTSIDE the containment root ($root = subagents):
        // a link into home-but-outside-the-root must also abort, but a link
        // whose target is inside $root is legitimately ours to sweep.
        $victimDir = $this->base . '/kept';
        mkdir($victimDir, 0o700, true);
        $victim = $victimDir . '/subagents-kept.txt';
        file_put_contents($victim, 'outside the sweep root');
        symlink($victim, $home . '/subagents/gone-escape/escape.txt');
        touch($home . '/subagents/gone-escape', $old);

        $removed = AgentManager::pruneSessionArtifacts(static fn (): bool => false, $home);

        $this->assertSame(1, $removed, 'the clean tree goes; the tree carrying an escape is left whole');
        $this->assertDirectoryDoesNotExist($home . '/subagents/gone-clean');
        $this->assertDirectoryExists($home . '/subagents/gone-escape');
        $this->assertFileExists($home . '/subagents/gone-escape/run.jsonl', 'an abort leaves the whole tree, not half of it');
        $this->assertFileExists($home . '/subagents/gone-escape/escape.txt');
        $this->assertFileExists($victim);
    }

    public function testTheMediaSweepPrunesPlainDirsAndSurvivesTheEscape(): void
    {
        $old = time() - 172800;
        mkdir($this->base . '/sess-clean', 0o700, true);
        file_put_contents($this->base . '/sess-clean/frame.png', 'x');
        touch($this->base . '/sess-clean/frame.png', $old);
        touch($this->base . '/sess-clean', $old);
        mkdir($this->base . '/sess-escape', 0o700, true);
        file_put_contents($this->base . '/sess-escape/frame.png', 'x');
        touch($this->base . '/sess-escape/frame.png', $old);
        // Victim outside the containment root ($mediaRoot): the escape this
        // predicate exists to catch. A link back INTO the root stays
        // deletable-by-design, so it is not planted here.
        $outsideDir = $this->base . '_media_outside';
        mkdir($outsideDir, 0o700, true);
        $victim = $outsideDir . '/media-victim.png';
        file_put_contents($victim, 'do not touch');
        symlink($victim, $this->base . '/sess-escape/escape.png');
        touch($this->base . '/sess-escape', $old);

        $removed = MediaStore::sweepExpiredDirs(1, time(), $this->base);

        $this->assertSame(1, $removed);
        $this->assertDirectoryDoesNotExist($this->base . '/sess-clean');
        $this->assertDirectoryExists($this->base . '/sess-escape');
        $this->assertFileExists($this->base . '/sess-escape/frame.png');
        $this->assertFileExists($victim);

        $this->assertSame(0, MediaStore::sweepExpiredDirs(1, time(), '/'));

        self::purgeOwnFixtureTree($outsideDir);
    }

    public function testTheToolIpcSweepPrunesPlainFilesAndRefusesTheRoot(): void
    {
        $old = time() - 7200;
        $staleName = ToolIpcFiles::RUNTIME_PREFIX . 'hardeningprobe';
        file_put_contents($this->base . '/' . $staleName, 'stale payload');
        touch($this->base . '/' . $staleName, $old);
        $victim = $this->base . '/ipc-victim.txt';
        file_put_contents($victim, 'outside');
        $escapeName = ToolIpcFiles::RUNTIME_PREFIX . 'escape';
        symlink($victim, $this->base . '/' . $escapeName);

        $removed = ToolIpcFiles::sweep($this->base, 1);

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($this->base . '/' . $staleName);
        // The escape link never even reaches the containment check — lstat
        // says non-regular first — but it must survive either way, and the
        // target doubly so.
        $this->assertFileExists($this->base . '/' . $escapeName);
        $this->assertFileExists($victim);

        $this->assertSame(0, ToolIpcFiles::sweep('/', 1));
    }

    public function testStaleIpcDirsNeedTheCreatorSentinelBeforeBeingEmptied(): void
    {
        $old = time() - 172800;
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
        $name = BackgroundSupervisor::IPC_DIR_PREFIX . $uid . '_' . bin2hex(random_bytes(8));
        $root = $this->base . '/ip';
        mkdir($root, 0o700, true);

        $foreign = $root . '/' . $name;
        mkdir($foreign, 0o700, true);
        file_put_contents($foreign . '/buffer.txt', 'no sentinel — not ours');
        touch($foreign . '/buffer.txt', $old);
        touch($foreign, $old);

        // Second candidate in a SUBDIRECTORY: sweepStaleIpcDirs is one level
        // flat, so this decoy also pins that the scan does not recurse.
        $decoyDir = $root . '/deeper';
        mkdir($decoyDir, 0o700, true);
        $sentinelDir = $decoyDir . '/' . $name;
        mkdir($sentinelDir, 0o700, true);
        file_put_contents($sentinelDir . '/buffer.txt', 'sentinelled');
        touch($sentinelDir . '/buffer.txt', $old);
        $this->assertTrue(SafeDelete::stampSentinel($sentinelDir));
        // Backdate the marker too: the sweep's age test covers EVERY entry,
        // and a fresh sentinel would read the directory as alive.
        touch($sentinelDir . '/' . SafeDelete::SENTINEL, $old);
        touch($sentinelDir, $old);

        $removed = BackgroundSupervisor::sweepStaleIpcDirs($root, 1);

        $this->assertSame(0, $removed, 'nothing at the top level carries the marker yet: the sentinel-less dir is refused and the flat scan never reaches the nested one');

        // Now the pair the sweep acts on: a TOP-LEVEL sentinelled dir.
        $topName = BackgroundSupervisor::IPC_DIR_PREFIX . $uid . '_' . bin2hex(random_bytes(8));
        $top = $root . '/' . $topName;
        mkdir($top, 0o700, true);
        file_put_contents($top . '/buffer.txt', 'sentinelled top-level');
        touch($top . '/buffer.txt', $old);
        $this->assertTrue(SafeDelete::stampSentinel($top));
        touch($top . '/' . SafeDelete::SENTINEL, $old);
        touch($top, $old);

        $removed = BackgroundSupervisor::sweepStaleIpcDirs($root, 1);

        $this->assertSame(1, $removed, 'the top-level sentinelled dir goes');
        $this->assertDirectoryDoesNotExist($top);
        $this->assertDirectoryExists($foreign, 'no sentinel: the whole directory stays, entries and all');
        $this->assertDirectoryExists($sentinelDir, 'flat scan: below the swept root nothing is touched');

        $this->assertSame(0, BackgroundSupervisor::sweepStaleIpcDirs('/', 1));
    }

    public function testTheCourtesyPruneIsOneHelperWithTheSentinelGateAheadOfTheWalk(): void
    {
        $bootstrap = (string) file_get_contents(\dirname(__DIR__) . '/bootstrap.php');
        $helper = (string) file_get_contents(__DIR__ . '/SuiteHomeSandbox.php');
        self::assertNotSame('', $helper, 'fixture: the prune helper could not be read, so this scan is void');

        // F-1 (rv7): the walk left bootstrap.php so the pin can drive it
        // behaviorally; a second inline copy coming back is the drift this
        // scan exists to catch. The creator stamps stay write-once here.
        $this->assertStringContainsString('SuiteHomeSandbox::pruneCourtesy($homeSandbox', $bootstrap, 'the home-sandbox prune stopped routing through the helper');
        $this->assertStringNotContainsString('$pruneStack = [', $bootstrap, 'a second inline prune walk reappeared in the bootstrap — the helper owns that loop now');
        $this->assertGreaterThanOrEqual(2, substr_count($bootstrap, 'stampSentinel'), 'both the sandbox root and the home sandbox carry creator stamps');

        // The ordering contract moved with the loop: gate before unlink, and
        // the sentinel is skipped by name (its survival is pinned
        // behaviorally in SuiteHomeSandboxTest, not by a string here).
        $gate = strpos($helper, 'carriesSentinel($base)');
        $walk = strpos($helper, '$pruneStack = [');
        $this->assertNotFalse($gate, 'the helper prune must consult the creator sentinel');
        $this->assertNotFalse($walk);
        $this->assertLessThan($walk, $gate, 'the prune walk must sit BEHIND the sentinel gate, not beside it');
        $this->assertStringContainsString('$pruneEntry === SafeDelete::SENTINEL', $helper);
    }

    public function testTheTeamManagerFixtureDeleterChecksLinksFirst(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__) . '/Agents/TeamManagerTest.php');

        $this->assertStringNotContainsString(
            'is_dir($path) ? $this->removeDirectory($path) : unlink($path);',
            $source,
            'the old ternary asked is_dir(), which FOLLOWS links into the target tree (rootscan H12)',
        );
        $linkArm = strpos($source, 'if (is_link($path) || is_file($path)) {');
        $dirArm = strpos($source, 'if (is_dir($path)) {');
        $this->assertNotFalse($linkArm);
        $this->assertNotFalse($dirArm);
        $this->assertLessThan($dirArm, $linkArm, 'is_link must be tested before is_dir is ever asked');
    }

    public function testTheWorktreeRemoverCarriesBaseSanityDepthAndContainment(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Agents/WorktreeManager.php');

        $this->assertStringContainsString('int $depth = SafeDelete::MAX_DEPTH', $source, 'the recursive emptying is depth-bounded');
        $this->assertStringContainsString('$depth <= 0 || !SafeDelete::baseIsSane($path)', $source);
        $this->assertStringContainsString('removeDirectory($itemPath, $depth - 1)', $source);
        $this->assertStringContainsString('!SafeDelete::deletable($itemPath, $path) || !@unlink($itemPath)', $source, 'unlink-else arm is containment-checked first');
    }

    public function testLegacyMigrationRefusesEscapeShapedLegacyLinks(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/LegacyMigration.php');

        $this->assertStringContainsString('is_link($from) && !SafeDelete::deletable($from, $home)', $source);
        $this->assertStringContainsString('is_link($to) && !SafeDelete::deletable($to, $home)', $source);
    }

    /**
     * This file's OWN cleanup: is_link first, unlink names, recurse dirs.
     * Named uniquely against DuplicatedTestHelperDrift's cross-file scan.
     */
    private static function purgeOwnFixtureTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }
            self::purgeOwnFixtureTree($path);
        }
        @rmdir($dir);
    }
}
