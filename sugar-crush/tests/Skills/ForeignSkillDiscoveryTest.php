<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\ForeignSkillDiscovery;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Tests for ForeignSkillDiscovery — imports SKILL.md-shaped directories from
 * other coding-CLI tools' conventions (.claude/skills, .opencode/skills) and
 * tags them with the originating SkillSource for provenance badges.
 */
final class ForeignSkillDiscoveryTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-foreign-skill-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        // Every discover*() call also scans the real HOME's foreign-skill
        // dirs; point HOME at an empty sandbox by default so tests aren't
        // polluted by whatever .claude/skills or .opencode/skills happen to
        // exist on the machine running the suite. Tests that specifically
        // exercise home-dir discovery move the sandbox to a different fake
        // home. BOTH spellings of HOME are redirected -- see HomeSandboxTrait.
        $this->useHomeSandbox($this->tempDir . '/default-empty-home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
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
        $this->useHomeSandbox($fakeHome);
        $this->createSkillFile($fakeHome . '/.claude/skills', 'home-skill', 'Home Claude skill');

        $result = $discovery->discoverClaude($this->tempDir . '/empty-project');

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('home-skill', $result);
        $this->assertSame(SkillSource::Claude, $result['home-skill']->source);
    }

    /**
     * THE USER'S OWN COPY WINS. A project's `.claude/skills` arrives with
     * whatever repository was cloned, so letting it win a shared key would let
     * a clone silently re-point a skill the user relies on -- the weaker,
     * prompt-text form of the project-hook-file hole
     * {@see \SugarCraft\Crush\Cli\Bootstrap::hookFiles()} is gated for. The
     * project's skill is still imported; it just may not displace a name that
     * already resolved.
     */
    public function testDiscoverClaudeMergesProjectAndHomeWithTheUsersCopyWinning(): void
    {
        // The merge is last-write-wins over a lowest-priority-first search
        // order, so the search order is project then home.
        $discovery = new ForeignSkillDiscovery();
        $projectRoot = $this->tempDir . '/project2';
        $fakeHome = $this->tempDir . '/fake-home2';
        $this->useHomeSandbox($fakeHome);
        $this->createSkillFile($projectRoot . '/.claude/skills', 'shared', 'From project');
        $this->createSkillFile($projectRoot . '/.claude/skills', 'project-only', 'Project only skill');
        $this->createSkillFile($fakeHome . '/.claude/skills', 'shared', 'From home');
        $this->createSkillFile($fakeHome . '/.claude/skills', 'home-only', 'Home only skill');

        $result = $discovery->discoverClaude($projectRoot);

        $this->assertCount(3, $result);
        $this->assertSame('From home', $result['shared']->description);
        $this->assertArrayHasKey('home-only', $result);
        $this->assertArrayHasKey('project-only', $result, 'a project skill with no collision is still imported');
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
        $this->useHomeSandbox($fakeHome);
        $this->createSkillFile($fakeHome . '/.config/opencode/skills', 'oc-home-skill', 'Home opencode skill');

        $result = $discovery->discoverOpencode($this->tempDir . '/empty-oc-project');

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('oc-home-skill', $result);
        $this->assertSame(SkillSource::Opencode, $result['oc-home-skill']->source);
    }

    public function testDiscoverOpencodeMergesProjectAndHomeWithTheUsersCopyWinning(): void
    {
        // Same project < user precedence as discoverClaude().
        $discovery = new ForeignSkillDiscovery();
        $projectRoot = $this->tempDir . '/oc-project2';
        $fakeHome = $this->tempDir . '/fake-home-oc2';
        $this->useHomeSandbox($fakeHome);
        $this->createSkillFile($projectRoot . '/.opencode/skills', 'shared', 'From project');
        $this->createSkillFile($projectRoot . '/.opencode/skills', 'project-only', 'Project only skill');
        $this->createSkillFile($fakeHome . '/.config/opencode/skills', 'shared', 'From home');
        $this->createSkillFile($fakeHome . '/.config/opencode/skills', 'home-only', 'Home only skill');

        $result = $discovery->discoverOpencode($projectRoot);

        $this->assertCount(3, $result);
        $this->assertSame('From home', $result['shared']->description);
        $this->assertArrayHasKey('home-only', $result);
        $this->assertArrayHasKey('project-only', $result, 'a project skill with no collision is still imported');
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

    // -------------------------------------------------------------------------
    // the user tier's own boundary
    // -------------------------------------------------------------------------

    /**
     * `~/.claude/skills -> <outside $HOME>` is REFUSED.
     *
     * This pair used to pass `null` as the user tier's anchor, justified as
     * "its location is not a repository's choice" — the same premise
     * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresetTiers()} measured
     * false across four launch shapes: a symlink out of `$HOME` arrives in a
     * tarball as readily as in a clone, and the `$ownedBy` check beside it does
     * not catch that, because a tarball extracts as the extracting user.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function foreignUserSkillTiers(): array
    {
        return [
            'claude' => ['discoverClaude', '.claude/skills'],
            'opencode' => ['discoverOpencode', '.config/opencode/skills'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignUserSkillTiers')]
    public function testAUserSkillTreeLinkedOutOfHomeIsRefused(string $method, string $relative): void
    {
        $home = $this->tempDir . '/anchored-home';
        $outside = $this->tempDir . '/outside-skills';
        mkdir($home . '/' . \dirname($relative), 0o700, true);
        mkdir($outside, 0o700, true);
        $this->createSkillFile($outside, 'leaked', 'OUTSIDE-SKILL-DESCRIPTION');
        symlink($outside, $home . '/' . $relative);

        $this->useHomeSandbox($home, create: false);

        $this->assertSame([], array_keys((new ForeignSkillDiscovery())->{$method}($this->tempDir . '/project')));
    }

    /**
     * THE CONTROL: a link elsewhere INSIDE `$HOME` — the layout the old
     * justification was actually defending — still loads.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('foreignUserSkillTiers')]
    public function testAUserSkillTreeLinkedInsideHomeStillLoads(string $method, string $relative): void
    {
        $home = $this->tempDir . '/inside-home';
        mkdir($home . '/' . \dirname($relative), 0o700, true);
        mkdir($home . '/elsewhere', 0o700, true);
        $this->createSkillFile($home . '/elsewhere', 'mine', 'MY-OWN-SKILL');
        symlink($home . '/elsewhere', $home . '/' . $relative);

        $this->useHomeSandbox($home, create: false);

        $this->assertSame(['mine'], array_keys((new ForeignSkillDiscovery())->{$method}($this->tempDir . '/project')));
    }
}
