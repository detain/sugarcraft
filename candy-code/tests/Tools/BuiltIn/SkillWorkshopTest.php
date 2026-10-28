<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Skills\LearnedSkills;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Tools\BuiltIn\SkillWorkshop;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * The SkillWorkshop tool (C-EXT-3.e, ruling R-EXT-2): the model's pen over
 * the learned-skill store. The pins guard the three promises the ruling is
 * made of — every successful call ANSWERS with the announcement naming the
 * live path and the `/skills undo` door, the schema door refuses malformed
 * calls before the store is reached, and the store's refusals (occupied
 * names, shadowed names, unlearned patch targets) ride back verbatim so the
 * model can correct — while the Write permission class keeps the human's
 * ask-gate in front of every byte.
 */
final class SkillWorkshopTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private LearnedSkills $store;
    private SkillWorkshop $tool;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/cc-cmp-3e-tool-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->store = LearnedSkills::new();
        $this->tool = new SkillWorkshop($this->store);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    /** The catalog identity: name, Write class, the position after skills_search. */
    public function testTheToolIsCataloguedAsAWriteClassTool(): void
    {
        $attributes = (new \ReflectionClass(SkillWorkshop::class))->getAttributes(BuiltInTool::class);
        self::assertCount(1, $attributes);
        $attribute = $attributes[0]->newInstance();

        self::assertSame(SkillWorkshop::NAME, $attribute->name);
        self::assertSame(ToolPermissionClass::Write, $attribute->permission, 'R-EXT-2 consent happens at the call: default mode asks, plan and dont-ask refuse');
        self::assertSame(43, $attribute->position);
        self::assertSame('SkillWorkshop', $this->tool->name());
    }

    /** A create lands the skill live and announces the path and the undo door. */
    public function testCreateLandsLiveAndAnnouncesTheUndoDoor(): void
    {
        $result = $this->tool->execute([
            'id' => 'call-create-1',
            'action' => 'create',
            'name' => 'workshop-alpha',
            'description' => 'Use when rehearsing the workshop.',
            'body' => "Step one.\nStep two.",
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame('call-create-1', $result->toolCallId());

        $path = $this->home . '/.candy-code/skills/workshop-alpha/SKILL.md';
        self::assertFileExists($path, 'R-EXT-2: the skill is live immediately, not queued behind an accept');
        self::assertSame('Use when rehearsing the workshop.', Skill::fromFile($path)->description);

        self::assertSame(
            sprintf(SkillWorkshop::CREATED_FORMAT, 'workshop-alpha', $path, 'workshop-alpha'),
            $result->content(),
        );
        self::assertStringContainsString('/skills undo workshop-alpha', $result->content(), 'the announcement names the undo door first-class');
    }

    /** A patch announces the next revision number. */
    public function testPatchAnnouncesTheNextRevision(): void
    {
        $this->tool->execute([
            'action' => 'create',
            'name' => 'workshop-patched',
            'description' => 'Original sentence.',
            'body' => 'Original body.',
        ]);

        $result = $this->tool->execute([
            'action' => 'patch',
            'name' => 'workshop-patched',
            'body' => 'Revised body only.',
        ]);

        self::assertFalse($result->isError(), $result->content());
        $path = $this->home . '/.candy-code/skills/workshop-patched/SKILL.md';
        self::assertSame(
            sprintf(SkillWorkshop::PATCHED_FORMAT, 'workshop-patched', 2, $path, 'workshop-patched'),
            $result->content(),
        );
        self::assertSame('Original sentence.', Skill::fromFile($path)->description, 'the omitted field keeps the stored sentence');
    }

    /** The sanitised name is what the announcement and the tree both carry. */
    public function testTheAnnouncementCarriesTheSanitisedName(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'name' => '../Slip Up!!',
            'description' => 'Name gets sanitised.',
            'body' => 'Body.',
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('Learned skill "slip-up"', $result->content());
        self::assertFileExists($this->home . '/.candy-code/skills/slip-up/SKILL.md');
        self::assertStringNotContainsString('../', $result->content());
    }

    /** Malformed calls are refused at the schema door; nothing reaches the store. */
    public function testTheSchemaDoorRefusesMalformedCalls(): void
    {
        $cases = [
            'no action at all' => [['name' => 'door-a', 'description' => 'd', 'body' => 'b'], 'must be "create" or "patch".'],
            'unknown action' => [['action' => 'delete', 'name' => 'door-a'], 'must be "create" or "patch".'],
            'no name' => [['action' => 'create', 'description' => 'd', 'body' => 'b'], '"name" must be a non-empty string.'],
            'blank name' => [['action' => 'create', 'name' => '   ', 'description' => 'd', 'body' => 'b'], '"name" must be a non-empty string.'],
            'create without body' => [['action' => 'create', 'name' => 'door-a', 'description' => 'd'], 'needs both "description" and "body".'],
            'patch with nothing to change' => [['action' => 'patch', 'name' => 'door-a'], 'at least one of "description" or "body" to change.'],
            'non-string description' => [['action' => 'create', 'name' => 'door-a', 'description' => 7, 'body' => 'b'], '"description" must be a string when given.'],
            'non-string body' => [['action' => 'create', 'name' => 'door-a', 'description' => 'd', 'body' => []], '"body" must be a string when given.'],
        ];

        foreach ($cases as $label => [$args, $needle]) {
            $result = $this->tool->execute($args);
            self::assertTrue($result->isError(), $label . ' must be refused');
            self::assertStringStartsWith('Error: SkillWorkshop ', $result->content(), $label);
            self::assertStringContainsString($needle, $result->content(), $label);
        }

        self::assertDirectoryDoesNotExist($this->home . '/.candy-code/skills', 'a schema refusal never creates the tree');
    }

    /** The store's refusals ride back verbatim so the model can correct. */
    public function testStoreRefusalsRideBackInTheToolError(): void
    {
        $this->tool->execute([
            'action' => 'create',
            'name' => 'occupied-twice',
            'description' => 'First.',
            'body' => 'Body.',
        ]);

        $again = $this->tool->execute([
            'action' => 'create',
            'name' => 'occupied-twice',
            'description' => 'Second.',
            'body' => 'Other body.',
        ]);
        self::assertTrue($again->isError());
        self::assertStringContainsString('SkillWorkshop refused:', $again->content());
        self::assertStringContainsString('patch it instead', $again->content(), 'the store sentence is handed to the model whole');

        $ghost = $this->tool->execute([
            'action' => 'patch',
            'name' => 'never-learned-ghost',
            'body' => 'Nothing to amend.',
        ]);
        self::assertTrue($ghost->isError());
        self::assertStringContainsString('no learned skill named', $ghost->content());
    }

    /** The doctrine the model reads at the call names the undo door and the bans. */
    public function testTheToolDescriptionCarriesTheDoctrine(): void
    {
        $description = $this->tool->description();

        self::assertStringContainsString('/skills undo', $description);
        self::assertStringContainsString('never secrets', $description);
        self::assertStringContainsString('general procedure', $description);
        self::assertStringContainsString('load in later sessions', $description);
    }

    /** The input schema demands action+name and lists both verbs. */
    public function testTheInputSchemaShape(): void
    {
        $schema = $this->tool->inputSchema();

        self::assertSame(['action', 'name'], $schema['required']);
        self::assertSame(['create', 'patch'], $schema['properties']['action']['enum']);
        self::assertArrayHasKey('description', $schema['properties']);
        self::assertArrayHasKey('body', $schema['properties']);
    }

}
