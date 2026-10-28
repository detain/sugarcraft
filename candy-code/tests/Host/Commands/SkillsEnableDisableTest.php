<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\SkillsHostCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Role;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-EXT-2.d: `/skills enable <name>` and `/skills disable <name>` — the two
 * halves of the flag in ONE action. The verbs write the persisted
 * `disabledSkills` list (user tier, the sanctioned SettingsWriter door) AND
 * flip the launch's live registry, so the change bites this session now and
 * every later launch at boot. Before this step {@see SkillManager::disable()}
 * had no caller at all and the only off-switch was hand-editing the JSON.
 *
 * The registry here is the one the command reads names from AND mutates:
 * a refusal must leave it (and the file) byte-untouched.
 */
final class SkillsEnableDisableTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $config = '';
    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-skills-toggle-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0700, true);
        mkdir($this->home . '/.candy-code', 0700, true);
        $this->config = $this->home . '/.candy-code/config.json';
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    /** The full circle: disable persists + flips live + the table says `no`; enable undoes all three. */
    public function testDisablePersistsFlipsTheLiveRegistryAndTheTableCatchsup(): void
    {
        $registry = $this->registeredRegistry();
        $context = $this->toggleContext($registry);

        self::assertStringContainsString('Disabled `shipline`', $this->toggleReply($context, '/skills disable shipline'));

        self::assertSame(['shipline'], $this->persistedList(), 'the user tier carries the name now');
        self::assertTrue($registry->isDisabled('shipline'), 'the live registry flipped with the file');
        self::assertStringContainsString('no', $this->toggleReply($context, '/skills'), 'the same launch sees the new flag — the table re-derives from the config the write just landed on');
    }

    public function testEnableClearsThePersistedNameAndReopensTheLiveRegistry(): void
    {
        file_put_contents($this->config, '{"disabledSkills":["shipline","other"]}');
        $registry = $this->registeredRegistry();
        $registry->disable('shipline');
        $registry->disable('other');
        $context = $this->toggleContext($registry);

        self::assertStringContainsString('Enabled `shipline`', $this->toggleReply($context, '/skills enable shipline'));

        self::assertSame(['other'], $this->persistedList(), 'only the named entry left the list');
        self::assertFalse($registry->isDisabled('shipline'));
        self::assertTrue($registry->isDisabled('other'), 'the untouched entry stays off');
    }

    /** An unknown name is refused whole: no file, no registry mutation. */
    public function testAnUnknownNameIsRefusedAndChangesNothing(): void
    {
        $registry = $this->registeredRegistry();
        $context = $this->toggleContext($registry);

        foreach (['/skills enable ghost', '/skills disable ghost'] as $text) {
            $result = (new SkillsHostCommand())->run($context, $text);
            $last = $result->rows[\count($result->rows) - 1];
            self::assertSame(Role::System, $last->role, $text);
            self::assertStringContainsString('No installed skill named `ghost`', $last->content, $text);
        }
        self::assertFileDoesNotExist($this->config, 'a refused toggle never creates the settings file');
        self::assertFalse($registry->isDisabled('shipline'), 'the refusal touched the live registry not at all');
    }

    /** The verb needs its name and nothing else; arity is a usage notice. */
    public function testTheVerbsTakeExactlyOneName(): void
    {
        $context = $this->toggleContext($this->registeredRegistry());
        foreach (['/skills disable', '/skills enable shipline extra'] as $text) {
            $result = (new SkillsHostCommand())->run($context, $text);
            $last = $result->rows[\count($result->rows) - 1];
            self::assertSame(Role::System, $last->role, $text);
            self::assertStringContainsString('Usage: /skills', $last->content, $text);
        }
    }

    /** Without a writer door the persisted half cannot happen, so NOTHING moves. */
    public function testToggleRefusesWhenNoSettingsWriterIsReachable(): void
    {
        $registry = $this->registeredRegistry();
        $context = CommandContext::new(
            root: $this->project,
            workspace: WorkspaceContext::new(root: $this->project, skills: $registry),
        );

        $result = (new SkillsHostCommand())->run($context, '/skills disable shipline');
        $last = $result->rows[\count($result->rows) - 1];
        self::assertSame(Role::System, $last->role);
        self::assertStringContainsString('not saved', $last->content);
        self::assertFalse($registry->isDisabled('shipline'), 'the live flip rides the persisted one; without the writer neither happens');
    }

    /**
     * A writer door that throws is the third refusal shape (after unknown name
     * and absent door): the catch inside `toggleSkill()` converts it into a
     * `settings write failed` notice — the outer handler's generic
     * `host.skills.error` wording must never be reached here — and NOTHING
     * moves: no file, no live flip. The flip rides the persisted half, so a
     * half-applied toggle is structurally impossible.
     */
    public function testAThrowingWriterDoorRefusesWithTheDedicatedNoticeAndMovesNothing(): void
    {
        $registry = $this->registeredRegistry();
        $writer = SettingsWriter::new($this->config, static function (array $set, array $unset): void {
            throw new \RuntimeException('disk on fire');
        });
        $context = CommandContext::new(
            root: $this->project,
            workspace: WorkspaceContext::new(root: $this->project, skills: $registry)
                ->withService(SettingsWriter::class, $writer),
        );

        $result = (new SkillsHostCommand())->run($context, '/skills disable shipline');
        $last = $result->rows[\count($result->rows) - 1];
        self::assertSame(Role::System, $last->role);
        self::assertStringContainsString('settings write failed', $last->content, 'the dedicated catch, not the generic host.skills.error fall-through');
        self::assertStringContainsString('disk on fire', $last->content, 'the failure names what threw');
        self::assertSame([], $this->persistedList(), 'a throwing door never creates or rewrites the settings file');
        self::assertFalse($registry->isDisabled('shipline'), 'the live flip happens only after the file agrees');
    }

    /** Disabling an already-disabled skill is an idempotent yes, not a duplicate entry. */
    public function testDisablingTwiceKeepsOneEntry(): void
    {
        $context = $this->toggleContext($this->registeredRegistry());
        $this->toggleReply($context, '/skills disable shipline');
        self::assertStringContainsString('Disabled `shipline`', $this->toggleReply($context, '/skills disable shipline'));
        self::assertSame(['shipline'], $this->persistedList());
    }

    /** @return list<string> */
    private function persistedList(): array
    {
        if (!\is_file($this->config)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->config), true);

        return \is_array($data) && \is_array($data['disabledSkills'] ?? null) ? array_values($data['disabledSkills']) : [];
    }

    /**
     * shipline both places production keeps it: a project-tier manifest on
     * disk (so the re-derived table has the row) and a registered row in the
     * live registry the command mutates (so the name check passes against
     * the same shape the launch built).
     */
    private function registeredRegistry(): SkillRegistry
    {
        $dir = $this->project . '/.candy-code/skills/shipline';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/SKILL.md', "---\nname: shipline\ndescription: Ship notes for a release.\n---\n\nToggle body.\n");

        $registry = new SkillRegistry();
        $skill = Skill::parse("---\nname: shipline\ndescription: Ship notes for a release.\n---\n\nToggle body.", 'shipline');
        $registry->register(['shipline' => $skill]);

        return $registry;
    }

    /**
     * The workspace both production drivers hand the command: the launch's
     * registry on `skills` and the launch's one sanctioned writer as a
     * service (the door re-patches the same JSON the boot reads back).
     */
    private function toggleContext(SkillRegistry $registry): CommandContext
    {
        $config = $this->config;
        $writer = SettingsWriter::new($config, static function (array $set, array $unset) use ($config): void {
            $current = \is_file($config) ? (array) \json_decode((string) \file_get_contents($config), true) : [];
            \file_put_contents($config, (string) \json_encode(SettingsWriter::patched($current, $set, $unset)));
        });

        return CommandContext::new(
            root: $this->project,
            workspace: WorkspaceContext::new(root: $this->project, skills: $registry)
                ->withService(SettingsWriter::class, $writer),
        );
    }

    private function toggleReply(CommandContext $context, string $text): string
    {
        $result = (new SkillsHostCommand())->run($context, $text);
        self::assertFalse($result->isRefused(), $text);
        $last = $result->rows[\count($result->rows) - 1];
        self::assertSame(Role::Assistant, $last->role, $text);

        return $last->content;
    }
}
