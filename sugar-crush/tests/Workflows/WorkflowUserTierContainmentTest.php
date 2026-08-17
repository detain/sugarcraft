<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Workflows\WorkflowNotFoundException;
use SugarCraft\Crush\Workflows\WorkflowRegistry;

/**
 * The user tier of {@see WorkflowRegistry} is the only directory in this package
 * whose contents are EXECUTED, and until this test file it had no containment of
 * any kind.
 *
 * TWO SPELLINGS, MEASURED, NOT HYPOTHESISED. Both were driven against the build
 * immediately before the fix, with `$HOME` mode 0700 and owned by the running
 * uid and no `.git` anywhere in the fixture — i.e. with every discriminator the
 * package had ever leaned on answering "this is the user's own tree":
 *
 *     ~/.sugar-crush/workflows -> <outside>     load('pwned') EXECUTED uid=1000
 *     workflows/entry.php -> <outside>/x.php    load('entry') EXECUTED uid=1000
 *
 * The stack trace was `WorkflowRegistry.php(241): require()`, reached in
 * production by `/workflow run <name>`
 * ({@see \SugarCraft\Crush\Cli\Bootstrap::workflowEngine()} ->
 * `Chat.php:3921 -> 4297`).
 *
 * NEITHER GATE CATCHES THE OTHER'S SPELLING, which is why both tests are here
 * and why one file's worth of assertions is not enough:
 *
 *  - the DIRECTORY anchor ({@see WorkflowRegistry::readableUserDir()}) is the
 *    only thing that can see row one, because per-entry confinement resolves the
 *    directory too and a linked directory takes the boundary with it;
 *  - the per-ENTRY check is the only thing that can see row two, because the
 *    directory in that row is a real directory inside `$HOME` and passes every
 *    anchor there is.
 *
 * WHY A FILE OF ITS OWN rather than more cases in `WorkflowRegistryTest`: the
 * escape is code execution, and the assertion that matters is that a payload did
 * NOT run. That needs a payload on disk with an observable side effect, plus a
 * HOME sandbox, plus a control proving the payload runs when it is legitimately
 * placed — otherwise "it did not execute" is indistinguishable from "the fixture
 * was wrong". A reviewer looking for the code-execution regression should find it
 * in one place.
 */
final class WorkflowUserTierContainmentTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    private string $home;

    private string $outside;

    private string $marker;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/crush_wf_user_tier_' . uniqid('', true);
        $this->home = $this->tempDir . '/home';
        $this->outside = $this->tempDir . '/outside';
        $this->marker = $this->tempDir . '/EXECUTED';

        mkdir($this->home . '/.sugar-crush', 0o700, true);
        mkdir($this->outside, 0o755, true);

        // MODE 0700 AND OWNED, deliberately: the fixture has to be the case
        // HomeDirectory::owned() ACCEPTS, or it would be measuring the ownership
        // check rather than the containment one. The refuted premise was that an
        // owned home implies the user chose where the links inside it point.
        $this->useHomeSandbox($this->home, create: false);

        $this->writePayload($this->outside . '/pwned.php');
        $this->writePayload($this->outside . '/entry.php');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }

    /**
     * PROBE A — the tarball-delivered directory symlink. One line in an archive:
     * `~/.sugar-crush/workflows -> <anywhere readable>`.
     *
     * Closed by the directory anchor alone. The per-entry check cannot see this
     * shape at all, which is why deleting `readableUserDir()`'s
     * `ContainedPath::below()` reddens this test and not the entry one.
     */
    public function testALinkedUserWorkflowsDirectoryDoesNotGetItsPhpRequired(): void
    {
        $userDir = $this->home . '/.sugar-crush/workflows';
        $this->assertTrue(symlink($this->outside, $userDir), 'fixture needs the directory link');

        $registry = $this->registry($userDir);

        $this->assertSame([], $registry->list(), 'a linked workflows directory advertises nothing');
        $this->assertNotNull($registry->userTierRefusal(), 'and says why');

        try {
            $registry->load('pwned');
            $this->fail('load() must not require a .php file out of a linked user directory');
        } catch (WorkflowNotFoundException) {
            // The refusal.
        }

        $this->assertFileDoesNotExist($this->marker, 'ARBITRARY CODE EXECUTION: the payload ran');
    }

    /**
     * PROBE B — no directory link at all. A real `~/.sugar-crush/workflows`
     * directory the user's own home vouches for, with ONE entry symlinked out.
     *
     * Closed by the per-entry check on the resolved `.php` path, which did not
     * exist in any form before this change: `load()` tested `is_file()` only, and
     * `is_file()` stats THROUGH a symlink.
     */
    public function testAnEntrySymlinkedOutOfARealUserDirectoryIsNotRequired(): void
    {
        $userDir = $this->home . '/.sugar-crush/workflows';
        mkdir($userDir, 0o700, true);
        $this->assertTrue(symlink($this->outside . '/entry.php', $userDir . '/entry.php'));

        $registry = $this->registry($userDir);

        $this->assertNull(
            $registry->userTierRefusal(),
            'the DIRECTORY is legitimate here — this row is only about the entry',
        );
        $this->assertSame([], $registry->list(), 'an escaping entry is not listed');

        try {
            $registry->load('entry');
            $this->fail('load() must not require an entry that resolves outside its directory');
        } catch (WorkflowNotFoundException) {
            // The refusal.
        }

        $this->assertFileDoesNotExist($this->marker, 'ARBITRARY CODE EXECUTION: the payload ran');
    }

    /**
     * THE CONTROL, without which both tests above pass against a build that
     * simply stopped loading `.php` workflows: a real file in a real directory
     * inside the home still runs and still returns its Workflow.
     */
    public function testAPhpWorkflowTheUserActuallyWroteStillLoadsAndRuns(): void
    {
        $userDir = $this->home . '/.sugar-crush/workflows';
        mkdir($userDir, 0o700, true);
        $this->writePayload($userDir . '/legit.php');

        $registry = $this->registry($userDir);

        $this->assertSame(['legit'], $registry->list());
        $this->assertSame('payload', $registry->load('legit')->name);
        $this->assertFileExists($this->marker, 'the control must actually execute, or it controls nothing');
    }

    /**
     * WHAT THE FIX COSTS, asserted rather than described: the user's own link to
     * a file elsewhere INSIDE their home is refused too.
     *
     * The entry predicate confines to the workflows DIRECTORY, not to `$HOME`, so
     * `workflows/deploy.php -> ~/src/workflows/deploy.php` — the layout the
     * refuted sentence named as its justification — stops resolving. Pinned here
     * so the cost cannot be discovered as a bug report; see the constructor for
     * why it is not softened to "anywhere in `$HOME`".
     */
    public function testAUserLinkToTheirOwnFileElsewhereInsideHomeIsRefusedToo(): void
    {
        $userDir = $this->home . '/.sugar-crush/workflows';
        mkdir($userDir, 0o700, true);
        mkdir($this->home . '/mine', 0o700, true);
        $this->writePayload($this->home . '/mine/inhome.php');
        $this->assertTrue(symlink($this->home . '/mine/inhome.php', $userDir . '/inhome.php'));

        $registry = $this->registry($userDir);

        $this->assertSame([], $registry->list());
        $this->expectException(WorkflowNotFoundException::class);

        try {
            $registry->load('inhome');
        } finally {
            $this->assertFileDoesNotExist($this->marker);
        }
    }

    /**
     * The `$userHome` anchor is what makes the fallback's stated bound real: a
     * link one component ABOVE the workflows directory resolves inside its own
     * parent, so the parent-directory fallback grants it and the passed home
     * refuses it.
     *
     * Both halves in one test, because the claim is a DIFFERENCE between them and
     * a difference asserted in two places drifts.
     */
    public function testTheHomeAnchorCatchesTheLinkOneComponentAboveWhichTheFallbackGrants(): void
    {
        $elsewhere = $this->tempDir . '/elsewhere';
        mkdir($elsewhere . '/workflows', 0o755, true);
        $this->writePayload($elsewhere . '/workflows/up.php');

        // `~/.sugar-crush -> <outside>`: the LEAF is a real directory inside the
        // link's target, so `<...>/workflows` resolves inside `<...>`.
        rmdir($this->home . '/.sugar-crush');
        $this->assertTrue(symlink($elsewhere, $this->home . '/.sugar-crush'));

        $userDir = $this->home . '/.sugar-crush/workflows';

        $withoutHome = new WorkflowRegistry($userDir);
        $this->assertNull($withoutHome->userTierRefusal(), 'the parent fallback cannot see a link one level up');
        $this->assertSame(['up'], $withoutHome->list(), 'and therefore grants the directory');

        @unlink($this->marker);

        $withHome = $this->registry($userDir);
        $this->assertNotNull($withHome->userTierRefusal(), 'the passed home is what sees it');
        $this->assertSame([], $withHome->list());

        try {
            $withHome->load('up');
        } catch (WorkflowNotFoundException) {
            // The refusal.
        }

        $this->assertFileDoesNotExist($this->marker);
    }

    /**
     * The refusal NAMES the anchor it was measured against, not just the words
     * "your home directory" — the correction applied to both this class's refusal
     * messages, for the reason stated on
     * {@see WorkflowRegistry::readableProjectDir()}: a reader cannot act on
     * "outside your home" without knowing which directory that was.
     */
    public function testTheUserTierRefusalNamesTheResolvedTargetAndTheAnchorPath(): void
    {
        $userDir = $this->home . '/.sugar-crush/workflows';
        $this->assertTrue(symlink($this->outside, $userDir));

        $refusal = (string) $this->registry($userDir)->userTierRefusal();

        $this->assertStringContainsString((string) realpath($this->outside), $refusal, 'the resolved target');
        $this->assertStringContainsString($this->home, $refusal, 'the anchor path');
        $this->assertStringContainsString('your home directory', $refusal, 'and what the anchor IS');
    }

    /**
     * A DANGLING user workflows link is refused, a MISSING directory is not — the
     * two halves of "does not resolve", split here for the reason they are split
     * for the project tier: a fresh install has no `~/.sugar-crush/workflows` and
     * must keep getting the not-found message that names it, while a link to a
     * path that does not exist yet is a request to `require` whatever appears
     * there later.
     */
    public function testADanglingUserWorkflowsLinkIsRefusedAndAMissingDirectoryIsNot(): void
    {
        $userDir = $this->home . '/.sugar-crush/workflows';

        $missing = $this->registry($userDir);
        $this->assertNull($missing->userTierRefusal(), 'a directory that is simply absent is not an escape');

        try {
            $missing->load('nope');
            $this->fail('nothing to load');
        } catch (WorkflowNotFoundException $e) {
            $this->assertStringContainsString($userDir, $e->getMessage(), 'and the message still names it');
        }

        $this->assertTrue(symlink($this->tempDir . '/appears-later', $userDir));
        $this->assertNotNull($this->registry($userDir)->userTierRefusal(), 'a dangling link is');
    }

    /**
     * A registry whose user directory is refused still reports a directory in its
     * not-found message.
     *
     * With NO project tier and the user's refused, `yamlDirectories()` is empty
     * and the message used to read `not found at ` with nothing after it — a worse
     * answer than naming the configured path, which is what the user is looking
     * for.
     */
    public function testTheNotFoundMessageStillNamesADirectoryWhenEveryTierIsRefused(): void
    {
        $userDir = $this->home . '/.sugar-crush/workflows';
        $this->assertTrue(symlink($this->outside, $userDir));

        $registry = new WorkflowRegistry($userDir, null, null, $this->home);

        $this->assertSame([], $registry->list(), 'the fixture is only meaningful with every tier refused');

        try {
            $registry->load('anything');
            $this->fail('every tier is refused');
        } catch (WorkflowNotFoundException $e) {
            $this->assertStringContainsString($userDir . '/anything.yaml', $e->getMessage());
        }
    }

    /**
     * THE SEAM IS ACTUALLY DRAINED, driven through a real launch rather than
     * asserted about the collector.
     *
     * A refusal nobody reads is the failure
     * {@see WorkflowRegistry::projectTierRefusal()} was added for: everything else
     * about a refused tier is silent — `list()` is simply shorter and the not-found
     * message names a directory the loader never opened. This is the user tier's
     * half, and it is the one that costs a user workflows they wrote themselves.
     *
     * `Bootstrap::chat()` is the entry point because `workflowEngine()` is private
     * and drained there; the collector is a process-wide static, so the assertion is
     * scoped to this test's own path rather than to the map's size.
     */
    public function testALaunchReportsARefusedUserWorkflowsDirectoryThroughTheCollector(): void
    {
        $userDir = $this->home . '/.sugar-crush/workflows';
        $this->assertTrue(symlink($this->outside, $userDir));

        $project = $this->tempDir . '/project';
        mkdir($project, 0o755, true);

        \SugarCraft\Crush\Cli\Bootstrap::chat($project);

        $refusals = array_filter(
            \SugarCraft\Crush\Cli\Bootstrap::projectTierRefusals(),
            static fn (string $key): bool => str_ends_with($key, '/.sugar-crush/workflows'),
            \ARRAY_FILTER_USE_KEY,
        );

        $this->assertArrayHasKey($userDir, $refusals, 'the launch must report the directory it refused');
        $this->assertStringContainsString('your home directory', $refusals[$userDir]);
    }

    /** A registry wired the way {@see \SugarCraft\Crush\Cli\Bootstrap} wires one. */
    private function registry(string $userDir): WorkflowRegistry
    {
        return new WorkflowRegistry(
            $userDir,
            $this->tempDir . '/project/.sugar-crush/workflows',
            $this->tempDir . '/project',
            $this->home,
        );
    }

    /**
     * A workflow file whose side effect is observable from outside the process.
     *
     * The marker is the whole instrument: `require` runs this file's top-level
     * code before anything looks at what it returned, so "did the payload
     * execute" cannot be answered by inspecting the return value.
     */
    private function writePayload(string $path): void
    {
        file_put_contents($path, sprintf(
            "<?php\nfile_put_contents(%s, 'EXECUTED');\n"
            . "return (new \\SugarCraft\\Crush\\Workflows\\WorkflowBuilder())->name('payload')->build();\n",
            var_export($this->marker, true),
        ));
    }
}
