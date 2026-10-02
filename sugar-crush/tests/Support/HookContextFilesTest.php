<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\HookContextFiles;
use SugarCraft\Crush\Support\ToolIpcFiles;

/**
 * The RETAINED hook-overflow store (P7.S1, ruling R-2), tested where it lives.
 *
 * Three properties are load-bearing. Two are pinned behaviourally here: the write
 * is private (0600) and leaves no intermediate behind, and the file SURVIVES the
 * hourly IPC sweep (that is the whole difference between this store and {@see
 * ToolIpcFiles} — a retained overflow must still be readable by the model on a
 * later turn). The third, {@see HookContextFiles::bound()} never returning more
 * than the byte cap it is given, is pinned below.
 *
 * ATOMICITY IS NOT BEHAVIOURALLY CLAIMED BY THIS SUITE. `write()`'s temp-then-
 * rename discipline (`src/Support/HookContextFiles.php`) — never leaving a
 * half-written file the consumer reads as whole — is pinned at CODE REVIEW, not
 * here. Reddening on a loss of atomicity would require a same-box crash mid-write
 * probe, which is banned as flaky in this deterministic suite; a post-success
 * absence check cannot distinguish atomic from direct write, so the tests below
 * deliberately make no atomicity promise they could not keep.
 */
final class HookContextFilesTest extends TestCase
{
    /**
     * @var list<string> files this test created, removed in tearDown
     */
    private array $created = [];

    /**
     * @var list<string> sandbox roots and root-scoped files from the FU3 arms
     */
    private array $sandboxRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }

        $this->created = [];
        $this->removeSandboxRoots();
    }

    public function testDirIsDedicatedAndOutsideTheSweepGlob(): void
    {
        $dir = HookContextFiles::dir();

        $this->assertDirectoryExists($dir);
        // The dedicated sub-directory is the first half of the sweep-safety proof:
        // glob('*/sc_runtime_tool_*') never descends into a nested directory.
        $this->assertSame(sys_get_temp_dir() . '/' . self::ownDirectoryName(), $dir);
    }

    /**
     * THE NAME CARRIES THE UID IT WAS GIVEN, and the posix-less build gets a
     * named shared scope rather than the bare stem. Driven through the private
     * seam because no build this suite runs on lacks `posix_geteuid()`, so the
     * `null` arm is otherwise unreachable — the same reason
     * {@see \SugarCraft\Crush\Tests\Hooks\AuditHookTest} drives AuditHook's.
     */
    public function testTheDirectoryNameIsScopedToTheEffectiveUid(): void
    {
        $for = new \ReflectionMethod(HookContextFiles::class, 'directoryFor');

        self::assertSame(
            sys_get_temp_dir() . '/sc-hook-ctx-4242',
            $for->invoke(null, 4242),
            'the overflow directory no longer carries the uid it was given, so two users share one name again',
        );
        self::assertSame(sys_get_temp_dir() . '/sc-hook-ctx-noposix', $for->invoke(null, null));
    }

    /**
     * AUDIT TMP-1: ANOTHER USER'S DIRECTORY AT THE OLD SHARED NAME NO LONGER
     * DISABLES THIS USER'S RETENTION.
     *
     * Under one fixed `sc-hook-ctx` name the first user on the box to overflow a
     * hook owned the directory, and every other user's overflow was refused as
     * unsafe for as long as it stood. A process without root cannot create a
     * directory another uid owns, so the squatter here is a `0755` directory —
     * one the verification refuses just the same — at the unscoped name, in a
     * sandbox the child adopts as its temp directory (`sys_get_temp_dir()` is
     * cached per process, see the blocked-directory test below for why this
     * runs in a child). Reverting the uid suffix makes the store pick the
     * squatter again and the marker reads "could not be retained".
     */
    public function testADirectoryAnotherUserHoldsAtTheSharedNameDoesNotBlockThisUsersOverflow(): void
    {
        $root = $this->overflowSandboxRoot();
        $squatter = $root . '/' . HookContextFiles::DIR_NAME;
        self::assertTrue(mkdir($squatter, 0o700));
        self::assertTrue(chmod($squatter, 0o755));

        $program = <<<'PHP'
            <?php
            declare(strict_types=1);
            require '%1$s';

            $bounded = SugarCraft\Crush\Support\HookContextFiles::bound(str_repeat('Q', 20_000), 10_000);

            fwrite(STDOUT, json_encode([
                'tempRoot' => sys_get_temp_dir(),
                'marker' => bin2hex(substr($bounded, -400)),
            ]));
            PHP;
        $child = $this->runInSandboxedChild($root, $program);

        self::assertSame(0, $child['status'], 'the child died: ' . $child['stderr']);
        $report = json_decode($child['stdout'], true);
        self::assertIsArray($report, 'the child reported nothing: ' . $child['stdout']);
        self::assertSame($root, $report['tempRoot'], 'the child did not run under the sandbox, so this says nothing');

        $marker = (string) hex2bin($report['marker']);
        self::assertStringNotContainsString(
            'could not be retained',
            $marker,
            'a directory somebody else holds at the shared name still disables this user\'s overflow retention',
        );
        $ours = $root . '/' . self::ownDirectoryName();
        self::assertStringContainsString('retained at ' . $ours . '/ctx-', $marker);
        self::assertSame(1, preg_match('/retained at (\S+)\]/', $marker, $m));
        self::assertSame(20_000, filesize($m[1]), 'the retained file does not hold the whole overflow');
        self::assertSame(['.', '..'], scandir($squatter), 'the overflow was written into the squatter\'s directory');
    }

    /**
     * Pins ONLY what a post-success observation can honestly see: `write()` creates
     * the file OWNER-PRIVATE (exactly 0600, no group/other bits) and leaves NO
     * intermediate behind — nothing sharing the final path as a prefix survives a
     * successful write.
     *
     * NOT claimed here (and not claimable without a crash probe): ATOMICITY. The
     * temp-then-rename discipline in `src/Support/HookContextFiles.php` that makes
     * a consumer unable to observe a half-written overflow is a property pinned at
     * CODE REVIEW. Pinning it behaviourally would require crashing the writer
     * mid-write on the same box, which is banned as flaky in this deterministic
     * suite; a `.partial`-absence check passes identically whether production wrote
     * atomically or directly, so this test makes no atomicity promise it could not
     * keep.
     */
    public function testWriteIsPrivateAndLeavesNoPartialBehind(): void
    {
        $path = HookContextFiles::write(str_repeat('Z', 4096));
        $this->created[] = $path;

        clearstatcache();
        $this->assertFileExists($path);
        // 0600 exactly — no group/other bits for the whole life of the file (umask
        // at create, not chmod-after, so there is no world-readable window). An
        // exact mode check, not a `& 0o077` zero test that would also pass on e.g.
        // 0o640.
        $this->assertSame(0o600, fileperms($path) & 0o777, 'a retained overflow is not exactly owner-private');

        // The final path holds the whole text, and NOTHING sharing it as a name
        // prefix with a dotted suffix (e.g. a `.partial` intermediate) survives a
        // successful write. Note the `.*` (not `*`) pattern: PHP's glob `*` matches
        // the empty string, so `glob($path . '*')` returns the retained file itself
        // and could never be `[]`; `.*` is the honest form of the reviewer's
        // "nothing like ctx-….txt.anything remains" intent. This pins the "no
        // residue behind" half of the write discipline; see the docblock for why
        // the atomicity half is code-review-only.
        $this->assertSame(4096, strlen((string) file_get_contents($path)));
        $this->assertFileDoesNotExist($path . '.partial', 'the write left its .partial intermediate in place');
        $this->assertSame([], glob($path . '.*'), 'the write left a dotted-suffix intermediate behind');
    }

    public function testRetainedFileSurvivesTheHourlyIpcSweep(): void
    {
        // The pre-code STEP ZERO proof made concrete: with the sweep's age cutoff
        // forced to zero, a file in the bare temp dir matching one of the three
        // IPC prefixes would be removed immediately, yet our overflow — same age,
        // different directory segment — must be left standing. This is exactly the
        // silent data-loss window the ruling forbade, pinned against a future
        // widening of the sweep predicate.
        $path = HookContextFiles::write('must not be reaped before the model reads it');
        $this->created[] = $path;

        // POSITIVE CONTROL for the sweep call below: plant a file the sweep MUST
        // reap, in the bare temp dir under one of the three real IPC prefixes. With
        // no decoy a fully inert sweep (a broken glob, a renamed prefix list) leaves
        // this test GREEN while the retained file stands for the wrong reason — "the
        // reaper is dead", not "the reaper cannot reach us". The decoy proves the
        // reaper actually fired on this very invocation.
        $decoy = sys_get_temp_dir() . '/' . ToolIpcFiles::RUNTIME_PREFIX . bin2hex(random_bytes(4)) . '.json';
        $previous = umask(0o077);

        try {
            file_put_contents($decoy, 'decoy ipc payload owed to a dead owner');
        } finally {
            umask($previous);
        }

        try {
            // This exercises the REAL sweep against the SHARED temp dir by design:
            // only a real `HookContextFiles::dir()` proves the two stores are
            // disjoint, which is the whole point. A cutoff of 0 could therefore reap
            // another LIVE sugar-crush process's in-flight IPC payloads — so suites
            // are run serially in this repo. `ToolIpcFilesTest` keeps the sandboxed
            // positive-control pin for the reap behaviour in isolation.
            $removed = ToolIpcFiles::sweep(sys_get_temp_dir(), 0);
        } finally {
            @unlink($decoy);
        }

        $this->assertIsInt($removed);
        $this->assertGreaterThanOrEqual(1, $removed, 'the sweep reaped nothing — the decoy proves it never fired');
        $this->assertFileDoesNotExist($decoy, 'the sweep left a same-prefix IPC decoy in the bare temp dir');
        $this->assertFileExists($path, 'the IPC sweep reached a retained hook-overflow file');
        $this->assertSame(
            'must not be reaped before the model reads it',
            file_get_contents($path),
        );
    }

    public function testBoundReturnsShortTextUnchangedAndWritesNothing(): void
    {
        $short = 'a model-visible note well under the cap';
        $before = glob(HookContextFiles::dir() . '/*') ?: [];
        sort($before);

        $result = HookContextFiles::bound($short, 10_000);

        $this->assertSame($short, $result);

        // The "WritesNothing" half of the method name is a real assertion, not a
        // caption: the directory listing must be byte-identical before and after, so
        // an at-or-under-cap path that quietly spilled to disk would go red here.
        $after = glob(HookContextFiles::dir() . '/*') ?: [];
        sort($after);
        $this->assertSame($before, $after, 'bound() wrote a retained file for text at or under the cap');
    }

    public function testBoundCapsToTheByteLimitAndNamesTheRetainedFile(): void
    {
        $text = str_repeat('Q', 20_000);

        $bounded = HookContextFiles::bound($text, 10_000);

        $this->assertLessThanOrEqual(10_000, strlen($bounded), 'bound() blew past its cap');
        $this->assertNotSame($text, $bounded);
        $this->assertStringContainsString('truncated: ', $bounded);
        $this->assertStringContainsString('of 20000 bytes shown', $bounded);

        $matched = preg_match('/retained at (\S+)\]/', $bounded, $m);
        $this->assertSame(1, $matched, 'the bounded marker did not name a retained path');
        $this->created[] = $m[1];

        // The retained file carries the ENTIRE original text, not the preview.
        $this->assertSame(20_000, strlen((string) file_get_contents($m[1])));
    }

    public function testBoundReservesAtLeastOnePreviewByteUnderATinyCap(): void
    {
        // A cap smaller than the marker reserve must still yield a non-empty,
        // in-cap string rather than a zero/negative-length mb_strcut — the
        // max(1, …) guard copied from the TruncatesOutput idiom, pinned so it
        // cannot be "optimised" away into the $maxBytes <= 0 no-cap trap.
        $bounded = HookContextFiles::bound(str_repeat('W', 5_000), 128);

        $this->assertLessThanOrEqual(128, strlen($bounded));
        $this->assertNotSame('', $bounded);
    }

    // =========================================================================
    // FU3(a) — the directory is inspected before a byte is written through it
    // =========================================================================

    /**
     * THE CLASSIC `/tmp` SHAPE, refused. `/tmp` lets any user on the box create
     * an entry, so the entry named `sc-hook-ctx` may exist before this process
     * ever asks for it — and if it is a link, "retain my overflow privately"
     * means "write hook output wherever the link points, as this uid".
     *
     * Both polarities are here because a gate that only ever refuses is
     * indistinguishable from a gate that always refuses: the same input minus the
     * link must be ACCEPTED by the same call, or the assertion above says nothing
     * about the comparison that was supposed to be deleted.
     */
    public function testASymbolicLinkAtTheDirectoryPathIsRefusedAndNothingIsWrittenThroughIt(): void
    {
        $root = $this->overflowSandboxRoot();
        $planted = $root . '/' . HookContextFiles::DIR_NAME;
        $whereTheLinkPoints = $root . '/somewhere-else';

        self::assertTrue(mkdir($whereTheLinkPoints, 0o700, true));
        self::assertTrue(symlink($whereTheLinkPoints, $planted));

        $outcome = $this->inspectDirectory($planted);

        self::assertNull($outcome['path'], 'a symlink at the overflow directory was accepted');
        self::assertSame(HookContextFiles::REFUSAL_UNSAFE_DIRECTORY, $outcome['code']);
        self::assertStringContainsString('symbolic link', (string) $outcome['reason']);
        self::assertTrue(is_link($planted), 'the refusal removed or replaced the link it refused');
        self::assertSame(
            ['.', '..'],
            scandir($whereTheLinkPoints),
            'bytes were written through the refused symlink — the whole point of the check',
        );
    }

    /**
     * THE OWNERSHIP ARM, on a directory this test does not get to choose.
     *
     * The input is derived rather than manufactured because a process at uid 1000
     * cannot `chown`: the helper takes the first real directory on a short list
     * that is tight enough to CLEAR the mode arm and owned by somebody else, so a
     * refusal is attributable to the uid comparison alone. On this box that is
     * `/root` (uid 0, 0700, MEASURED). STATED BOUND, in the same spirit as
     * {@see \SugarCraft\Crush\Tests\Hooks\AuditHookTest}: a suite running as root
     * owns every candidate, the list comes back empty, and only the positive
     * control below still runs.
     */
    public function testADirectoryOwnedByAnotherUserIsRefused(): void
    {
        $foreign = self::aTightDirectoryAnotherUserOwns();

        if ($foreign !== null) {
            $outcome = $this->inspectDirectory($foreign);
            self::assertNull($outcome['path'], $foreign . ' belongs to another uid and is tight enough to '
                . 'clear the mode arm, so accepting it means the ownership comparison is gone');
            self::assertSame(HookContextFiles::REFUSAL_UNSAFE_DIRECTORY, $outcome['code']);
            self::assertStringContainsString('owned by uid', (string) $outcome['reason']);
        }

        $ours = $this->overflowSandboxRoot() . '/' . HookContextFiles::DIR_NAME;
        self::assertTrue(mkdir($ours, 0o700, true));
        self::assertSame($ours, $this->inspectDirectory($ours)['path'], 'the accept arm refuses everything, '
            . 'which would leave the refusals above describing the test rather than the store');
    }

    /**
     * THE MODE ARM, on a directory this process DOES own.
     *
     * Ownership alone is not the property: a `0755` directory of ours is one every
     * other user can list, and a `0777` one is one they can create entries in
     * before we do. The refusal has to come from the bits, so this input is tight
     * on uid and loose on mode — the mirror image of the ownership arm's input —
     * and the remedy belongs in the message because `chmod 700` is not inferable
     * from the symptom.
     */
    public function testADirectoryOtherUsersCanReachIsRefusedOnItsMode(): void
    {
        $loose = $this->overflowSandboxRoot() . '/' . HookContextFiles::DIR_NAME;
        self::assertTrue(mkdir($loose, 0o700, true));
        self::assertTrue(chmod($loose, 0o755));

        $outcome = $this->inspectDirectory($loose);

        self::assertNull($outcome['path'], 'a directory anybody on the box can read was accepted as private storage');
        self::assertSame(HookContextFiles::REFUSAL_UNSAFE_DIRECTORY, $outcome['code']);
        self::assertStringContainsString('mode 0755', (string) $outcome['reason']);
        self::assertStringContainsString('chmod 700 ' . $loose, (string) $outcome['reason']);

        self::assertTrue(chmod($loose, 0o700));
        self::assertSame($loose, $this->inspectDirectory($loose)['path'], 'the mode arm never lets a tight '
            . 'directory through, so the refusal above could be a hardcoded rejection');
    }

    /**
     * A REGULAR FILE squatting the name is its own arm: `is_dir()` would follow a
     * link here and `mkdir()` would fail there, but the sentence the operator
     * needs is "that path is not a directory", not a guess from a create failure.
     */
    public function testAPlainFileAtTheDirectoryPathIsRefused(): void
    {
        $root = $this->overflowSandboxRoot();
        $squatter = $root . '/' . HookContextFiles::DIR_NAME;
        file_put_contents($squatter, 'squatted');

        $outcome = $this->inspectDirectory($squatter);

        self::assertNull($outcome['path'], 'a plain file at the overflow directory name was accepted');
        self::assertSame(HookContextFiles::REFUSAL_UNSAFE_DIRECTORY, $outcome['code']);
        self::assertStringContainsString('not a directory', (string) $outcome['reason']);
        self::assertSame('squatted', file_get_contents($squatter), 'the refusal wrote through the file it refused');
    }

    /**
     * THE CREATE ARM, which runs once per machine and so is the arm the accept-path
     * tests above cannot see. An absent path must become a directory at EXACTLY
     * 0700 — the umask narrowed around the create rather than a `chmod` after it,
     * the same discipline the file writes use, because the window between the two
     * is the window the bytes sit world-readable in.
     */
    public function testTheDirectoryIsCreatedExactlyOwnerOnlyWhenItIsAbsent(): void
    {
        $root = $this->overflowSandboxRoot();
        $dir = $root . '/' . HookContextFiles::DIR_NAME;

        $outcome = $this->inspectDirectory($dir);

        self::assertSame($dir, $outcome['path']);
        self::assertNull($outcome['reason']);
        clearstatcache();
        self::assertSame(0o700, fileperms($dir) & 0o777, 'the overflow directory was not created owner-exclusive');
    }

    /**
     * THE FAIL-CLOSED END OF THE CHAIN, seen from the model's side and through the
     * real {@see HookContextFiles::dir()} resolution rather than the private seam.
     *
     * It runs in a child because `sys_get_temp_dir()` caches its answer on the
     * first call in a process — the mechanism `tests/bootstrap.php` documents at
     * length — so the only way to point the store at a hostile root is to start a
     * process that has not looked yet. `TMPDIR` is set in the CHILD's environment
     * rather than with `putenv()`, which is what makes the cache warm with the
     * sandbox instead of the machine temp dir.
     *
     * Three things are asserted together, and the third is the one that a marker
     * alone cannot prove: the overflow still travels in band under the cap, it
     * SAYS the bytes were not retained, and the directory the planted link points
     * at is still empty. A "fail closed" that writes through the link while
     * printing a sorry message would satisfy the first two.
     */
    public function testABlockedDirectoryDegradesInTheBandAndWritesNothingThroughTheLink(): void
    {
        $root = $this->overflowSandboxRoot();
        // The uid-scoped name, i.e. the one this process will really resolve:
        // the per-user suffix ends contention, not planting, so the link a
        // hostile user can still put there is the threat this test pins.
        $planted = $root . '/' . self::ownDirectoryName();
        $whereTheLinkPoints = $root . '/victim';

        self::assertTrue(mkdir($whereTheLinkPoints, 0o700, true));
        self::assertTrue(symlink($whereTheLinkPoints, $planted));

        // THE CHILD LOADS THE ONE FILE, NOT THE autoloader. Two reasons, and the
        // second is a constraint worth naming where it is met. `HookContextFiles`
        // is final, imports nothing and extends nothing (MEASURED: zero `use` lines
        // in its source), so requiring the file the parent already ran is the whole
        // program the child needs — and it is provably the SAME file the parent
        // used, which a `vendor/` resolution could only assert. The other reason is
        // that the usual spelling of that path is a two-level climb out of this
        // directory, and that shape is precisely `TreeWideGuardRosterTest::ROOT_ANCHOR`
        // — which it is here as PROSE, measured: the first draft of this very
        // paragraph quoted the climb and that quote alone pulled this file into that
        // test's domain, where every `glob`/`scandir` in it must then be classified
        // in a roster this step does not own. Reaching the source through reflection
        // on the class under test is both the stronger claim and the narrower
        // footprint. IF this class ever
        // gains a sibling dependency the child will fatal on an undefined class and
        // the `$status === 0` assertion below fails loudly — the switch back to the
        // autoloader is then due, along with the roster rows it would owe.
        // THE REPORT TRAVELS AS HEX ON PURPOSE. The tail slice this test needs is
        // `substr($bounded, -180)` — BYTES — and a bounded string whose marker is
        // longer than the slice starts INSIDE the three-byte U+2026 the marker
        // opens with, which hands `json_encode()` malformed UTF-8 and it answers
        // `false`. That happened, and it made this test's red-on-revert read as a
        // serialization fault instead of as the write-through-a-symlink it was
        // actually reporting. Hex cannot be malformed.
        $program = <<<'PHP'
            <?php
            declare(strict_types=1);
            require '%1$s';

            $bounded = SugarCraft\Crush\Support\HookContextFiles::bound(str_repeat('Q', 20_000), 10_000);

            fwrite(STDOUT, json_encode([
                'tempRoot' => sys_get_temp_dir(),
                'length' => strlen($bounded),
                'marker' => bin2hex(substr($bounded, -180)),
                'head' => bin2hex(substr($bounded, 0, 40)),
                'retained' => glob(sys_get_temp_dir() . '/sc-hook-ctx' . '*/*') ?: [],
            ]));
            PHP;
        ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr] = $this->runInSandboxedChild($root, $program);

        // THE SECURITY CLAIM FIRST, ahead of anything the child had to say for
        // itself: whatever the marker reads, the refused store must not have put a
        // single byte behind the link. Reverting the hardening reddens here, with
        // the victim's own filename in the diff, rather than in whichever assertion
        // the child's report happens to upset.
        self::assertSame(
            ['.', '..'],
            scandir($whereTheLinkPoints),
            'the refused overflow landed in the directory the planted symlink pointed at',
        );
        self::assertTrue(is_link($planted), 'the refusal removed or replaced the link it refused');

        self::assertSame(0, $status, 'the child died: ' . $stderr);
        $report = json_decode($stdout, true);
        self::assertIsArray($report, 'the child reported nothing: ' . $stdout);
        self::assertSame($root, $report['tempRoot'], 'the child did not run under the sandbox, so this says nothing');
        self::assertSame(
            [],
            $report['retained'],
            'the overflow found some other path to write to under the sandbox — a fallback, not a refusal',
        );

        $marker = (string) hex2bin($report['marker']);
        self::assertStringContainsString('truncated: ', $marker);
        self::assertStringContainsString('of 20000 bytes shown', $marker);
        self::assertStringContainsString('could not be retained (overflow directory refused as unsafe)', $marker);
        self::assertStringNotContainsString(
            'retained at',
            $marker,
            'the marker named a path while the directory was refused',
        );
        self::assertLessThanOrEqual(10_000, $report['length']);
        self::assertSame(
            str_repeat('Q', 40),
            (string) hex2bin($report['head']),
            'the bounded head was not the first bytes of the text',
        );
    }

    /**
     * THE NOT-DISK ARM: a create that fails because the filesystem will not take
     * it is a different sentence from a refusal, and conflating the two sends an
     * operator to `df` for a problem that is somebody else's symlink. The parent
     * asserts the distinction rather than the wording, so the seam is driven with
     * a path that cannot exist at all (`/proc/<pid>/…` is not writable) and code
     * `0` is what must come back.
     */
    public function testANoRoomOnTheDiskFailureIsNotReportedAsARefusal(): void
    {
        $outcome = $this->inspectDirectory('/proc/self/sc-hook-ctx-impossible');

        self::assertNull($outcome['path']);
        self::assertSame(0, $outcome['code'], 'an impossible create was reported as a security refusal');
        self::assertStringContainsString('unable to create hook overflow directory', (string) $outcome['reason']);
    }

    // =========================================================================
    // FU3(b) — one retained file per overflowing pass, and no file without a name
    // =========================================================================

    /**
     * THE MULTI-PASS RULE, driven exactly the way
     * {@see \SugarCraft\Crush\Hooks\HookRegistry::executeHooks()} drives it: the
     * accumulator that already carries a marker goes back through `bound()` with
     * the next pass joined on.
     *
     * The rule pinned is one NEW file per overflowing bind, never a rewrite: pass
     * one's file keeps every byte it was given (the second accumulator holds only
     * that pass's bounded HEAD, so overwriting it would destroy the tail the model
     * was promised and could no longer name), and the chain stays walkable because
     * the first marker — path included — travels verbatim inside the second file.
     * `additions`/`removals` are computed as a set difference rather than a count,
     * so the assertion is about these two files and not about what else the box
     * happens to hold.
     */
    public function testASecondOverflowingPassWritesItsOwnFileAndLeavesTheFirstIntact(): void
    {
        $dir = HookContextFiles::dir();
        $before = glob($dir . '/*') ?: [];

        $first = HookContextFiles::bound(str_repeat('A', 20_000), 10_000);
        $firstPath = $this->retainedPathFrom($first);
        $firstBytes = (string) file_get_contents($firstPath);

        $joined = $first . "\n\n" . str_repeat('B', 9_000);
        $second = HookContextFiles::bound($joined, 10_000);
        $secondPath = $this->retainedPathFrom($second);
        $secondBytes = (string) file_get_contents($secondPath);

        $third = HookContextFiles::bound($second . "\n\n" . str_repeat('C', 3_000), 10_000);
        $thirdPath = $this->retainedPathFrom($third);

        $after = glob($dir . '/*') ?: [];
        $additions = array_values(array_diff($after, $before));
        sort($additions);
        $written = [$firstPath, $secondPath, $thirdPath];
        sort($written);

        self::assertSame(
            $written,
            $additions,
            'a multi-pass re-bind did not write exactly one new file per overflowing bind',
        );
        self::assertSame([], array_values(array_diff($before, $after)), 'a re-bind removed a retained file');

        self::assertSame($firstBytes, (string) file_get_contents($firstPath), 'the second pass rewrote the first '
            . 'pass — which would destroy the tail the first marker promised, since the accumulator it saved only '
            . 'holds the bounded head');
        self::assertSame(20_000, strlen($firstBytes));
        self::assertSame(strlen($joined), strlen($secondBytes));
        self::assertStringContainsString(
            'the full output is retained at ' . $firstPath . ']',
            $secondBytes,
            'the second file did not carry the first marker, so nothing downstream names the first overflow',
        );
        self::assertSame(str_repeat('B', 9_000), substr($secondBytes, -9_000));

        // The model-visible half: each pass names ITS OWN file and states the
        // byte figures of the text it was handed, under the cap.
        self::assertNotSame($firstPath, $secondPath);
        self::assertStringContainsString('of ' . strlen($joined) . ' bytes shown', $second);
        self::assertStringContainsString('truncated: 9488 of', $second);
        self::assertLessThanOrEqual(10_000, strlen($second));

        $thirdBytes = (string) file_get_contents($thirdPath);
        self::assertStringContainsString('retained at ' . $secondPath . ']', $thirdBytes);
        self::assertStringNotContainsString($thirdPath, $thirdBytes, 'a file named itself');
        self::assertStringContainsString(
            'of ' . strlen($second . "\n\n" . str_repeat('C', 3_000)) . ' bytes shown',
            $third,
        );
    }

    /**
     * THE NAMED-PATH FLOOR. Below a cap of roughly the marker's own footprint the
     * path cannot be disclosed at all, and the shipped shape answered that by
     * clamping `preview . marker` from the right — MEASURED on that shape, a
     * 128-byte cap returned
     * `… is retained at /tmp/sc-hook-ctx/ctx-0fc3c479ef8`, a name no reader can
     * open. Bytes the model cannot ask for are lost bytes with better typography,
     * so the disclosure now outranks the preview (cap 160: whole path, two bytes
     * of text) and, when even the path will not fit, the file is un-written
     * (cap 128: nothing retained, and nothing left in the directory under a name
     * no marker quotes).
     *
     * The caps are chosen for margin rather than measurement: both markers are
     * ~138–145 bytes depending on how long the temp path is, so 128 is below every
     * spelling of them and 160 above, whatever box this runs on.
     */
    public function testTheRetainedPathIsNeverCutInHalfByTheCap(): void
    {
        $dir = HookContextFiles::dir();
        $before = glob($dir . '/*') ?: [];

        $roomy = HookContextFiles::bound(str_repeat('Q', 5_000), 160);
        self::assertLessThanOrEqual(160, strlen($roomy));
        self::assertMatchesRegularExpression(
            '/the full output is retained at \S+\.txt\]/',
            $roomy,
            'a cap that could carry the path still cut it, so the model is holding a broken name',
        );

        $named = $this->retainedPathFrom($roomy);
        self::assertSame(5_000, strlen((string) file_get_contents($named)), 'the shrinking preview changed what was retained');

        $airless = HookContextFiles::bound(str_repeat('Q', 5_000), 128);
        self::assertLessThanOrEqual(128, strlen($airless));
        self::assertNotSame('', $airless);
        self::assertStringNotContainsString('retained at', $airless, 'an undisclosable path was still advertised');
        self::assertStringContainsString('could not be retained', $airless);
        self::assertStringContainsString('truncated: 0 of 5000 bytes shown', $airless);

        $after = glob($dir . '/*') ?: [];
        self::assertSame(
            [$named],
            array_values(array_diff($after, $before)),
            'the cap that cannot name a file still left one behind',
        );
    }

    /**
     * THE PRE-FLIGHT ON THE INTERMEDIATE NAME, pinned by SHAPE because no
     * behaviour can reach it.
     *
     * The `.partial` name carries 64 random bits and lives inside a directory
     * {@see HookContextFiles::dir()} has just refused to hand to anybody else, so
     * there is no way for a test to plant a collision at a name it is not allowed
     * to know. That is the same reason this file's older doc-block leaves the
     * create/rename ATOMICITY to code review; the difference here is that the
     * property has an unambiguous source shape to point at, and a mutation test
     * proves the pointer bites: deleting the guard reddens this test and nothing
     * else.
     *
     * Four positional claims over the body of `write()`, all with real offsets:
     * the `lstat()` guard exists; it precedes the `try` whose failure path
     * unlinks the name (so a file this process never made is never deleted on its
     * account); it precedes the write that would otherwise open with `O_TRUNC`
     * and follow a link planted at that exact name; and the unlink belongs to the
     * write, not to the guard.
     */
    public function testTheIntermediateNameIsInspectedBeforeItIsOpenedAndNeverUnlinkedOnThatRefusal(): void
    {
        $file = (new \ReflectionMethod(HookContextFiles::class, 'write'))->getFileName();
        self::assertIsString($file, 'the write() under test has no source file to inspect');

        $whole = file_get_contents($file);
        self::assertIsString($whole, 'the source of write() could not be read');

        $start = strpos($whole, 'public static function write');
        self::assertIsInt($start, 'write() is no longer spelled the way this census reads it');
        $body = substr($whole, $start);

        $guard = strpos($body, '@lstat($partial)');
        $try = strpos($body, 'try {');
        $write = strpos($body, '@file_put_contents($partial');
        $unlink = strpos($body, '@unlink($partial)');

        self::assertIsInt(
            $guard,
            'the pre-flight on the intermediate name is gone: a link planted at that name would now be '
                . 'truncated through, because the write below opens with O_TRUNC',
        );
        self::assertIsInt($try, 'write() no longer has the cleanup block this order is about');
        self::assertIsInt($write);
        self::assertIsInt($unlink);
        self::assertLessThan($try, $guard, 'the name is now inspected inside the cleanup path, so a refusal there would delete a file this process never made');
        self::assertLessThan($write, $guard, 'the intermediate is opened before it is inspected, which makes the inspection a witness and not a gate');
        self::assertGreaterThan($write, $unlink, 'the unlink is no longer the write side\'s cleanup, so this file no longer says what gets removed on failure');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A process-unique private root for the hostile-path tests, removed with
     * everything under it in tearDown.
     *
     * Named and scoped to this file on purpose: {@see \SugarCraft\Crush\Tests\Hooks\AuditHookTest}
     * already carries a `scratchPath()` helper doing the analogous job for the
     * audit hook, and a byte-twin of it here is the shape
     * {@see \SugarCraft\Crush\Tests\Support\DuplicatedTestHelperDriftTest} exists
     * to red on. This one allocates a DIRECTORY that outlives the single call
     * (the hostile paths need a parent to plant entries in) and is recursive on
     * the way out, which is enough difference in job to be worth its own name.
     */
    private function overflowSandboxRoot(): string
    {
        $root = sys_get_temp_dir() . '/sc_ctx_fu3_' . getmypid() . '_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0o700, true));
        $this->sandboxRoots[] = $root;

        return $root;
    }

    /**
     * This effective uid's directory name under any temp root: what
     * {@see HookContextFiles::dir()} resolves to, spelled independently of the
     * private seam so a test reading it is not grading the seam against itself.
     */
    private static function ownDirectoryName(): string
    {
        return HookContextFiles::DIR_NAME . '-' . posix_geteuid();
    }

    /**
     * Run $program (a `sprintf` template whose `%1$s` is the class under test's
     * source file) in a child whose temp directory is $root.
     *
     * THE CHILD LOADS THE ONE FILE, NOT THE autoloader — see the reasoning in
     * the blocked-directory test, which this helper's first caller was. `TMPDIR`
     * is set in the CHILD's environment so its `sys_get_temp_dir()` cache warms
     * with the sandbox.
     *
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runInSandboxedChild(string $root, string $program): array
    {
        $source = (new \ReflectionClass(HookContextFiles::class))->getFileName();
        self::assertIsString($source, 'the class under test has no source file to hand the child');

        $script = $this->sandboxFile($root, 'probe');
        file_put_contents($script, sprintf($program, $source));

        $process = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['TMPDIR' => $root, 'PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** A uniquely named writable file inside $root (the child's program text). */
    private function sandboxFile(string $root, string $tag): string
    {
        $path = $root . '/' . $tag . '_' . bin2hex(random_bytes(6)) . '.php';
        $this->sandboxRoots[] = $path;

        return $path;
    }

    /**
     * {@see HookContextFiles::verifiedDirectory()} as a value rather than an
     * exception, so the refusal arms can be asserted without a `catch` standing
     * between the test and a swallowed failure.
     *
     * @return array{path: ?string, code: int, reason: ?string}
     */
    private function inspectDirectory(string $dir): array
    {
        try {
            return [
                'path' => (string) (new \ReflectionMethod(HookContextFiles::class, 'verifiedDirectory'))
                    ->invoke(null, $dir),
                'code' => 0,
                'reason' => null,
            ];
        } catch (\RuntimeException $failure) {
            return [
                'path' => null,
                'code' => $failure->getCode(),
                'reason' => $failure->getMessage(),
            ];
        }
    }

    /** Pull the retained path out of a marker and register it for clean-up. */
    private function retainedPathFrom(string $bounded): string
    {
        self::assertSame(1, preg_match('/retained at (\S+)\]/', $bounded, $m));
        $this->created[] = $m[1];

        return $m[1];
    }

    /**
     * A real directory owned by another user that already clears the mode arm —
     * or null on a root run, where no such directory exists to be found.
     *
     * Checked at call time rather than assumed, so a candidate that has moved,
     * turned into a symlink, or been loosened by an operator is passed over
     * instead of turning this file's ownership arm into a mode-arm test.
     */
    private static function aTightDirectoryAnotherUserOwns(): ?string
    {
        foreach (['/root', '/etc/ssl/private', '/lost+found'] as $candidate) {
            if (is_link($candidate) || !is_dir($candidate)) {
                continue;
            }

            $owner = @fileowner($candidate);
            $mode = @fileperms($candidate);

            if ($owner !== false && $mode !== false && $owner !== posix_geteuid() && ($mode & 0o077) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Remove every sandbox root this test planted, two levels deep, without ever
     * following a link: the planted links are the point of three of these tests,
     * and a clean-up that resolved one would delete whatever it pointed at.
     */
    private function removeSandboxRoots(): void
    {
        foreach ($this->sandboxRoots as $path) {
            if (!is_dir($path) || is_link($path)) {
                @unlink($path);

                continue;
            }

            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $child = $path . '/' . $entry;

                if (is_link($child)) {
                    @unlink($child);
                } elseif (is_dir($child)) {
                    foreach (scandir($child) ?: [] as $leaf) {
                        if ($leaf !== '.' && $leaf !== '..') {
                            @unlink($child . '/' . $leaf);
                        }
                    }

                    @rmdir($child);
                } else {
                    @unlink($child);
                }
            }

            @rmdir($path);
        }

        $this->sandboxRoots = [];
    }
}
