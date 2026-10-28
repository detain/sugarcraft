<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Skills\LearnedSkills;
use SugarCraft\Code\Skills\ProposedSkills;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Skills\SkillProposal;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * The versioned learned-skill store (C-EXT-3.e, owner ruling R-EXT-2):
 * model-written skills go live immediately, and the saved history under
 * `history/<n>.md` is what makes the announced `/skills undo` real. Every
 * pin here guards one of the store's four laws: the revision lands BEFORE
 * the live file, the window is bounded but never truncated (overflow is
 * archived whole), undo walks the window (restore for a head above 1,
 * removal with residue honesty for the sole revision), and no write ever
 * crosses a link or lands in a home this user does not own.
 */
final class LearnedSkillsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $root = '';
    private LearnedSkills $store;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/cc-cmp-3e-store-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->root = $this->home . '/.candy-code/skills';
        $this->store = LearnedSkills::new();
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    /** A create lands revision 1 and the live SKILL.md as the same bytes. */
    public function testCreateLandsRevisionOneAndTheLiveFileByteEqual(): void
    {
        $revision = $this->store->create(SkillProposal::new('learned-fixture-alpha', 'Use when testing the store.', "Step one.\nStep two."));

        self::assertSame(1, $revision->number);
        self::assertSame('learned-fixture-alpha', $revision->name);

        $history = $this->root . '/learned-fixture-alpha/history/1.md';
        self::assertSame($history, $revision->path);
        self::assertFileExists($history);

        $live = $this->root . '/learned-fixture-alpha/SKILL.md';
        self::assertSame((string) file_get_contents($history), (string) file_get_contents($live), 'the live file and its first revision are the same bytes');
        self::assertSame(0600, fileperms($live) & 0777);
        self::assertSame(0600, fileperms($history) & 0777);
        self::assertSame(0700, fileperms($this->root . '/learned-fixture-alpha') & 0777);
        self::assertSame(0700, fileperms($this->root . '/learned-fixture-alpha/history') & 0777);

        // Parseable by the loader that will read it next session.
        $skill = Skill::fromFile($live);
        self::assertSame('learned-fixture-alpha', $skill->name);
        self::assertSame('Use when testing the store.', $skill->description);

        $listed = $this->store->history('learned-fixture-alpha');
        self::assertTrue($listed['live']);
        self::assertCount(1, $listed['revisions']);
        self::assertSame(1, $listed['revisions'][0]['number']);
        self::assertSame(0, $listed['archived']);
    }

    /** The name arrives through the draft store's sanitiser, never as a path. */
    public function testTheNameIsSanitisedBeforeWriting(): void
    {
        $revision = $this->store->create(SkillProposal::new('../Hidden Traversal X', 'Escaping name test.', 'Body.'));

        self::assertSame('hidden-traversal-x', $revision->name);
        self::assertFileExists($this->root . '/hidden-traversal-x/SKILL.md');
        self::assertFileDoesNotExist($this->sandbox . '/hidden-traversal-x/SKILL.md');
        self::assertFileDoesNotExist($this->sandbox . '/Hidden Traversal X/SKILL.md');
        self::assertStringContainsString('name: hidden-traversal-x', (string) file_get_contents($revision->path));
    }

    /** A second create on an occupied user-tier name sends the writer to patch. */
    public function testCreateOnAnOccupiedNameRefusesAndPointsAtPatch(): void
    {
        $this->store->create(SkillProposal::new('dup-learned', 'First landing.', 'Original body.'));
        $liveBytes = (string) file_get_contents($this->root . '/dup-learned/SKILL.md');

        $occupied = null;
        try {
            $this->store->create(SkillProposal::new('dup-learned', 'Second tries.', 'Replacement body.'));
        } catch (\RuntimeException $e) {
            $occupied = $e;
        }
        self::assertNotNull($occupied, 'creating an occupied name must refuse');
        self::assertStringContainsString('patch it instead', $occupied->getMessage());

        self::assertSame($liveBytes, (string) file_get_contents($this->root . '/dup-learned/SKILL.md'), 'a refused create leaves the live bytes alone');
        self::assertSame(1, $this->store->revisionCount('dup-learned'));
    }

    /**
     * The shadow floor: a name the loader already resolves from the project
     * tier cannot be re-learned into the user tier above it (R-EXT-2's risks
     * row — a shadowed skill rides every later session). Proved against the
     * loader's own walk by chdir'ing into a planted project.
     */
    public function testCreateRefusesToShadowAProjectTierSkill(): void
    {
        $project = $this->sandbox . '/project';
        mkdir($project . '/.candy-code/skills/proj-shadow', 0700, true);
        file_put_contents(
            $project . '/.candy-code/skills/proj-shadow/SKILL.md',
            "---\nname: proj-shadow\ndescription: \"A project skill.\"\n---\n\nProject body.\n",
        );

        // The refusal must be observable THROUGH the loader the store uses.
        $rows = null;
        $shadowed = null;
        $previous = getcwd();
        chdir($project);
        try {
            self::assertArrayHasKey('proj-shadow', (new SkillLoader(false))->loadAllManifests('.'), 'the fixture project really carries the name');
            try {
                $this->store->create(SkillProposal::new('proj-shadow', 'Would shadow it.', 'Body.'));
            } catch (\RuntimeException $e) {
                $shadowed = $e;
            }
            $rows = $shadowed?->getMessage();
        } finally {
            chdir((string) $previous);
        }

        self::assertNotNull($shadowed, 'creating a project-tier name in the user tier must refuse');
        self::assertStringContainsString('must not shadow', (string) $rows);
        self::assertStringContainsString('project', (string) $rows);
        self::assertDirectoryDoesNotExist($this->root . '/proj-shadow');
    }

    /** A patch keeps the omitted fields and lands the merged file as head+1. */
    public function testPatchKeepsOmittedFieldsAndAdvancesTheHead(): void
    {
        $this->store->create(SkillProposal::new('patch-merge', 'Original description.', "Original body.\nSecond line."));

        $revision = $this->store->patch('patch-merge', 'New description only.', null);
        self::assertSame(2, $revision->number);

        $skill = Skill::fromFile($this->root . '/patch-merge/SKILL.md');
        self::assertSame('New description only.', $skill->description);
        self::assertSame("Original body.\nSecond line.", trim((string) $skill->content), 'the body the patch did not mention survives');

        $listed = $this->store->history('patch-merge');
        self::assertSame([1, 2], array_column($listed['revisions'], 'number'));
    }

    /** Undo of a head above 1 restores the previous revision's bytes whole. */
    public function testUndoRestoresThePreviousRevision(): void
    {
        $this->store->create(SkillProposal::new('undo-walk', 'One.', 'First body.'));
        $firstBytes = (string) file_get_contents($this->root . '/undo-walk/SKILL.md');
        $this->store->patch('undo-walk', 'Two.', 'Second body.');

        $result = $this->store->undo('undo-walk');
        self::assertSame('restored', $result['action']);
        self::assertSame(1, $result['revision']);
        self::assertNull($result['residue']);
        self::assertSame($firstBytes, (string) file_get_contents($this->root . '/undo-walk/SKILL.md'));
        self::assertFileDoesNotExist($this->root . '/undo-walk/history/2.md', 'the undone head leaves the window');
        self::assertSame(1, $this->store->revisionCount('undo-walk'));
    }

    /** Undo of the sole revision removes the skill — and says what stayed. */
    public function testUndoOfTheSoleRevisionRemovesTheSkill(): void
    {
        $this->store->create(SkillProposal::new('undo-solo', 'Only one landing.', 'Body.'));

        $result = $this->store->undo('undo-solo');
        self::assertSame('removed', $result['action']);
        self::assertSame(0, $result['revision']);
        self::assertNull($result['residue']);
        self::assertDirectoryDoesNotExist($this->root . '/undo-solo', 'the whole tree is pruned when it held only what the store wrote');
        self::assertFalse($this->store->isLive('undo-solo'));
    }

    /** Removal never deletes files the workshop did not write; residue names them. */
    public function testRemovalLeavesUserFilesAndReportsResidue(): void
    {
        $this->store->create(SkillProposal::new('undo-residue', 'Learned.', 'Body.'));
        $dir = $this->root . '/undo-residue';
        mkdir($dir . '/assets', 0700, true);
        file_put_contents($dir . '/assets/notes.md', 'A file the user added beside it.');

        $result = $this->store->undo('undo-residue');
        self::assertSame('removed', $result['action']);
        self::assertSame($dir, $result['residue']);
        self::assertFileExists($dir . '/assets/notes.md', 'the user file survives');
        self::assertFileDoesNotExist($dir . '/SKILL.md');
        self::assertDirectoryDoesNotExist($dir . '/history');
    }

    /**
     * A removal the filesystem denies is an error, never a silent "removed".
     * With the skill directory itself sealed (no write permission) the live
     * file survives its unlink leg and undo raises before touching anything
     * else; with the history directory sealed the revision survives instead,
     * and the raised refusal names it while the saved bytes stay whole for a
     * retry. Under root the seals do not bite — the same pins then assert
     * the honest-success polarity (the AtomicEditWriteTest precedent), so
     * the test never skips and never passes vacuously.
     */
    public function testUndoOfTheSoleRevisionRefusesLoudlyWhenARemovalIsBlocked(): void
    {
        $asRoot = \function_exists('posix_geteuid') && \posix_geteuid() === 0;

        // Leg 1: the live file cannot go. Sealing the skill directory also
        // blocks the later rmdir legs, so this asserts the pre-state refusal
        // only; the retry below lifts the seal and runs the undo to its end.
        $this->store->create(SkillProposal::new('undo-stuck-live', 'Sealed live file.', 'Body.'));
        $dir = $this->root . '/undo-stuck-live';
        self::assertTrue(chmod($dir, 0500), 'the seal must apply');

        if ($asRoot) {
            $through = $this->store->undo('undo-stuck-live');
            self::assertSame('removed', $through['action'], 'root ignores the mode, so the undo simply succeeds');
            self::assertNull($through['residue']);
        } else {
            $liveStuck = null;
            try {
                $this->store->undo('undo-stuck-live');
            } catch (\RuntimeException $e) {
                $liveStuck = $e;
            }
            self::assertTrue(chmod($dir, 0700), 'the seal must lift for the asserts and tearDown');
            self::assertNotNull($liveStuck, 'undo must refuse loudly when the live file cannot be removed');
            self::assertStringContainsString('cannot remove the live file', $liveStuck->getMessage());
            self::assertFileExists($dir . '/SKILL.md', 'the refusal says nothing was removed, and it is true');
            self::assertFileExists($dir . '/history/1.md');

            $done = $this->store->undo('undo-stuck-live');
            self::assertSame('removed', $done['action']);
            self::assertDirectoryDoesNotExist($dir, 'the retry after the seal completes the removal');
        }

        // Leg 2: the revision file cannot go. The live file is removed first
        // (the write law in reverse), so the saved bytes are the survivor and
        // the refusal must name them rather than claim a finished removal.
        $this->store->create(SkillProposal::new('undo-stuck-head', 'Sealed head.', 'Body.'));
        $dir = $this->root . '/undo-stuck-head';
        $history = $dir . '/history';
        self::assertTrue(chmod($history, 0500), 'the seal must apply');

        if ($asRoot) {
            $through = $this->store->undo('undo-stuck-head');
            self::assertSame('removed', $through['action'], 'root ignores the mode, so the undo simply succeeds');
            self::assertNull($through['residue']);

            return;
        }

        $headStuck = null;
        try {
            $this->store->undo('undo-stuck-head');
        } catch (\RuntimeException $e) {
            $headStuck = $e;
        }
        self::assertTrue(chmod($history, 0700), 'the seal must lift for the retry and tearDown');
        self::assertNotNull($headStuck, 'undo must refuse loudly when the head revision cannot be removed');
        self::assertStringContainsString('cannot remove revision 1', $headStuck->getMessage());
        self::assertFileDoesNotExist($dir . '/SKILL.md', 'the live file was already removed by the half-applied undo');
        self::assertFileExists($history . '/1.md', 'the saved bytes survive a refused removal — undo is retryable');

        $done = $this->store->undo('undo-stuck-head');
        self::assertSame('removed', $done['action']);
        self::assertSame(0, $done['revision']);
        self::assertDirectoryDoesNotExist($dir, 'the retry completes the removal with nothing left mislabelled');
    }

    /** A skill that arrived another way has nothing saved to undo. */
    public function testUndoWithoutSavedHistoryRefuses(): void
    {
        $dir = $this->root . '/handwritten';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/SKILL.md', "---\nname: handwritten\ndescription: \"Typed by hand.\"\n---\n\nBody.\n");

        $nothingSaved = null;
        try {
            $this->store->undo('handwritten');
        } catch (\RuntimeException $e) {
            $nothingSaved = $e;
        }
        self::assertNotNull($nothingSaved, 'undo without saved revisions must refuse');
        self::assertStringContainsString('no saved revision to undo', $nothingSaved->getMessage());

        self::assertFileExists($dir . '/SKILL.md');
    }

    /** The window is bounded; overflow moves WHOLE files to the archive. */
    public function testTheWindowIsBoundedAndOverflowIsArchivedWhole(): void
    {
        $this->store->create(SkillProposal::new('window-wide', 'Revision one.', 'Body one.'));
        for ($number = 2; $number <= 9; $number++) {
            $this->store->patch('window-wide', 'Revision ' . $number . '.', 'Body ' . $number . '.');
        }

        $listed = $this->store->history('window-wide');
        self::assertCount(LearnedSkills::MAX_REVISIONS, $listed['revisions'], 'the active window holds exactly the newest MAX_REVISIONS');
        self::assertSame([2, 3, 4, 5, 6, 7, 8, 9], array_column($listed['revisions'], 'number'));
        self::assertSame(1, $listed['archived']);
        self::assertFileDoesNotExist($this->root . '/window-wide/history/1.md');
        $archivedBytes = (string) file_get_contents($this->root . '/window-wide/history/archive/1.md');
        self::assertStringContainsString('Revision one.', $archivedBytes, 'the archived revision is the whole original file, not a trace');

        // The undo door still walks the window.
        $result = $this->store->undo('window-wide');
        self::assertSame(8, $result['revision']);
        self::assertStringContainsString('Revision 8.', (string) file_get_contents($this->root . '/window-wide/SKILL.md'));
    }

    /**
     * The law of the write order, pinned structurally: inside land() the
     * revision file is written BEFORE the live SKILL.md, and the window trim
     * runs AFTER both — no live bytes ever exist that the history does not
     * already hold, and no trim ever runs against a not-yet-landed revision.
     * A mutation that swaps the two writes (or trims first) reddens exactly
     * this pin, where every functional test stays green.
     */
    public function testTheRevisionIsWrittenBeforeTheLiveFileAndTheTrimLast(): void
    {
        $lines = file((new \ReflectionMethod(LearnedSkills::class, 'land'))->getFileName());
        self::assertIsArray($lines);
        $source = implode('', $lines);

        $start = strpos($source, 'private function land(');
        self::assertNotFalse($start, 'land() is in the store');
        $end = strpos($source, "\n    }\n", $start);
        self::assertNotFalse($end, 'land() terminates plainly');
        $body = substr($source, $start, $end - $start);

        $revisionWrite = strpos($body, 'AtomicFileWriter::write($revisionPath');
        $liveWrite = strpos($body, "AtomicFileWriter::write(\$dir . '/SKILL.md'");
        $trim = strpos($body, 'MAX_REVISIONS)');

        self::assertNotFalse($revisionWrite, 'the revision write is in land()');
        self::assertNotFalse($liveWrite, 'the live write is in land()');
        self::assertNotFalse($trim, 'the window trim is in land()');
        self::assertLessThan($liveWrite, $revisionWrite, 'the revision lands before the live file it versions');
        self::assertLessThan($trim, $liveWrite, 'the trim runs after both writes');
    }

    /** A planted link at the skill directory is never read through. */
    public function testALinkedSkillDirectoryIsRefusedBlind(): void
    {
        $this->store->create(SkillProposal::new('linked-witness', 'Plain sibling exists.', 'Body.'));

        // A real learned tree planted OUTSIDE the store, linked in under a
        // second name: patch, undo and history must all answer as if it were
        // nothing, because the store never descends through a link.
        $target = $this->sandbox . '/outside-beta';
        mkdir($target . '/history', 0700, true);
        file_put_contents($target . '/history/1.md', "---\nname: beta-linked\ndescription: \"Planted.\"\n---\n\nPlanted body.\n");
        file_put_contents($target . '/SKILL.md', "---\nname: beta-linked\ndescription: \"Planted.\"\n---\n\nPlanted body.\n");
        symlink($target, $this->root . '/beta-linked');

        $patchRefused = null;
        try {
            $this->store->patch('beta-linked', 'Over the link.', null);
        } catch (\RuntimeException $e) {
            $patchRefused = $e;
        }
        self::assertNotNull($patchRefused, 'patching through a linked skill directory must refuse');
        self::assertStringContainsString('no learned skill named', $patchRefused->getMessage());

        $undoRefused = null;
        try {
            $this->store->undo('beta-linked');
        } catch (\RuntimeException $e) {
            $undoRefused = $e;
        }
        self::assertNotNull($undoRefused, 'undoing through a linked skill directory must refuse');
        self::assertStringContainsString('no saved revision to undo', $undoRefused->getMessage());

        $listed = $this->store->history('beta-linked');
        self::assertSame([], $listed['revisions']);
        self::assertFalse($listed['live'], 'a linked live file is not live');
        self::assertFileExists($target . '/history/1.md', 'the planted target is untouched');
    }

    /** A create whose name is already a planted link refuses as occupied. */
    public function testCreateOnALinkedDirectoryNameRefuses(): void
    {
        $this->store->create(SkillProposal::new('witness-again', 'Root exists.', 'Body.'));
        symlink($this->sandbox . '/nowhere-at-all', $this->root . '/link-occupied');

        $linkCreate = null;
        try {
            $this->store->create(SkillProposal::new('link-occupied', 'Through the link?', 'Body.'));
        } catch (\RuntimeException $e) {
            $linkCreate = $e;
        }
        self::assertNotNull($linkCreate, 'creating through a planted link must refuse');
        self::assertStringContainsString('patch it instead', $linkCreate->getMessage(), 'the occupied arm fires for links too — nothing is written through them');

        self::assertDirectoryDoesNotExist($this->sandbox . '/nowhere-at-all');
    }

    /** A present-but-unreadable head (oversized) refuses the patch, never fabricates. */
    public function testAnUnreadableHeadRevisionRefusesToPatch(): void
    {
        $this->store->create(SkillProposal::new('fat-head', 'Fine so far.', 'Body.'));
        // Plant a newest revision over the read cap by hand.
        file_put_contents(
            $this->root . '/fat-head/history/2.md',
            str_repeat('x', LearnedSkills::MAX_REVISION_BYTES + 64),
        );

        $fatHead = null;
        try {
            $this->store->patch('fat-head', 'Would overwrite unseen bytes.', null);
        } catch (\RuntimeException $e) {
            $fatHead = $e;
        }
        self::assertNotNull($fatHead, 'patching over an unreadable head must refuse');
        self::assertStringContainsString('cannot be read', $fatHead->getMessage());

        self::assertSame(2, $this->store->revisionCount('fat-head'), 'the refusal writes nothing and drops nothing');
        self::assertFileExists($this->root . '/fat-head/history/2.md');
    }

    /** Over-cap and empty fields are refused with the draft store's wording family. */
    public function testFieldCapsAndEmptinessAreRefused(): void
    {
        try {
            $this->store->create(SkillProposal::new('capped-field', str_repeat('d', ProposedSkills::MAX_DESCRIPTION_CHARS + 1), 'Body.'));
            self::fail('an over-cap description must refuse');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('over', $e->getMessage());
            self::assertStringContainsString('description', $e->getMessage());
        }

        try {
            $this->store->create(SkillProposal::new('capped-field', 'Fine.', str_repeat('b', ProposedSkills::MAX_BODY_BYTES + 1)));
            self::fail('an over-cap body must refuse');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('body over', $e->getMessage());
        }

        try {
            $this->store->create(SkillProposal::new('capped-field', '   ', 'Body.'));
            self::fail('an empty description must refuse');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('empty description', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->root . '/capped-field', 'a refused create writes no tree');
    }

    /** Secrets in the learned text are blanked by the redactor before landing. */
    public function testTheRedactorPassLandsBeforeTheBytesDo(): void
    {
        $secret = 'ghp_' . str_repeat('a', 20) . str_repeat('B', 16);
        $this->store->create(SkillProposal::new('secret-learner', 'Keeps a token out.', 'Use the token ' . $secret . ' and carry on.'));

        $bytes = (string) file_get_contents($this->root . '/secret-learner/SKILL.md');
        self::assertStringNotContainsString($secret, $bytes);
        self::assertStringContainsString('[REDACTED]', $bytes);
    }

    /** With an unowned home the store refuses every door by name. */
    public function testNoOwnedHomeRefusesEveryWrite(): void
    {
        chmod($this->home, 0777);
        // Clear first: fileperms() answers from PHP's per-path stat cache, and
        // the cache holds the pre-chmod 0700 the sandbox setup touched.
        clearstatcache(true, $this->home);
        // Built AFTER the lever moves: the store resolves its home at
        // construction, so a store built while the home was owned would keep
        // answering through its cached root.
        $stranded = LearnedSkills::new();
        $createRefused = null;
        $undoRefused = null;
        try {
            try {
                $stranded->create(SkillProposal::new('stranded-learned', 'Cannot land.', 'Body.'));
            } catch (\RuntimeException $e) {
                $createRefused = $e;
            }

            try {
                $stranded->undo('stranded-learned');
            } catch (\RuntimeException $e) {
                $undoRefused = $e;
            }
        } finally {
            chmod($this->home, 0700);
        }

        self::assertNotNull($createRefused, 'the store must refuse without an owned home');
        self::assertStringContainsString('no home directory', $createRefused->getMessage());
        self::assertNotNull($undoRefused, 'undo must refuse without an owned home too');
        self::assertStringContainsString('no home directory', $undoRefused->getMessage());

        self::assertDirectoryDoesNotExist($this->root);
    }
}
