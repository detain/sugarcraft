<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleFactory;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\BuiltIn\ScheduleTool;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;

/**
 * C-AGT-5.a — the model's Schedule surface, driven through its real
 * construction seam: {@see ScheduleTool::fromCatalog()} with a HOME sandbox,
 * so the registry the tool builds IS the home-backed file the pumps reload.
 * The ceiling/cap arithmetic itself is pinned beside the factory
 * ({@see \SugarCraft\Code\Tests\Background\ScheduleFactoryTest}); what lives
 * here is the WIRING — that a create arrives root-bound and tagged in the
 * file, that a bypass ask dies at the call, that the delete guard reads the
 * tag, and that every answer arrives as an ordinary tool result the model can
 * act on.
 */
final class ScheduleToolTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    private string $project;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cc-sched-tool-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/home', 0700, true);
        mkdir($this->dir . '/project', 0700, true);
        $this->project = (string) realpath($this->dir . '/project');
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        // Deterministic, fixed-path cleanup: no walker call sites in a test
        // file (TreeWideGuardRoster) and no glob-shaped string literals in
        // this corpus (GlobDialectDifferential). The only files this suite's
        // own paths can grow are the roster and its single file lock.
        $code = $this->dir . '/home/.candy-code';
        @unlink($code . '/schedules.json');
        @unlink($code . '/schedules/.file.lock');
        @rmdir($code . '/schedules');
        @rmdir($code);
        @rmdir($this->dir . '/home');
        @rmdir($this->dir . '/project');
        @rmdir($this->dir);
    }

    /** The house construction seam: the catalog builds this tool. */
    private function tool(): ScheduleTool
    {
        $skills = new SkillRegistry();

        return ScheduleTool::fromCatalog(new ToolBuildContext(
            root: $this->project,
            loader: new InstructionFileLoader($this->project),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
        ));
    }

    private function fileRegistry(): ScheduleRegistry
    {
        // atPath builds over the path; LOAD it — the whole point of this
        // helper is to read back what the tool's own registry wrote.
        $registry = ScheduleRegistry::atPath($this->dir . '/home/.candy-code/schedules.json', $this->project);
        $registry->load();

        return $registry;
    }

    /**
     * @param array<array-key, mixed> $args
     * @param array<array-key, mixed> $overrides
     */
    private function create(ScheduleTool $tool, string $name, array $overrides = []): \SugarCraft\Code\Tools\ToolResult
    {
        return $tool->execute(['action' => 'create', 'name' => $name, 'every' => 3600, 'prompt' => 'Summarise the overnight changes.'] + $overrides);
    }

    public function testACreateArrivesRootBoundTaggedAndInFile(): void
    {
        $result = $this->create($this->tool(), 'nightly-notes');

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString("schedule 'nightly-notes' created", $result->content());
        self::assertStringContainsString($this->project, $result->content(), 'the answer states the root the row fired in');

        $row = $this->fileRegistry()->get('nightly-notes');
        self::assertNotNull($row, 'the create wrote the HOME file the pumps reload — not an in-memory ghost');
        self::assertSame($this->project, $row->root);
        self::assertSame(Schedule::CREATED_BY_MODEL, $row->createdBy);
        self::assertSame(PermissionMode::DontAsk->value, $row->permissionMode, 'no mode spelled lands on the floor');
    }

    public function testARequestedBypassIsRefusedByNameAtTheCall(): void
    {
        $result = $this->create($this->tool(), 'widen-me', ['permission_mode' => PermissionMode::BypassPermissions->value]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('bypass-permissions', $result->content());
        self::assertStringContainsString('unattended', $result->content());
        self::assertNull($this->fileRegistry()->get('widen-me'), 'a refused create wrote nothing');
    }

    public function testALooserRequestLandsOnTheSessionCeilingTheToolRunsUnder(): void
    {
        // No engine bound: the tool answers under its Default session, so an
        // accept-edits ask settles UP to default, never down to the row ask.
        $result = $this->create($this->tool(), 'gentle', ['permission_mode' => PermissionMode::AcceptEdits->value]);

        self::assertFalse($result->isError(), $result->content());
        $row = $this->fileRegistry()->get('gentle');
        self::assertNotNull($row);
        self::assertSame(PermissionMode::Default->value, $row->permissionMode);
    }

    public function testTheNinthModelCreateIsRefusedAndSaysWhy(): void
    {
        $tool = $this->tool();
        for ($i = 0; $i < ScheduleFactory::MODEL_ROW_CAP; $i++) {
            self::assertFalse($this->create($tool, 'quota-' . $i)->isError(), 'row ' . $i . ' should still fit');
        }

        $result = $this->create($tool, 'quota-final');
        self::assertTrue($result->isError());
        self::assertStringContainsString((string) ScheduleFactory::MODEL_ROW_CAP, $result->content());
        self::assertStringContainsString('delete', $result->content(), 'the refusal names the way out');
    }

    public function testDeleteRemovesAModelRowAndRewritesTheFile(): void
    {
        $tool = $this->tool();
        self::assertFalse($this->create($tool, 'temporary')->isError());

        $result = $tool->execute(['action' => 'delete', 'name' => 'temporary']);
        self::assertFalse($result->isError(), $result->content());

        $fresh = $this->fileRegistry();
        $fresh->load();
        self::assertNull($fresh->get('temporary'));
        self::assertStringNotContainsString('temporary', (string) file_get_contents($this->dir . '/home/.candy-code/schedules.json'));
    }

    public function testDeleteRefusesAnOperatorRowUntilTheFlagIsSpelled(): void
    {
        // An untagged row — the shape a hand-written schedules.json carries —
        // is not the model's to tidy...
        $operator = $this->fileRegistry();
        $operator->add(new Schedule(name: 'human-cron', everySeconds: 600, prompt: 'theirs', persisted: true, root: $this->project));
        $operator->persist();

        $tool = $this->tool();
        $refused = $tool->execute(['action' => 'delete', 'name' => 'human-cron']);
        self::assertTrue($refused->isError());
        self::assertStringContainsString('include_operator_rows', $refused->content(), 'the refusal spells the way a human can actually approve');
        self::assertStringContainsString('/schedules', $refused->content());
        self::assertNotNull($this->fileRegistry()->get('human-cron'), 'refused means untouched');

        // ...and the flag works because the Ask approval puts the spelled
        // argument in front of the human — the flag is the asking, not a
        // bypass of it.
        $forced = $tool->execute(['action' => 'delete', 'name' => 'human-cron', 'include_operator_rows' => true]);
        self::assertFalse($forced->isError(), $forced->content());

        $fresh = $this->fileRegistry();
        $fresh->load();
        self::assertNull($fresh->get('human-cron'));
    }

    public function testDeleteOfAnUnknownNameRefusesAndListsTheWayOut(): void
    {
        $result = $this->tool()->execute(['action' => 'delete', 'name' => 'never-existed']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('never-existed', $result->content());
    }

    public function testListPaintsRowsAndLoadsWithoutArguments(): void
    {
        $tool = $this->tool();
        $empty = $tool->execute(['action' => 'list']);
        self::assertFalse($empty->isError());

        self::assertFalse($this->create($tool, 'listed-one')->isError());
        $listing = $tool->execute([]); // action absent defaults to list — the read answer
        self::assertFalse($listing->isError());
        self::assertStringContainsString('listed-one', $listing->content());
        self::assertStringContainsString('every 1h', $listing->content());
    }

    public function testTheActionGrammarIsRefusedInBothDirections(): void
    {
        $tool = $this->tool();
        $nonsense = $tool->execute(['action' => 'frobnicate']);
        self::assertTrue($nonsense->isError());
        self::assertStringContainsString('create', $nonsense->content());

        $nonString = $tool->execute(['action' => 42]);
        self::assertTrue($nonString->isError());
    }

    public function testAPeerEditOnDiskIsVisibleToTheNextCall(): void
    {
        $tool = $this->tool();
        $peer = $this->fileRegistry();
        $peer->add(new Schedule(name: 'peer-row', everySeconds: 600, prompt: 'theirs', persisted: true, root: $this->project, createdBy: Schedule::CREATED_BY_MODEL));
        $peer->persist();

        // The digest latch is the cross-host channel — a model that asks to
        // delete what a peer just wrote must meet the CURRENT file, not the
        // roster its launch read at build time.
        $listing = $tool->execute(['action' => 'list']);
        self::assertStringContainsString('peer-row', $listing->content());

        $delete = $tool->execute(['action' => 'delete', 'name' => 'peer-row']);
        self::assertFalse($delete->isError(), $delete->content());
    }

    public function testAPersistFailureWithdrawsTheRowSoTheListNeverLies(): void
    {
        $tool = $this->tool();

        // Hold the machine-wide file lock on a second descriptor (flock is
        // per-open-file-description, so this process contends with itself
        // honestly): the roster add succeeds, the write-back loses the claim
        // race and raises its RuntimeException — the exact shape a peer host
        // mid-write produces.
        $lockDir = $this->dir . '/home/.candy-code/schedules';
        mkdir($lockDir, 0700, true);
        $held = fopen($lockDir . '/.file.lock', 'c');
        self::assertNotFalse($held);
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));

        $result = $this->create($tool, 'ghost-shift');

        flock($held, LOCK_UN);
        fclose($held);

        self::assertTrue($result->isError());
        self::assertStringContainsString('another host is writing schedules.json', $result->content(), 'the refusal wording rides the factory exception untouched');

        // The ghost lived in the tool's OWN registry: the digest latch saw no
        // change on disk, so no reload would ever clear it and every later
        // list would announce a row the file never received.
        $listing = $tool->execute(['action' => 'list']);
        self::assertFalse($listing->isError(), $listing->content());
        self::assertStringNotContainsString('ghost-shift', $listing->content(), 'a refused create leaves no in-memory ghost');
        self::assertFileDoesNotExist($this->dir . '/home/.candy-code/schedules.json');
    }

    public function testTheToolAnswersLoudlyWhenNoLaunchAttachedARegistry(): void
    {
        $result = ScheduleTool::new()->execute(['action' => 'list']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('registry', $result->content());
    }

    public function testTheCatalogIdentityIsAskClassPositionFortyOne(): void
    {
        // The design line, pinned on the attribute itself: Ask (every call
        // asks) and the free tail slot after the MCP dispatcher pair.
        $attributes = (new \ReflectionClass(ScheduleTool::class))->getAttributes(BuiltInTool::class);
        self::assertCount(1, $attributes);
        $attribute = $attributes[0]->newInstance();

        self::assertSame(ScheduleTool::NAME, $attribute->name);
        self::assertSame(ToolPermissionClass::Ask, $attribute->permission);
        self::assertSame(41, $attribute->position);
    }
}
