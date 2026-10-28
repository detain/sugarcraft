<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Background;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleFactory;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\BuiltIn\ScheduleTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;

/**
 * C-AGT-5.b pins for the owner ruling (plan §6.3, option (a), closing
 * C-AGT-5.a review MINOR-2): the Schedule delete guard is ROOT-SCOPED. A
 * model-created row belonging to ANOTHER project root may not be deleted
 * without the include_operator_rows door; a same-root model delete stays
 * free; an operator-written row keeps the createdBy sentence byte-for-byte
 * and the /schedules delete human surface unchanged. The policy lives in
 * ScheduleFactory::deleteRefusal — one home beside create() — and the
 * tool-level legs prove the launch catalog routes through that single choke.
 *
 * The tool legs drive the catalog's own construction (fromCatalog →
 * ScheduleRegistry::loadDefault over the sandboxed HOME), so the "another
 * host" is a second launch rooted elsewhere reading the SAME roster file.
 */
final class ScheduleCrossRootDeleteTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    private string $project;

    private string $elsewhere;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cc-sched-xroot-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/home', 0700, true);
        mkdir($this->dir . '/project', 0700, true);
        mkdir($this->dir . '/elsewhere', 0700, true);
        $this->project = (string) realpath($this->dir . '/project');
        $this->elsewhere = (string) realpath($this->dir . '/elsewhere');
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        // Deterministic fixed-path cleanup: no walker call sites in a test
        // file (TreeWideGuardRoster) and no glob-shaped literals (GlobDialect).
        $code = $this->dir . '/home/.candy-code';
        @unlink($code . '/schedules.json');
        @unlink($code . '/schedules/.file.lock');
        @rmdir($code . '/schedules');
        @rmdir($code);
        @rmdir($this->dir . '/home');
        @rmdir($this->dir . '/project');
        @rmdir($this->dir . '/elsewhere');
        @rmdir($this->dir);
    }

    public function testASameRootModelRowDeletesWithoutAnyDoor(): void
    {
        self::assertNull(ScheduleFactory::deleteRefusal(
            self::makeRow('mine', root: $this->project),
            $this->project,
            false
        ));
    }

    public function testACrossRootModelRowIsRefusedAndNamesTheRootScope(): void
    {
        $refusal = ScheduleFactory::deleteRefusal(
            self::makeRow('mine', root: $this->elsewhere),
            $this->project,
            false
        );
        self::assertIsString($refusal);
        \assert($refusal !== null);
        self::assertStringContainsString('another project root', $refusal);
        self::assertStringContainsString($this->elsewhere, $refusal);
        self::assertStringContainsString('include_operator_rows: true', $refusal);
        // The ruling's route sentence survives: the human surface is named.
        self::assertStringContainsString('/schedules delete mine', $refusal);
    }

    public function testAnUnboundModelRowReadsAsForeignToAProjectRoot(): void
    {
        $refusal = ScheduleFactory::deleteRefusal(
            self::makeRow('loose', root: null),
            $this->project,
            false
        );
        self::assertIsString($refusal);
        \assert($refusal !== null);
        self::assertStringContainsString('another project root (unbound)', $refusal);
    }

    public function testTheOperatorDoorAlsoCarriesACrossRootModelRow(): void
    {
        self::assertNull(ScheduleFactory::deleteRefusal(
            self::makeRow('mine', root: $this->elsewhere),
            $this->project,
            true
        ));
    }

    public function testAnOperatorWrittenRowKeepsTheLegacySentenceByteForByte(): void
    {
        // C-AGT-5.a r1's carry is that this EXACT wording survived the
        // guard's move into the factory: a pin here, not a prose promise.
        $row = self::makeRow('audit', root: $this->project, createdBy: null);
        $refusal = ScheduleFactory::deleteRefusal($row, $this->project, false);
        self::assertSame(
            "schedule 'audit' was not created by this tool — deleting an "
            . "operator-written row needs include_operator_rows: true (the approval "
            . "will show it), or /schedules delete audit from a human prompt",
            $refusal
        );
        self::assertNull(ScheduleFactory::deleteRefusal($row, $this->project, true));
    }

    public function testTheToolRefusesACrossRootModelRowAndTheDoorCarriesIt(): void
    {
        $created = $this->catalogTool($this->project)->execute(
            ['action' => 'create', 'name' => 'mine', 'every' => 3600, 'prompt' => 'Sweep the overnight logs.']
        );
        self::assertFalse($created->isError(), $created->content());

        // A launch rooted ELSEWHERE over the same shared roster: the row is
        // model-created, so the legacy createdBy test passes — without the
        // root scope the old guard would have deleted it in one call.
        $foreign = $this->catalogTool($this->elsewhere);
        $refused = $foreign->execute(['action' => 'delete', 'name' => 'mine']);
        self::assertTrue($refused->isError(), $refused->content());
        self::assertStringContainsString('another project root', $refused->content());
        self::assertStringContainsString('include_operator_rows', $refused->content());
        self::assertNotNull($this->readBackRegistry()->get('mine'), 'the refusal wrote nothing');

        $through = $foreign->execute(['action' => 'delete', 'name' => 'mine', 'include_operator_rows' => true]);
        self::assertFalse($through->isError(), $through->content());
        self::assertStringContainsString("schedule 'mine' deleted", $through->content());
        self::assertNull($this->readBackRegistry()->get('mine'));
    }

    public function testTheToolStillDeletesASameRootModelRowWithoutTheDoor(): void
    {
        $tool = $this->catalogTool($this->project);
        self::assertFalse($tool->execute(
            ['action' => 'create', 'name' => 'mine', 'every' => 3600, 'prompt' => 'Sweep the overnight logs.']
        )->isError());
        $deleted = $tool->execute(['action' => 'delete', 'name' => 'mine']);
        self::assertFalse($deleted->isError(), $deleted->content());
        self::assertNull($this->readBackRegistry()->get('mine'));
    }

    public function testTheDescriptionAndSchemaSpellTheRootScope(): void
    {
        // The model reads THESE sentences before it ever gets refused: the
        // ruling's semantics have to be visible in the prompt, not only in
        // the refusal they prevent.
        $tool = $this->catalogTool($this->project);
        self::assertStringContainsString('another project root', $tool->description());
        $schema = $tool->inputSchema()['properties']['include_operator_rows'] ?? null;
        self::assertIsArray($schema);
        \assert(\is_array($schema));
        self::assertStringContainsString('another project root', (string) ($schema['description'] ?? ''));
    }

    // ---- fixtures --------------------------------------------------------

    /**
     * The launch catalog's own construction at a given project root, over
     * the shared sandboxed HOME roster.
     */
    private function catalogTool(string $root): ScheduleTool
    {
        $skills = new SkillRegistry();

        return ScheduleTool::fromCatalog(new ToolBuildContext(
            root: $root,
            loader: new InstructionFileLoader($root),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
        ));
    }

    /** Read-back over the SAME home file the tool's registry wrote. */
    private function readBackRegistry(): ScheduleRegistry
    {
        $registry = ScheduleRegistry::atPath($this->dir . '/home/.candy-code/schedules.json', $this->project);
        \clearstatcache();
        $registry->load();

        return $registry;
    }

    private function makeRow(string $name, ?string $root, ?string $createdBy = Schedule::CREATED_BY_MODEL): Schedule
    {
        return new Schedule(
            name: $name,
            everySeconds: 3600,
            prompt: 'Sweep the overnight logs.',
            nextDue: 4000000000,
            persisted: true,
            root: $root,
            createdBy: $createdBy,
        );
    }
}
