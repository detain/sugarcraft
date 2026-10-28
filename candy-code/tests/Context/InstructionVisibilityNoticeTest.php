<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\InstructionDiffTracker;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tools\BuiltIn\Read;

/**
 * C-CTX-2.f: the two silent instruction events now have transcript rows.
 *
 * (a) The FIRST mid-session load of a nested instruction file records one
 * `Instruction file loaded: <path>` row on the same once-per-session gate as
 * the load itself — the emitted-path mark, which is the first key of the
 * Read/Glob/Grep session-state envelope, so a forked tool child's row is not
 * repeated by the next child for the same path. A refused or deferred
 * candidate never gets a loaded row; it says its refusal row instead.
 *
 * (b) A NEWLY DETECTED change in {@see InstructionDiffTracker::recheck()}
 * records one `Instructions updated: <name>` row; re-rendering a change the
 * tracker already announced (the per-step replay leg) stays silent, and a
 * revert to the frozen bytes clears the file's row so a later edit is
 * announced again.
 *
 * The rows ride {@see RuntimeNoticeSink} into the transcript through the same
 * pump the refusal rows already use; an unarmed sink (a bare `-p` run) drops
 * them, which is the accepted behavior recorded with the step.
 */
final class InstructionVisibilityNoticeTest extends TestCase
{
    private string $sandbox;

    private string $repo;

    private string $personal;

    private string $claude;

    private string|false $errorLog = false;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-ctx2f-' . bin2hex(random_bytes(6));
        $this->repo = $this->sandbox . '/repo';
        mkdir($this->repo . '/src', 0700, true);
        file_put_contents($this->repo . '/src/x.php', "<?php\n");

        // A fixture git so the loader's ancestor walk stops exactly here for
        // the tracker legs, same as the existing turn-context pins.
        foreach ([['init', '-q'], ['config', 'user.email', 'fixture@example.invalid'], ['config', 'user.name', 'Fixture'], ['config', 'commit.gpgsign', 'false']] as $argv) {
            exec('git -C ' . escapeshellarg($this->repo) . ' ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $this->markTestSkipped('git is unavailable: ' . implode("\n", $out));
            }
        }

        // No root instruction file exists yet at this point: the loaded-row
        // legs keep it that way, so the first on-touch load of a nested file
        // says exactly its own row. The tracker legs add the root files
        // themselves (see trackerFixture()).

        // RuntimeNoticeSink::warn() also writes to the error log (the refusal
        // leg of the negative-control test); keep that off the runner.
        $this->errorLog = ini_get('error_log');
        ini_set('error_log', $this->sandbox . '/error.log');

        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(false);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        ini_set('error_log', $this->errorLog === false ? '' : $this->errorLog);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->sandbox);
    }

    public function testTheFirstTouchOfANestedInstructionFileSaysItLoadedOnce(): void
    {
        file_put_contents($this->repo . '/src/CLAUDE.md', "# src rules\n");
        file_put_contents($this->repo . '/src/y.php', "<?php\n");
        $loader = new InstructionFileLoader($this->repo);

        $content = $loader->loadForPath($this->repo . '/src/x.php');

        self::assertStringContainsString('# src rules', (string) $content, 'fixture: the nested file was injected');
        self::assertSame(
            [Lang::t('chat.instructions.loaded', ['file' => 'src/CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
        );

        // Every later touch under the same directory — including the very
        // same file again — rides the emitted-path gate and says nothing.
        self::assertNull($loader->loadForPath($this->repo . '/src/y.php'));
        self::assertNull($loader->loadForPath($this->repo . '/src/x.php'));
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    /**
     * The loaded row shares the emitted-path mark with the load itself, and
     * that mark is the first key of the session-state envelope: the parent
     * merge stops the next forked child from saying the row a second time.
     */
    public function testTheLoadedRowCrossesAForkedToolChildsSessionState(): void
    {
        file_put_contents($this->repo . '/src/CLAUDE.md', "# src rules\n");

        $child = new Read(instructionLoader: new InstructionFileLoader($this->repo));
        $child->execute(['file_path' => $this->repo . '/src/x.php']);
        self::assertSame(
            [Lang::t('chat.instructions.loaded', ['file' => 'src/CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
            'fixture: the child said the row',
        );

        $parentLoader = new InstructionFileLoader($this->repo);
        $parent = new Read(instructionLoader: $parentLoader);
        $parent->mergeSessionState(json_decode(json_encode($child->exportSessionState()), true));

        self::assertNull($parentLoader->loadForPath($this->repo . '/src/x.php'));
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    /** The negative control on the pair: a deferred file says its refusal, never a load. */
    public function testADeferredNestedInstructionFileSaysItsRefusalAndNeverItsLoad(): void
    {
        file_put_contents($this->repo . '/src/CLAUDE.md', str_repeat('x', InstructionFileLoader::MAX_DOCUMENT_BYTES + 1));
        $loader = new InstructionFileLoader($this->repo);

        $loader->loadForPath($this->repo . '/src/x.php');

        $notices = RuntimeNoticeSink::drain();
        self::assertCount(1, $notices, 'exactly one row: the refusal, not a load-and-refusal pair');
        self::assertStringStartsWith('Instruction file src/CLAUDE.md was left out', $notices[0]);
    }

    public function testAnEditToARootInstructionFileSaysItUpdatedOnceThenSilence(): void
    {
        $tracker = InstructionDiffTracker::capture($this->trackerFixture());

        self::assertSame('', $tracker->recheck(), 'fixture: an unmoved disk is neither a section nor a row');
        self::assertSame([], RuntimeNoticeSink::drain());

        file_put_contents($this->claude, "Alpha rule\nBeta rule\nGamma rule\n");
        clearstatcache();

        $section = $tracker->recheck();
        self::assertNotSame('', $section, 'fixture: the change reached the model-facing section');
        self::assertSame(
            [Lang::t('chat.instructions.updated', ['file' => 'CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
        );

        // Re-rendering the same state (the per-step replay leg) is silent.
        $again = $tracker->recheck();
        self::assertSame($section, $again, 'the replay stays byte-identical');
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    public function testARevertClearsTheRowAndALaterEditIsSaidAgain(): void
    {
        $tracker = InstructionDiffTracker::capture($this->trackerFixture());

        file_put_contents($this->claude, "Alpha rule\nBeta rule\nGamma rule\n");
        clearstatcache();
        self::assertNotSame('', $tracker->recheck());
        self::assertSame(
            [Lang::t('chat.instructions.updated', ['file' => 'CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
            'fixture: the first edit was said',
        );

        file_put_contents($this->claude, "Alpha rule\nBeta rule\n");
        clearstatcache();
        self::assertSame('', $tracker->recheck(), 'fixture: reverting to the frozen bytes cancels the section');
        self::assertSame([], RuntimeNoticeSink::drain(), 'the revert also clears the said-state');

        file_put_contents($this->claude, "Alpha rule\nBeta rule\nDelta rule\n");
        clearstatcache();
        self::assertNotSame('', $tracker->recheck());
        self::assertSame(
            [Lang::t('chat.instructions.updated', ['file' => 'CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
            'a later edit is a new change and is said again',
        );
    }

    /**
     * The tracker fixture shape from the existing turn-context pins: root
     * instruction files present, a pinned personal directory so no real
     * `~/.candy-code` note can leak in. Only CLAUDE.md is ever touched, so
     * each test owns exactly the rows it asserts.
     */
    private function trackerFixture(): InstructionFileLoader
    {
        $this->personal = $this->sandbox . '/personal';
        mkdir($this->personal, 0700, true);
        file_put_contents($this->personal . '/AGENTS.md', "Personal note one\nPersonal note two\n");

        $this->claude = $this->repo . '/CLAUDE.md';
        file_put_contents($this->claude, "Alpha rule\nBeta rule\n");
        file_put_contents($this->repo . '/AGENTS.md', "Agents rule one\nAgents rule two\n");

        return new InstructionFileLoader($this->repo, [], null, $this->personal);
    }
}
