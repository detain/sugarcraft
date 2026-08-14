<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPreset;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\Agents\Effort;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * Tests for AgentPresetRegistry.
 */
final class AgentPresetRegistryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-registry-test-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Recursively remove the entire temp tree (handles subdirs created by tests)
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // load() - known preset
    // -------------------------------------------------------------------------

    public function testLoadReturnsPresetForKnownName(): void
    {
        $presetContent = <<<'YAML'
---
name: test-coder
description: A coding agent for implementing features and fixing bugs
tools:
  - Read
  - Glob
  - Grep
  - Bash
disallowedTools:
  - Delete
model: sonnet
permissionMode: plan
maxTurns: 50
skills:
  - php-pro
  - code-review
mcpServers:
  - filesystem
memory: project
background: false
effort: high
isolation: worktree
color: "#6366f1"
initialPrompt: You are a skilled coder.
---
# Test Coder Agent

This preset is used for general coding tasks.
YAML;

        $filePath = $this->tempDir . '/test-coder.md';
        file_put_contents($filePath, $presetContent);

        $registry = new AgentPresetRegistry([$this->tempDir]);
        $preset = $registry->load('test-coder');

        $this->assertInstanceOf(AgentPreset::class, $preset);
        $this->assertSame('test-coder', $preset->name);
        $this->assertSame('A coding agent for implementing features and fixing bugs', $preset->description);
        $this->assertSame(['Read', 'Glob', 'Grep', 'Bash'], $preset->tools);
        $this->assertSame(['Delete'], $preset->disallowedTools);
        $this->assertSame('sonnet', $preset->model);
        $this->assertSame(PermissionMode::Plan, $preset->permissionMode);
        $this->assertSame(50, $preset->maxTurns);
        $this->assertSame(['php-pro', 'code-review'], $preset->skills);
        $this->assertSame(['filesystem'], $preset->mcpServers);
        $this->assertSame(MemoryScope::Project, $preset->memory);
        $this->assertFalse($preset->background);
        $this->assertSame(Effort::High, $preset->effort);
        $this->assertSame(Isolation::Worktree, $preset->isolation);
        $this->assertSame('#6366f1', $preset->color);
        $this->assertSame('You are a skilled coder.', $preset->initialPrompt);
    }

    public function testLoadSearchesPathsInOrder(): void
    {
        $dir1 = $this->tempDir . '/path1';
        $dir2 = $this->tempDir . '/path2';
        mkdir($dir1, 0777, true);
        mkdir($dir2, 0777, true);

        $presetContent1 = <<<'YAML'
---
name: my-preset
description: First preset
tools: []
---
# First
YAML;

        $presetContent2 = <<<'YAML'
---
name: my-preset
description: Second preset
tools: []
---
# Second
YAML;

        file_put_contents($dir1 . '/my-preset.md', $presetContent1);
        file_put_contents($dir2 . '/my-preset.md', $presetContent2);

        $registry = new AgentPresetRegistry([$dir1, $dir2]);
        $preset = $registry->load('my-preset');

        // First path takes precedence
        $this->assertSame('First preset', $preset->description);
    }

    // -------------------------------------------------------------------------
    // load() - unknown preset throws
    // -------------------------------------------------------------------------

    public function testLoadThrowsExceptionForUnknownPreset(): void
    {
        $registry = new AgentPresetRegistry([$this->tempDir]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Preset 'nonexistent' not found in search paths.");

        $registry->load('nonexistent');
    }

    public function testLoadThrowsWhenSearchPathsAreEmpty(): void
    {
        $registry = new AgentPresetRegistry(['/nonexistent/path']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Preset 'missing' not found in search paths.");

        $registry->load('missing');
    }

    // -------------------------------------------------------------------------
    // list() - returns array of presets
    // -------------------------------------------------------------------------

    public function testListReturnsAllPresetsFromAllPaths(): void
    {
        $dir1 = $this->tempDir . '/list1';
        $dir2 = $this->tempDir . '/list2';
        mkdir($dir1, 0777, true);
        mkdir($dir2, 0777, true);

        $this->writePreset($dir1 . '/alpha.md', 'alpha', 'Alpha preset for testing');
        $this->writePreset($dir1 . '/beta.md', 'beta', 'Beta preset for testing');
        $this->writePreset($dir2 . '/gamma.md', 'gamma', 'Gamma preset for testing');

        $registry = new AgentPresetRegistry([$dir1, $dir2]);
        $presets = $registry->list();

        $this->assertIsArray($presets);
        $this->assertCount(3, $presets);
        $this->assertArrayHasKey('alpha', $presets);
        $this->assertArrayHasKey('beta', $presets);
        $this->assertArrayHasKey('gamma', $presets);
        $this->assertSame('Alpha preset for testing', $presets['alpha']->description);
        $this->assertSame('Beta preset for testing', $presets['beta']->description);
        $this->assertSame('Gamma preset for testing', $presets['gamma']->description);
    }

    public function testListReturnsEmptyArrayWhenNoPresets(): void
    {
        $registry = new AgentPresetRegistry([$this->tempDir]);
        $presets = $registry->list();

        $this->assertIsArray($presets);
        $this->assertEmpty($presets);
    }

    public function testListSkipsNonExistentDirectories(): void
    {
        $registry = new AgentPresetRegistry(['/nonexistent/path', $this->tempDir]);
        $presets = $registry->list();

        $this->assertIsArray($presets);
    }

    public function testListNameCollisionFirstPathWins(): void
    {
        $dir1 = $this->tempDir . '/coll1';
        $dir2 = $this->tempDir . '/coll2';
        mkdir($dir1, 0777, true);
        mkdir($dir2, 0777, true);

        $this->writePreset($dir1 . '/shared.md', 'shared', 'First shared preset');
        $this->writePreset($dir2 . '/shared.md', 'shared', 'Second shared preset');

        $registry = new AgentPresetRegistry([$dir1, $dir2]);
        $presets = $registry->list();

        // First search path wins on name conflicts (if (!isset(...)))
        $this->assertCount(1, $presets);
        $this->assertSame('First shared preset', $presets['shared']->description);
    }

    // -------------------------------------------------------------------------
    // resolve() - description substring matching
    // -------------------------------------------------------------------------

    public function testResolveMatchesDescriptionSubstring(): void
    {
        $this->writePreset(
            $this->tempDir . '/coder.md',
            'coder',
            'A coding agent that writes and edits source code files'
        );

        $registry = new AgentPresetRegistry([$this->tempDir]);

        // "coding" + "source" + "code" = 3 overlapping keywords (>=2 threshold)
        $matched = $registry->resolve('I need help with coding and source code');

        $this->assertNotNull($matched);
        $this->assertSame('coder', $matched->name);
    }

    public function testResolveReturnsNullWhenNoMatch(): void
    {
        $this->writePreset(
            $this->tempDir . '/coder.md',
            'coder',
            'A coding agent for PHP development tasks'
        );

        $registry = new AgentPresetRegistry([$this->tempDir]);

        // Only "for" overlaps (stop word, filtered out) — score < 2
        $matched = $registry->resolve('Please do something for me');

        $this->assertNull($matched);
    }

    public function testResolveReturnsNullForEmptyDescription(): void
    {
        $this->writePreset(
            $this->tempDir . '/empty.md',
            'empty',
            ''
        );

        $registry = new AgentPresetRegistry([$this->tempDir]);

        $matched = $registry->resolve('Any task description here');

        $this->assertNull($matched);
    }

    public function testResolveReturnsNullWhenTaskHasNoKeywords(): void
    {
        $this->writePreset(
            $this->tempDir . '/web.md',
            'web',
            'A web developer agent for building websites'
        );

        $registry = new AgentPresetRegistry([$this->tempDir]);

        // "a the" — all stop words filtered out, no keywords remain
        $matched = $registry->resolve('a the');

        $this->assertNull($matched);
    }

    public function testResolveSelectsHighestScoringPreset(): void
    {
        $dir1 = $this->tempDir . '/resolve1';
        $dir2 = $this->tempDir . '/resolve2';
        mkdir($dir1, 0777, true);
        mkdir($dir2, 0777, true);

        $this->writePreset($dir1 . '/coder.md', 'coder', 'A coding agent for PHP and JavaScript programming');
        $this->writePreset($dir2 . '/designer.md', 'designer', 'A designer for UI and visual elements');

        $registry = new AgentPresetRegistry([$dir1, $dir2]);

        // "coding" matches coder (3 keywords: coding, php, javascript)
        // "designing" matches designer (2 keywords: designer, ui)
        // coders has more overlap
        $matched = $registry->resolve('I need help with coding PHP classes');

        $this->assertNotNull($matched);
        $this->assertSame('coder', $matched->name);
    }

    public function testResolveRequiresMinimumScoreOfTwo(): void
    {
        $this->writePreset(
            $this->tempDir . '/coder.md',
            'coder',
            'A coding agent for PHP development'
        );

        $registry = new AgentPresetRegistry([$this->tempDir]);

        // Only "coding" overlaps (1 keyword) — below threshold of 2
        $matched = $registry->resolve('I need help with coding');

        $this->assertNull($matched);
    }

    public function testResolveReturnsNullWhenNoPresetsAvailable(): void
    {
        $registry = new AgentPresetRegistry([$this->tempDir]);

        $matched = $registry->resolve('Any task at all here please');

        $this->assertNull($matched);
    }

    // -------------------------------------------------------------------------
    // Malformed files
    // -------------------------------------------------------------------------

    public function testLoadThrowsOnMissingFrontmatter(): void
    {
        $filePath = $this->tempDir . '/no-frontmatter.md';
        file_put_contents($filePath, "# Just a header\n\nNo YAML here.");

        $registry = new AgentPresetRegistry([$this->tempDir]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No YAML frontmatter found');

        $registry->load('no-frontmatter');
    }

    public function testLoadThrowsOnInvalidYaml(): void
    {
        $filePath = $this->tempDir . '/bad-yaml.md';
        file_put_contents($filePath, "---\nname: test\ndescription: [invalid yaml\n---\n");

        $registry = new AgentPresetRegistry([$this->tempDir]);

        try {
            $registry->load('bad-yaml');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            // The RuntimeException wraps Symfony's ParseException; the actual
            // message is the ParseException message since that is what was
            // re-thrown. Verify it is non-empty (YAML parse failure occurred).
            $this->assertNotEmpty($e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // load() - path-traversal / sibling-directory hardening (R23)
    // -------------------------------------------------------------------------

    /**
     * Reproduces the original bug: a raw str_starts_with() prefix check treats
     * "agents-secrets" as "inside" "agents" because the string "agents-secrets"
     * literally starts with the string "agents". A "../<name>" traversal from
     * within the legitimate "agents" search path must NOT be able to resolve
     * into the sibling "agents-secrets" directory.
     */
    public function testLoadRejectsSiblingDirectoryThatSharesNamePrefix(): void
    {
        $agentsDir = $this->tempDir . '/agents';
        $secretsDir = $this->tempDir . '/agents-secrets';
        mkdir($agentsDir, 0777, true);
        mkdir($secretsDir, 0777, true);

        $this->writePreset($secretsDir . '/passwords.md', 'passwords', 'Should never be reachable from agents/');

        $registry = new AgentPresetRegistry([$agentsDir]);

        // "../agents-secrets/passwords" traverses out of "agents" and into the
        // sibling "agents-secrets" dir, which merely SHARES a string prefix
        // with the legitimate search path — it must be rejected, not loaded.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Preset '../agents-secrets/passwords' not found in search paths.");

        $registry->load('../agents-secrets/passwords');
    }

    /**
     * A file that genuinely lives inside the search path (not just sharing a
     * string prefix with a sibling) must still load normally after the fix.
     */
    public function testLoadStillWorksForFileGenuinelyInsideSearchPath(): void
    {
        $agentsDir = $this->tempDir . '/agents';
        mkdir($agentsDir, 0777, true);

        $this->writePreset($agentsDir . '/legit.md', 'legit', 'A legitimate in-bounds preset');

        $registry = new AgentPresetRegistry([$agentsDir]);
        $preset = $registry->load('legit');

        $this->assertSame('legit', $preset->name);
        $this->assertSame('A legitimate in-bounds preset', $preset->description);
    }

    // -------------------------------------------------------------------------
    // arrayToPreset() - filename fallback when no name: key is set (R23)
    // -------------------------------------------------------------------------

    public function testLoadFallsBackToFilenameWhenNameKeyIsMissing(): void
    {
        $filePath = $this->tempDir . '/unnamed-preset.md';
        $content = <<<'YAML'
---
description: A preset with no explicit name field
tools: []
---
# Unnamed
YAML;
        file_put_contents($filePath, $content);

        $registry = new AgentPresetRegistry([$this->tempDir]);
        $preset = $registry->load('unnamed-preset');

        // Falls back to the real filename passed into the parser, not the
        // dead '_file' key which is never actually populated in $data.
        $this->assertSame('unnamed-preset', $preset->name);
        $this->assertSame('A preset with no explicit name field', $preset->description);
    }

    // -------------------------------------------------------------------------
    // Real shipped presets under .sugar-crush/agents/ (R23 coverage gap)
    // -------------------------------------------------------------------------

    public function testLoadsRealShippedCoderPreset(): void
    {
        $registry = new AgentPresetRegistry([$this->shippedAgentsDir()]);
        $preset = $registry->load('coder');

        $this->assertSame('coder', $preset->name);
        $this->assertSame(
            'Implements features and fixes bugs in PHP code; writes new files, edits existing code, and runs tests.',
            $preset->description
        );
        $this->assertSame(['Read', 'Write', 'Edit', 'Bash', 'Grep'], $preset->tools);
        $this->assertSame(PermissionMode::AcceptEdits, $preset->permissionMode);
        $this->assertSame(Isolation::Worktree, $preset->isolation);
    }

    public function testLoadsRealShippedReviewerPreset(): void
    {
        $registry = new AgentPresetRegistry([$this->shippedAgentsDir()]);
        $preset = $registry->load('reviewer');

        $this->assertSame('reviewer', $preset->name);
        $this->assertSame(PermissionMode::Plan, $preset->permissionMode);
        $this->assertSame(['Write', 'Edit'], $preset->disallowedTools);
        $this->assertSame(30, $preset->maxTurns);
    }

    public function testLoadsRealShippedSecurityAuditorPreset(): void
    {
        $registry = new AgentPresetRegistry([$this->shippedAgentsDir()]);
        $preset = $registry->load('security-auditor');

        $this->assertSame('security-auditor', $preset->name);
        $this->assertSame('sonnet', $preset->model);
        $this->assertSame(['git'], $preset->mcpServers);
        $this->assertSame(Isolation::Worktree, $preset->isolation);
    }

    // -------------------------------------------------------------------------
    // Markdown body as the preset prompt
    // -------------------------------------------------------------------------

    /**
     * A preset whose prompt is written as the markdown BODY — the Claude Code
     * and opencode convention — must reach `initialPrompt`.
     *
     * The parser only ever looked at the frontmatter, so a body-authored
     * `reviewer.md` produced `initialPrompt: null`, and `Agent::fromPreset()`'s
     * `?? ''` then registered the agent with an EMPTY prompt.
     *
     * Fails if the fix is reverted: `initialPrompt` is null, so both assertions
     * below fail immediately.
     */
    public function testMarkdownBodyBecomesTheInitialPromptWhenFrontmatterDeclaresNone(): void
    {
        file_put_contents(
            $this->tempDir . '/body-authored.md',
            "---\nname: body-authored\ndescription: Prompt lives in the body\n---\n\n"
                . "You are a meticulous reviewer.\n\nQuote the line numbers you cite.\n",
        );

        $preset = (new AgentPresetRegistry([$this->tempDir]))->load('body-authored');

        $this->assertSame(
            "You are a meticulous reviewer.\n\nQuote the line numbers you cite.",
            $preset->initialPrompt,
        );
        // list() is the method Bootstrap uses, so it has to agree with load().
        $this->assertSame(
            $preset->initialPrompt,
            (new AgentPresetRegistry([$this->tempDir]))->list()['body-authored']->initialPrompt,
        );
    }

    /**
     * A declared `initialPrompt:` is the more specific statement of intent, so
     * it wins over a body that is only commentary.
     */
    public function testDeclaredInitialPromptWinsOverTheMarkdownBody(): void
    {
        file_put_contents(
            $this->tempDir . '/both.md',
            "---\nname: both\ndescription: Declares both\ninitialPrompt: The declared prompt.\n---\n\n"
                . "Notes for humans, not for the model.\n",
        );

        $preset = (new AgentPresetRegistry([$this->tempDir]))->load('both');

        $this->assertSame('The declared prompt.', $preset->initialPrompt);
    }

    /** A frontmatter-only preset still reports "no prompt" as null, not ''. */
    public function testAPresetWithNoBodyAndNoDeclaredPromptStillHasANullInitialPrompt(): void
    {
        file_put_contents(
            $this->tempDir . '/bare.md',
            "---\nname: bare\ndescription: Nothing but frontmatter\n---\n",
        );

        $this->assertNull((new AgentPresetRegistry([$this->tempDir]))->load('bare')->initialPrompt);
    }

    // -------------------------------------------------------------------------
    // list() path containment
    // -------------------------------------------------------------------------

    /**
     * `list()` must apply the containment check `load()` already applies.
     *
     * `load()` resolves the candidate with realpath() and refuses anything that
     * lands outside the search path; `list()` — the method
     * `Bootstrap::agentPresets()` actually calls — just globbed, so a symlink
     * `agents/link.md -> ../outside/stolen.md` registered an agent from a file
     * the search path does not contain. Same trust boundary, two answers.
     *
     * Fails if the fix is reverted: the unguarded glob parses the symlink and
     * `list()` returns the key 'link', tripping assertArrayNotHasKey.
     */
    public function testListRefusesAPresetSymlinkedInFromOutsideTheSearchPath(): void
    {
        $agents = $this->tempDir . '/agents';
        $outside = $this->tempDir . '/outside';
        mkdir($agents, 0777, true);
        mkdir($outside, 0777, true);

        $this->writePreset($agents . '/genuine.md', 'genuine', 'Lives in the search path');
        $this->writePreset($outside . '/stolen.md', 'stolen', 'Does not');
        symlink($outside . '/stolen.md', $agents . '/link.md');

        $registry = new AgentPresetRegistry([$agents]);
        $presets = $registry->list();

        $this->assertArrayHasKey('genuine', $presets);
        $this->assertArrayNotHasKey('link', $presets);
        $this->assertArrayNotHasKey('stolen', $presets);

        // The two methods now agree: load() already refused this file.
        $this->expectException(\RuntimeException::class);
        $registry->load('link');
    }

    /**
     * The guard must not reject the ordinary case of a search path that is
     * itself reached through a symlink (a symlinked project directory, or
     * /tmp -> /private/tmp on macOS): realpath() normalises BOTH sides, so
     * containment still holds.
     */
    public function testListStillFindsPresetsWhenTheSearchPathItselfIsASymlink(): void
    {
        $real = $this->tempDir . '/real-agents';
        mkdir($real, 0777, true);
        $this->writePreset($real . '/genuine.md', 'genuine', 'Lives in the real directory');

        $linked = $this->tempDir . '/linked-agents';
        symlink($real, $linked);

        try {
            $presets = (new AgentPresetRegistry([$linked]))->list();

            $this->assertArrayHasKey('genuine', $presets);
        } finally {
            // Removed here, not by tearDown(): removeDir() treats a
            // symlink-to-directory as a directory and would rmdir() it, which
            // warns -- and phpunit.xml sets failOnWarning="true".
            unlink($linked);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Absolute path to the repo's shipped .sugar-crush/agents/ preset directory.
     */
    private function shippedAgentsDir(): string
    {
        return dirname(__DIR__, 2) . '/.sugar-crush/agents';
    }

    private function writePreset(string $filePath, string $name, string $description): void
    {
        $content = <<<YAML
---
name: {$name}
description: {$description}
tools: []
---
# {$name}
YAML;
        file_put_contents($filePath, $content);
    }
}
