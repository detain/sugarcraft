<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * The two properties {@see ToolIpcFiles} exists for: a forked tool child's
 * payload is readable only by its owner, and an abandoned one is eventually
 * removed even though the process that would have collected it was killed.
 *
 * Both were real leaks. The payload is a whole tool result — file bodies,
 * grep hits, fetched pages — and it was landing in `/tmp` at 0664 under the
 * ambient umask; and 582 abandoned `sc_chat_tool_*.json` files had piled up
 * on the machine this was found on, because the only unlink either dispatcher
 * had was the one on the collection path a cancel skips.
 */
final class ToolIpcFilesTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_ipc_files_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        // Every launch path reached from here walks the skill trees under HOME
        // (~/.claude/skills, ~/.config/opencode/skills, ~/.sugar-crush/skills),
        // so HOME is redirected at an empty sandbox -- both spellings, see
        // HomeSandboxTrait. Nothing here asserts on a skill roster today; the
        // point is that nothing here can start depending on the developer's.
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    // =========================================================================
    // Mode
    // =========================================================================

    /**
     * Under a deliberately wide-open umask, which is what makes this a
     * regression test rather than a restatement of the machine's defaults: a
     * plain `file_put_contents()` here produces 0666.
     */
    public function testWriteCreatesThePayloadReadableOnlyByItsOwner(): void
    {
        $file = $this->dir . '/payload.bin';

        $previous = umask(0o000);

        try {
            self::assertTrue(ToolIpcFiles::write($file, 'secret'));
        } finally {
            umask($previous);
        }

        self::assertSame('secret', file_get_contents($file));
        self::assertSame('0600', $this->mode($file));
    }

    /**
     * The `.partial` sibling is the file actually written — `rename()` only
     * carries its mode across — so it has to be private too, for the whole
     * window in which it holds the payload.
     */
    public function testTheIntermediatePartialFileIsPrivateToo(): void
    {
        $file = $this->dir . '/interrupted.bin';
        $partial = $file . '.partial';

        $previous = umask(0o000);

        try {
            // Stand in for a child SIGKILLed after the write and before the
            // rename: the same create path, stopped one step early.
            file_put_contents($partial, 'half');
            @unlink($partial);
            ToolIpcFiles::write($partial, 'half');
        } finally {
            umask($previous);
        }

        self::assertSame('0600', $this->mode($partial));
    }

    public function testWriteLeavesTheProcessUmaskAsItFoundIt(): void
    {
        $before = umask();
        ToolIpcFiles::write($this->dir . '/umask.bin', 'x');

        self::assertSame($before, umask());
    }

    public function testWriteLeavesNoPartialBehindOnSuccess(): void
    {
        $file = $this->dir . '/atomic.bin';
        ToolIpcFiles::write($file, 'whole');

        self::assertFileExists($file);
        self::assertFileDoesNotExist($file . '.partial');
    }

    public function testWriteReportsFailureRatherThanThrowingWhenTheDirectoryIsGone(): void
    {
        self::assertFalse(@ToolIpcFiles::write($this->dir . '/nope/deep.bin', 'x'));
    }

    public function testDiscardRemovesThePayloadAndAnyPartialSibling(): void
    {
        $file = $this->dir . '/collected.bin';
        file_put_contents($file, 'a');
        file_put_contents($file . '.partial', 'b');

        ToolIpcFiles::discard($file);

        self::assertFileDoesNotExist($file);
        self::assertFileDoesNotExist($file . '.partial');
    }

    // =========================================================================
    // Sweep
    // =========================================================================

    public function testSweepRemovesAbandonedPayloadsOfBothDispatchers(): void
    {
        $runtime = $this->stale(ToolIpcFiles::RUNTIME_PREFIX . 'aaaa.bin');
        $chat = $this->stale(ToolIpcFiles::CHAT_PREFIX . 'bbbb.json');
        $partial = $this->stale(ToolIpcFiles::RUNTIME_PREFIX . 'cccc.bin.partial');

        self::assertSame(3, ToolIpcFiles::sweep($this->dir, 3600));

        self::assertFileDoesNotExist($runtime);
        self::assertFileDoesNotExist($chat);
        self::assertFileDoesNotExist($partial);
    }

    /**
     * The whole safety of an age-based sweep. A payload belonging to a LIVE
     * run — this process's, or another sugar-crush on the same box, which is
     * indistinguishable from here — must survive, or a leak becomes a lost
     * tool result.
     */
    public function testSweepSparesAPayloadYoungerThanTheCutoff(): void
    {
        $fresh = $this->dir . '/' . ToolIpcFiles::RUNTIME_PREFIX . 'live.bin';
        file_put_contents($fresh, 'in flight');

        self::assertSame(0, ToolIpcFiles::sweep($this->dir, 3600));
        self::assertFileExists($fresh);
    }

    /**
     * And the boundary is the cutoff itself, not the prefix: the same file,
     * swept with a cutoff it IS older than, goes.
     */
    public function testTheCutoffIsWhatDecides(): void
    {
        $file = $this->dir . '/' . ToolIpcFiles::CHAT_PREFIX . 'old.json';
        file_put_contents($file, 'x');
        touch($file, time() - 120);

        self::assertSame(0, ToolIpcFiles::sweep($this->dir, 3600));
        self::assertSame(1, ToolIpcFiles::sweep($this->dir, 60));
    }

    public function testSweepLeavesEveryOtherFileInTheDirectoryAlone(): void
    {
        $unrelated = $this->stale('some_other_tool.json');
        $nearMiss = $this->stale('sc_runtime_toolish.bin');

        self::assertSame(0, ToolIpcFiles::sweep($this->dir, 3600));

        self::assertFileExists($unrelated);
        self::assertFileExists($nearMiss);
    }

    /**
     * `/tmp` is world-WRITABLE as well as world-listable, so another local
     * user can plant anything wearing our prefix. Only regular files are
     * touched: we are cleaning up after ourselves, not policing the directory.
     */
    public function testSweepIgnoresSomethingThatIsNotARegularFile(): void
    {
        $target = $this->dir . '/decoy-target';
        file_put_contents($target, 'not ours to delete');

        $link = $this->dir . '/' . ToolIpcFiles::CHAT_PREFIX . 'planted.json';
        symlink($target, $link);
        touch($link, time() - 86_400);

        $dir = $this->dir . '/' . ToolIpcFiles::RUNTIME_PREFIX . 'dir.bin';
        mkdir($dir);

        self::assertSame(0, ToolIpcFiles::sweep($this->dir, 3600));

        self::assertTrue(is_link($link));
        self::assertFileExists($target);
        self::assertDirectoryExists($dir);

        unlink($link);
        rmdir($dir);
    }

    public function testSweepIsSilentAboutADirectoryThatDoesNotExist(): void
    {
        self::assertSame(0, ToolIpcFiles::sweep($this->dir . '/missing', 1));
    }

    // =========================================================================
    // Wiring — the sweep has to actually run somewhere
    // =========================================================================

    /**
     * The latch is what makes it AT MOST once, and it has to be spent on the
     * directory the caller named — tests/bootstrap.php relies on exactly that
     * to keep the suite off the real `sys_get_temp_dir()`.
     */
    public function testSweepOnceSweepsTheGivenDirectoryAndThenNoOther(): void
    {
        $stale = $this->stale(ToolIpcFiles::CHAT_PREFIX . 'latched.json');
        $second = $this->dir . '/second';
        mkdir($second, 0o700);
        $untouched = $this->stale('second/' . ToolIpcFiles::CHAT_PREFIX . 'spared.json');

        $latch = new \ReflectionProperty(ToolIpcFiles::class, 'swept');
        $wasSwept = $latch->getValue();

        try {
            $latch->setValue(null, false);

            self::assertSame(1, ToolIpcFiles::sweepOnce($this->dir));
            self::assertFileDoesNotExist($stale);

            self::assertSame(0, ToolIpcFiles::sweepOnce($second), 'the latch is spent');
            self::assertFileExists($untouched);
        } finally {
            $latch->setValue(null, $wasSwept);
            @unlink($untouched);
            @rmdir($second);
        }
    }

    /**
     * A sweep nobody calls fixes nothing. Every real run reaches
     * {@see Bootstrap::backend()} (or {@see Bootstrap::backendFor()}) exactly
     * once, which is where the process's single sweep happens.
     *
     * The latch is reset by reflection first because another test in this
     * suite may already have tripped it, and restored afterwards so this test
     * cannot hand a later one a second sweep it did not expect.
     *
     * This is the one test that sweeps the REAL temp directory, because
     * production's sweep takes no directory and proving the wiring means
     * letting it run for real. The planted name is randomized so a second
     * suite running concurrently on the same box (CI, a watcher) cannot delete
     * this run's file out from under it and fail an unrelated assertion.
     */
    public function testBootstrapSweepsAbandonedPayloadsAtStartup(): void
    {
        $stale = sys_get_temp_dir() . '/' . ToolIpcFiles::RUNTIME_PREFIX
            . 'bootstrapsweep' . bin2hex(random_bytes(6)) . '.bin';
        file_put_contents($stale, 'orphaned by a cancelled turn');
        touch($stale, time() - 86_400);

        $latch = new \ReflectionProperty(ToolIpcFiles::class, 'swept');
        $wasSwept = $latch->getValue();

        $provider = getenv('SUGARCRUSH_PROVIDER');
        $cmd = getenv('SUGARCRUSH_BACKEND_CMD');
        // The streaming variable belongs in this list for the same reason the
        // other two do: it is a tier of the same selection chain, and a list that
        // names all but the newest member is the defect the chain keeps
        // producing. It happens not to change this test's outcome -- the sweep
        // runs before any tier is chosen -- which is exactly why it was missed.
        $streamCmd = getenv('SUGARCRUSH_BACKEND_CMD_STREAM');

        // setUp() already put HOME here (both spellings); this test only needs
        // the same directory to assert against.
        $sandbox = $this->dir . '/home';

        try {
            $latch->setValue(null, false);
            putenv('SUGARCRUSH_PROVIDER');
            putenv('SUGARCRUSH_BACKEND_CMD');
            putenv('SUGARCRUSH_BACKEND_CMD_STREAM');

            Bootstrap::backend($this->dir);

            self::assertFileDoesNotExist($stale, 'a real launch must reap payloads a killed run left behind');
        } finally {
            @unlink($stale);
            $latch->setValue(null, $wasSwept);
            $provider === false ? putenv('SUGARCRUSH_PROVIDER') : putenv('SUGARCRUSH_PROVIDER=' . $provider);
            $cmd === false ? putenv('SUGARCRUSH_BACKEND_CMD') : putenv('SUGARCRUSH_BACKEND_CMD=' . $cmd);
            $streamCmd === false ? putenv('SUGARCRUSH_BACKEND_CMD_STREAM') : putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $streamCmd);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function stale(string $name): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, 'x');
        touch($path, time() - 86_400);

        return $path;
    }

    private function mode(string $path): string
    {
        clearstatcache(true, $path);

        return substr(sprintf('%o', fileperms($path)), -4);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    // =========================================================================
    // The reservation ledger (E63)
    // =========================================================================

    /**
     * Production must not pay for the ledger, and must not accumulate one
     * either: unarmed, a reservation is recorded nowhere and
     * {@see ToolIpcFiles::strandedReservations()} answers "nothing" even for a
     * payload that really is sitting on disk uncollected.
     *
     * Stated as a property of the DEFAULT state rather than of a disarmed one,
     * because that is what a real run is: nothing in `src/` calls
     * `recordReservations()`.
     */
    public function testAnUnarmedLedgerRecordsNothing(): void
    {
        $this->assertNull(
            (new \ReflectionProperty(ToolIpcFiles::class, 'reserved'))->getValue(),
            'the ledger must be unarmed by default',
        );

        $file = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');

        try {
            ToolIpcFiles::write($file, '{}');
            self::assertFileExists($file);
            self::assertSame([], ToolIpcFiles::strandedReservations());
        } finally {
            ToolIpcFiles::discard($file);
        }
    }

    /**
     * The direction that matters for the leak: a payload this process reserved
     * and never collected is reported, and collecting it clears the report.
     */
    public function testAnArmedLedgerReportsAReservedPayloadNobodyCollected(): void
    {
        ToolIpcFiles::recordReservations(true);
        $file = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');

        try {
            self::assertSame([], ToolIpcFiles::strandedReservations(), 'a reserved name is not yet a file');

            ToolIpcFiles::write($file, '{}');
            self::assertSame([$file], ToolIpcFiles::strandedReservations());

            ToolIpcFiles::discard($file);
            self::assertSame([], ToolIpcFiles::strandedReservations());
        } finally {
            ToolIpcFiles::discard($file);
            ToolIpcFiles::recordReservations(false);
        }
    }

    /**
     * The E63 reproduction. A `sc_chat_tool_*` payload written by something
     * that is not this process appears in the shared temp dir inside the
     * window, so the window-diff detector this replaced counted it as this
     * run's strand -- the `glob()` assertion pins that the fixture really is
     * such a file, so a green result means the attribution changed rather than
     * that the fixture missed.
     *
     * Identity attribution cannot see it at all: it was never reserved here,
     * so no amount of concurrent activity in `/tmp` can put it in the list.
     */
    public function testAConcurrentWritersPayloadCannotEnterTheStrandedList(): void
    {
        ToolIpcFiles::recordReservations(true);

        $foreign = sys_get_temp_dir() . '/' . ToolIpcFiles::CHAT_PREFIX
            . bin2hex(random_bytes(8)) . '.json';
        $mine = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');

        try {
            file_put_contents($foreign, '{}');
            ToolIpcFiles::write($mine, '{}');

            $window = glob(sys_get_temp_dir() . '/' . ToolIpcFiles::CHAT_PREFIX . '*') ?: [];
            self::assertContains($foreign, $window, 'the fixture must be a file the old window diff would have counted');

            self::assertSame([$mine], ToolIpcFiles::strandedReservations());
        } finally {
            @unlink($foreign);
            ToolIpcFiles::discard($mine);
            ToolIpcFiles::recordReservations(false);
        }
    }

    /**
     * A child SIGKILLed mid-write leaves the `.partial` and never the payload,
     * so identity attribution has to look for both names or it would go blind
     * to exactly the abandonment {@see ToolIpcFiles::sweep()} was written for.
     */
    public function testAStrandedPartialIsReportedUnderItsOwnName(): void
    {
        ToolIpcFiles::recordReservations(true);
        $file = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');
        $partial = $file . '.partial';

        try {
            file_put_contents($partial, '{"half');
            self::assertFileDoesNotExist($file);
            self::assertSame([$partial], ToolIpcFiles::strandedReservations());
        } finally {
            ToolIpcFiles::discard($file);
            ToolIpcFiles::recordReservations(false);
        }
    }

    /**
     * Arming clears whatever was there, so one class's ledger can never be
     * read as another's -- and disarming clears it too, so a suite that
     * forgets to disarm cannot leave a later caller holding stale paths.
     */
    public function testRecordReservationsClearsTheLedgerInBothDirections(): void
    {
        $ledger = new \ReflectionProperty(ToolIpcFiles::class, 'reserved');

        ToolIpcFiles::recordReservations(true);
        $first = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');
        self::assertSame([$first], $ledger->getValue());

        ToolIpcFiles::recordReservations(true);
        self::assertSame([], $ledger->getValue());

        ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');
        ToolIpcFiles::recordReservations(false);
        self::assertNull($ledger->getValue());
        self::assertSame([], ToolIpcFiles::strandedReservations());
    }
}
