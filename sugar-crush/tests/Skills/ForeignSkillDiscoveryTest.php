<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\ForeignSkillDiscovery;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;

/**
 * Tests for ForeignSkillDiscovery — imports SKILL.md-shaped directories from
 * other coding-CLI tools' conventions (.claude/skills, .opencode/skills) and
 * tags them with the originating SkillSource for provenance badges.
 */
final class ForeignSkillDiscoveryTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tempDir;
    private string $origHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-foreign-skill-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->origHome = $_SERVER['HOME'] ?? '/root';
        // Every discover*() call also scans the real HOME's foreign-skill
        // dirs; point HOME at an empty sandbox by default so tests aren't
        // polluted by whatever .claude/skills or .opencode/skills happen to
        // exist on the machine running the suite. Tests that specifically
        // exercise home-dir discovery override this to a different fake home.
        $_SERVER['HOME'] = $this->tempDir . '/default-empty-home';
        mkdir($_SERVER['HOME'], 0777, true);
    }

    protected function tearDown(): void
    {
        $_SERVER['HOME'] = $this->origHome;
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function createSkillFile(string $dir, string $name, string $description): void
    {
        $skillDir = $dir . '/' . $name;
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', "---\ndescription: $description\n---\n\nBody.");
    }

    // -------------------------------------------------------------------------
    // discoverClaude()
    // -------------------------------------------------------------------------

    public function testDiscoverClaudeReturnsEmptyWhenNoDirExists(): void
    {
        $discovery = new ForeignSkillDiscovery();
        $projectRoot = $this->tempDir . '/no-claude-here';

        $result = $discovery->discoverClaude($projectRoot);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDiscoverClaudeFindsProjectSkillsAndTagsSource(): void
    {
        $discovery = new ForeignSkillDiscovery();
        $projectRoot = $this->tempDir . '/project';
        $this->createSkillFile($projectRoot . '/.claude/skills', 'imported-skill', 'A Claude Code skill');

        $result = $discovery->discoverClaude($projectRoot);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('imported-skill', $result);
        $this->assertSame(SkillSource::Claude, $result['imported-skill']->source);
        $this->assertSame('A Claude Code skill', $result['imported-skill']->description);
    }

    public function testDiscoverClaudeFindsUserHomeSkills(): void
    {
        $discovery = new ForeignSkillDiscovery();
        $fakeHome = $this->tempDir . '/fake-home';
        $_SERVER['HOME'] = $fakeHome;
        $this->createSkillFile($fakeHome . '/.claude/skills', 'home-skill', 'Home Claude skill');

        $result = $discovery->discoverClaude($this->tempDir . '/empty-project');

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('home-skill', $result);
        $this->assertSame(SkillSource::Claude, $result['home-skill']->source);
    }

    public function testDiscoverClaudeMergesProjectAndHomeWithProjectWinning(): void
    {
        // Both discoverClaude() search-path directories can define the same
        // skill name. The merge is last-write-wins over a
        // lowest-priority-first search order (home then project), so the
        // project checkout wins a shared key -- the same "user < project"
        // precedence SkillLoader::loadAll() documents for native skills.
        $discovery = new ForeignSkillDiscovery();
        $projectRoot = $this->tempDir . '/project2';
        $fakeHome = $this->tempDir . '/fake-home2';
        $_SERVER['HOME'] = $fakeHome;
        $this->createSkillFile($projectRoot . '/.claude/skills', 'shared', 'From project');
        $this->createSkillFile($fakeHome . '/.claude/skills', 'shared', 'From home');
        $this->createSkillFile($fakeHome . '/.claude/skills', 'home-only', 'Home only skill');

        $result = $discovery->discoverClaude($projectRoot);

        $this->assertCount(2, $result);
        $this->assertSame('From project', $result['shared']->description);
        $this->assertArrayHasKey('home-only', $result);
    }

    // -------------------------------------------------------------------------
    // discoverOpencode()
    // -------------------------------------------------------------------------

    public function testDiscoverOpencodeReturnsEmptyWhenNoDirExists(): void
    {
        $discovery = new ForeignSkillDiscovery();
        $projectRoot = $this->tempDir . '/no-opencode-here';

        $result = $discovery->discoverOpencode($projectRoot);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDiscoverOpencodeFindsProjectSkillsAndTagsSource(): void
    {
        $discovery = new ForeignSkillDiscovery();
        $projectRoot = $this->tempDir . '/oc-project';
        $this->createSkillFile($projectRoot . '/.opencode/skills', 'oc-skill', 'An opencode skill');

        $result = $discovery->discoverOpencode($projectRoot);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('oc-skill', $result);
        $this->assertSame(SkillSource::Opencode, $result['oc-skill']->source);
        $this->assertSame('An opencode skill', $result['oc-skill']->description);
    }

    public function testDiscoverOpencodeFindsUserConfigSkills(): void
    {
        $discovery = new ForeignSkillDiscovery();
        $fakeHome = $this->tempDir . '/fake-home-oc';
        $_SERVER['HOME'] = $fakeHome;
        $this->createSkillFile($fakeHome . '/.config/opencode/skills', 'oc-home-skill', 'Home opencode skill');

        $result = $discovery->discoverOpencode($this->tempDir . '/empty-oc-project');

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('oc-home-skill', $result);
        $this->assertSame(SkillSource::Opencode, $result['oc-home-skill']->source);
    }

    public function testDiscoverOpencodeMergesProjectAndHomeWithProjectWinning(): void
    {
        // Same user < project precedence as discoverClaude(); discoverOpencode()
        // shared the identical backwards search order before this was fixed.
        $discovery = new ForeignSkillDiscovery();
        $projectRoot = $this->tempDir . '/oc-project2';
        $fakeHome = $this->tempDir . '/fake-home-oc2';
        $_SERVER['HOME'] = $fakeHome;
        $this->createSkillFile($projectRoot . '/.opencode/skills', 'shared', 'From project');
        $this->createSkillFile($fakeHome . '/.config/opencode/skills', 'shared', 'From home');
        $this->createSkillFile($fakeHome . '/.config/opencode/skills', 'home-only', 'Home only skill');

        $result = $discovery->discoverOpencode($projectRoot);

        $this->assertCount(2, $result);
        $this->assertSame('From project', $result['shared']->description);
        $this->assertArrayHasKey('home-only', $result);
    }

    // -------------------------------------------------------------------------
    // tag() preserves every field except source (regression guard: a hand-
    // enumerated rebuild like tag() is exactly the shape of bug that dropped
    // provenance in Skill::withName() before that method threaded $source —
    // see SkillSourceTest::testWithNamePreservesForeignSource)
    // -------------------------------------------------------------------------

    public function testDiscoveredSkillPreservesAllFieldsExceptSource(): void
    {
        $discovery = new ForeignSkillDiscovery();
        $projectRoot = $this->tempDir . '/full-fields-project';
        $skillDir = $projectRoot . '/.claude/skills/full-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', <<<SKILL
---
description: Full field skill
disable-model-invocation: true
user-invocable: false
allowed-tools: read
disallowed-tools: write
model: gpt-4
effort: high
context: fork
paths:
  - some/path
---

Body content here.
SKILL
        );

        $result = $discovery->discoverClaude($projectRoot);
        $skill = $result['full-skill'];

        $this->assertSame('Full field skill', $skill->description);
        $this->assertTrue($skill->disableModelInvocation);
        $this->assertFalse($skill->userInvocable);
        $this->assertSame('read', $skill->allowedTools);
        $this->assertSame('write', $skill->disallowedTools);
        $this->assertSame('gpt-4', $skill->model);
        $this->assertSame('high', $skill->effort);
        $this->assertSame('fork', $skill->context);
        $this->assertSame(['some/path'], $skill->paths);
        $this->assertSame('Body content here.', $skill->content);
        $this->assertSame(SkillSource::Claude, $skill->source);
    }

    // -------------------------------------------------------------------------
    // constructor default
    // -------------------------------------------------------------------------

    public function testConstructorDefaultsToOwnSkillLoaderInstance(): void
    {
        // No exception thrown when constructed with zero args — confirms the
        // default-value SkillLoader instantiation compiles and works standalone.
        $discovery = new ForeignSkillDiscovery();

        $result = $discovery->discoverClaude($this->tempDir . '/never-created');

        $this->assertIsArray($result);
    }
}
