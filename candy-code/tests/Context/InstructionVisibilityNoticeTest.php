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
 * announced again — including one back to the very bytes first said, which
 * is the revert-clear's whole point, and two fresh detections of the SAME
 * fingerprint (the unreadable leg twice), which is its guard's early return.
 * (c) The said-state is the loader's, and the tracker registers with it at
 * capture: the row crosses a forked turn child on the envelope's fifth key,
 * {@see InstructionFileLoader::ANNOUNCED_UPDATES_KEY}, so the parent's
 * un-advanced diff state (C-CTX-1's law) re-detects and re-renders the
 * model's diff every turn WITHOUT re-spitting the human's row (round 1 of
 * the step, the lead-accepted in-step carry).
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
     * The MAJOR carry (round 1): the said-state crosses the fork on the
     * envelope's fifth key. The parent tracker models C-CTX-1's law — frozen
     * before the edit, its $seen deliberately never advanced — so its next
     * recheck is a FRESH detection whose section must still render for the
     * model; what must NOT happen again is the human's row, because the
     * turn child's merge-back left the fingerprint on the parent's loader.
     * Without the fifth envelope key this reds: the carried wire has no
     * update ledger, the parent's guard sees nothing, and the row repeats
     * once per turn for the rest of the session.
     */
    public function testTheAnnouncedUpdateRowCrossesTheForkOnTheFifthEnvelopeKey(): void
    {
        $childLoader = $this->trackerFixture();
        $parentLoader = new InstructionFileLoader($this->repo, [], null, $this->personal);
        $child = InstructionDiffTracker::capture($childLoader);
        $parent = InstructionDiffTracker::capture($parentLoader);

        $edited = "Alpha rule\nBeta rule\nGamma rule\n";
        file_put_contents($this->claude, $edited);
        clearstatcache();

        $childSection = $child->recheck();
        self::assertNotSame('', $childSection, 'fixture: the change reached the model-facing section');
        self::assertSame(
            [Lang::t('chat.instructions.updated', ['file' => 'CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
            'fixture: the first child said the row',
        );

        $childLedger = $childLoader->instructionUpdateLedger();
        self::assertCount(1, $childLedger, 'fixture: the announce went through to the loader');
        self::assertSame([sha1($edited)], array_values($childLedger), 'the fingerprint is the new content hash');

        $wire = json_decode(json_encode((new Read(instructionLoader: $childLoader))->exportSessionState()), true);
        self::assertArrayHasKey(InstructionFileLoader::ANNOUNCED_UPDATES_KEY, $wire, 'the ledger rides the envelope');
        self::assertCount(1, $wire[InstructionFileLoader::ANNOUNCED_UPDATES_KEY]);
        (new Read(instructionLoader: $parentLoader))->mergeSessionState($wire);
        self::assertSame(
            $childLoader->instructionUpdateLedger(),
            $parentLoader->instructionUpdateLedger(),
            'fixture: the merge landed the same path => fingerprint on the parent',
        );

        $parentSection = $parent->recheck();
        self::assertSame($childSection, $parentSection, 'the model half still replays byte-identically');
        self::assertSame(
            [],
            RuntimeNoticeSink::drain(),
            'the carried ledger silences the human half: one row per path per session on the fork path too',
        );
    }

    /**
     * Round 1 MINOR pin (the revert-clear leg): a revert to the frozen bytes
     * forgets the row, so an edit BACK to the very bytes first announced is
     * said again. Deleting the revert leg's unset would leave the stale
     * fingerprint and the guard would swallow this row — the existing
     * different-bytes re-edit leg cannot tell the difference.
     */
    public function testARevertThenTheSameBytesAgainIsSaidAgain(): void
    {
        $tracker = InstructionDiffTracker::capture($this->trackerFixture());

        $first = "Alpha rule\nBeta rule\nGamma rule\n";
        file_put_contents($this->claude, $first);
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
        self::assertSame([], RuntimeNoticeSink::drain());

        file_put_contents($this->claude, $first);
        clearstatcache();
        self::assertNotSame('', $tracker->recheck());
        self::assertSame(
            [Lang::t('chat.instructions.updated', ['file' => 'CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
            'the same bytes AGAIN after a revert are a new change and are said — the clear is load-bearing',
        );
    }

    /**
     * Round 1 MINOR pin (the guard's early return): two consecutive FRESH
     * detections of one fingerprint say the row once. The unreadable leg is
     * the deterministic driver — chmod 0 after a touch, twice, the size
     * unchanged, so `unreadable:size` is byte-equal across the two — and the
     * guard's line is what keeps the second silent. A different fingerprint
     * afterwards must still be said, so the silence is the fingerprint, not
     * a one-way latch. (The runner here is a plain user: chmod 0 makes the
     * file genuinely unreadable, and the tracker only ever stats it.)
     */
    public function testTwoFreshDetectionsOfOneFingerprintSayTheRowOnce(): void
    {
        $tracker = InstructionDiffTracker::capture($this->trackerFixture());

        chmod($this->claude, 0o644);
        touch($this->claude, time() + 11);
        chmod($this->claude, 0);
        clearstatcache();
        self::assertNotSame('', $tracker->recheck(), 'fixture: the unreadable change reached the section');
        self::assertSame(
            [Lang::t('chat.instructions.updated', ['file' => 'CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
            'the first unreadable detection is said',
        );

        chmod($this->claude, 0o644);
        touch($this->claude, time() + 22);
        chmod($this->claude, 0);
        clearstatcache();
        self::assertNotSame('', $tracker->recheck(), 'the second detection re-renders the section for the model');
        self::assertSame(
            [],
            RuntimeNoticeSink::drain(),
            'but the same fingerprint is one row — the guard early-returns',
        );

        chmod($this->claude, 0o644);
        file_put_contents($this->claude, "Alpha rule\nBeta rule\nZeta rule the third\n");
        clearstatcache();
        self::assertNotSame('', $tracker->recheck());
        self::assertSame(
            [Lang::t('chat.instructions.updated', ['file' => 'CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
            'a different fingerprint is still said after the silence',
        );
    }

    /**
     * The ledger's loader half: union, last-wins per path, record/forget,
     * and fail-soft per row — a hostile or stale-shaped entry costs the row,
     * never the merge.
     */
    public function testTheUpdateLedgerMarksAreAUnionLastWinsAndFailSoft(): void
    {
        $this->trackerFixture();
        $loader = new InstructionFileLoader($this->repo, [], null, $this->personal);
        $path = '/fixture' . uniqid('-ctx2f-', true);

        $loader->markInstructionUpdates([
            json_encode([$path, 'first']),
            'not-json',
            12.5,
            [],
            json_encode([$path . '/truncated']),
            json_encode(['', 'empty-path-is-skipped']),
            json_encode([$path, 'second']),
        ]);
        self::assertSame(
            [[$path, 'second']],
            array_map(json_decode(...), $loader->announcedInstructionUpdates()),
            'one entry for the path, last-wins; every malformed row skipped without throwing',
        );
        self::assertSame('second', $loader->instructionUpdateFingerprint($path));

        $loader->recordInstructionUpdate($path, 'third');
        self::assertSame('third', $loader->instructionUpdateFingerprint($path), 'record is the tracker write-through');
        $loader->forgetInstructionUpdate($path);
        self::assertSame([], $loader->announcedInstructionUpdates(), 'forget is the revert-clear write-through');
        self::assertNull($loader->instructionUpdateFingerprint($path));
    }

    /**
     * Round 2 MINOR-1 (the loader-side leg): once-per-path is the LOADER
     * key's law, not only the tracker capture's. Two trackers captured on
     * ONE loader before the edit — the shape a per-turn recapture against
     * the session loader takes — both hold an empty own view at announce
     * time; the first's row rides the loader through
     * {@see InstructionFileLoader::instructionUpdateFingerprint()}'s live
     * read and silences the second while the model half still renders.
     * Deleting that guard disjunct reddens exactly here: the fork-carry pin
     * cannot catch it (its parent view is silenced through the same line
     * only because the merge landed before the recheck — here there is no
     * wire at all, only the shared loader).
     */
    public function testTheSecondTrackerOnOneLoaderIsSilencedByTheLoaderKey(): void
    {
        $loader = $this->trackerFixture();
        $first = InstructionDiffTracker::capture($loader);
        $second = InstructionDiffTracker::capture($loader);

        file_put_contents($this->claude, "Alpha rule\nBeta rule\nGamma rule\n");
        clearstatcache();

        $section = $first->recheck();
        self::assertNotSame('', $section, 'fixture: the first fresh detection reached the section');
        self::assertSame(
            [Lang::t('chat.instructions.updated', ['file' => 'CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
            'fixture: the first tracker said the row, through the shared loader',
        );

        $again = $second->recheck();
        self::assertSame($section, $again, 'the second tracker re-detects: the model half renders again');
        self::assertSame(
            [],
            RuntimeNoticeSink::drain(),
            'the second announce consults the loader key and says nothing — 1 row to 0 on the shared ledger',
        );
    }

    /**
     * Round 2 MINOR-2 (the merge law, pinned as it IS): the ledger is a
     * path => fingerprint map with NO counter — a fresh row announced in
     * the parent view and an updated row for the same path announced in the
     * child view merge to ONE entry, the child's, by
     * {@see InstructionFileLoader::markInstructionUpdates()}'s last-wins
     * write. Not a sum, not a stack, not a max of hits: re-merging the same
     * wire is a no-op, so the entry count is topology-independent.
     */
    public function testMergingAParentFreshRowAndAChildUpdateIsOneEntryLastWins(): void
    {
        $parentLoader = $this->trackerFixture();
        $parent = InstructionDiffTracker::capture($parentLoader);

        $one = "Alpha rule\nBeta rule\nGamma rule\n";
        file_put_contents($this->claude, $one);
        clearstatcache();
        self::assertNotSame('', $parent->recheck(), 'fixture: the parent detected the change');
        self::assertSame(
            [Lang::t('chat.instructions.updated', ['file' => 'CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
            'fixture: the parent view holds the fresh row',
        );
        self::assertSame([$this->claude => sha1($one)], $parentLoader->instructionUpdateLedger());

        $childLoader = new InstructionFileLoader($this->repo, [], null, $this->personal);
        $child = InstructionDiffTracker::capture($childLoader);
        $two = "Alpha rule\nBeta rule\nGamma rule\nDelta rule\n";
        file_put_contents($this->claude, $two);
        clearstatcache();
        self::assertNotSame('', $child->recheck(), 'fixture: the child detected a further change');
        self::assertSame(
            [Lang::t('chat.instructions.updated', ['file' => 'CLAUDE.md'])],
            RuntimeNoticeSink::drain(),
            'fixture: the child view holds the updated row for the same path',
        );

        $wire = json_decode(json_encode((new Read(instructionLoader: $childLoader))->exportSessionState()), true);
        (new Read(instructionLoader: $parentLoader))->mergeSessionState($wire);
        self::assertSame(
            [$this->claude => sha1($two)],
            $parentLoader->instructionUpdateLedger(),
            'the merge takes the child row for the path — replaced, not added: one entry, no counter',
        );
        self::assertCount(1, $parentLoader->announcedInstructionUpdates(), 'the export stays one pair string for the path');

        (new Read(instructionLoader: $parentLoader))->mergeSessionState($wire);
        self::assertSame(
            [$this->claude => sha1($two)],
            $parentLoader->instructionUpdateLedger(),
            're-merging the same wire changes nothing — the union is not additive',
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
