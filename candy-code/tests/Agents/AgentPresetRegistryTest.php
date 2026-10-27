<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentPreset;
use SugarCraft\Code\Agents\AgentPresetRegistry;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Agents\Isolation;
use SugarCraft\Code\Agents\MemoryScope;
use SugarCraft\Code\Permissions\PermissionMode;

/**
 * Tests for AgentPresetRegistry.
 */
final class AgentPresetRegistryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy-code-registry-test-' . uniqid('', true);
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

    /**
     * E661: the `!is_array()` guard of parsePresetFile() ("Invalid YAML
     * frontmatter in:") is the local sibling of the branch E575 proved
     * REACHED for the foreign registry — frontmatter that parses CLEANLY to
     * a non-array. Frontmatter::parse() publishes exactly those shapes: NULL
     * for an empty or comment-only block, a scalar for a bare value. No
     * earlier guard fires on them, and THIS registry fails loud (the foreign
     * one skips) naming the file. Each shape is its own fixture and its own
     * assertion so only the file that broke can satisfy the message.
     */
    public function testFrontmatterThatParsesToAScalarThrowsNamingTheFile(): void
    {
        $dir = $this->tempDir . '/scalar-frontmatter';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/scalar.md', "---\n42\n---\nBody.");

        $failure = null;
        try {
            (new AgentPresetRegistry([$dir]))->load('scalar');
        } catch (\RuntimeException $caught) {
            $failure = $caught;
        }

        $this->assertNotNull($failure, 'a cleanly-parsing scalar frontmatter must not load');
        $this->assertStringContainsString('Invalid YAML frontmatter in:', $failure->getMessage());
        $this->assertStringContainsString('scalar.md', $failure->getMessage());
    }

    public function testCommentOnlyFrontmatterThrowsNamingTheFile(): void
    {
        $dir = $this->tempDir . '/comment-only-frontmatter';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/note.md', "---\n# just a note\n---\nBody.");

        $failure = null;
        try {
            (new AgentPresetRegistry([$dir]))->load('note');
        } catch (\RuntimeException $caught) {
            $failure = $caught;
        }

        $this->assertNotNull($failure, 'a cleanly-parsing comment-only frontmatter must not load');
        $this->assertStringContainsString('Invalid YAML frontmatter in:', $failure->getMessage());
        $this->assertStringContainsString('note.md', $failure->getMessage());
    }

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

        // The reason this shape is forbidden tree-wide, and the reason the
        // `fail()` that used to sit inside this try now sits after it: `fail()`
        // throws PHPUnit\Framework\AssertionFailedError, and MEASURED on
        // PHPUnit 10.5.64 its parents are PHPUnit\Framework\Exception ->
        // \RuntimeException -> \Exception. The catch below therefore caught the
        // fail() standing one line above it, and `assertNotEmpty()` was then
        // satisfied by the fail() message itself. In the old shape this test
        // asserted that load() throws while being unable to tell whether load()
        // was called at all. {@see \SugarCraft\Code\Tests\SwallowingCatchCensusTest}
        // for the guard that now refuses the shape tree-wide.
        $caught = null;

        try {
            $registry->load('bad-yaml');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected RuntimeException was not thrown');

        // WHAT THIS SAID: "The RuntimeException wraps Symfony's ParseException".
        // WHAT IS TRUE NOW: nothing wraps it. MEASURED on PHP 8.3.6 against this
        // exact fixture, load() lets Symfony's own
        // Symfony\Component\Yaml\Exception\ParseException escape uncaught --
        // getPrevious() is null and get_class() is ParseException, which reaches
        // the catch above only because ParseException is itself a
        // \RuntimeException. AgentPresetRegistry::parsePresetFile() does own a
        // message that WOULD name the file, "Invalid YAML frontmatter in:
        // {$filePath}", but that is the OTHER branch -- the one for frontmatter
        // that parses to a non-array -- and malformed YAML never reaches it
        // because Frontmatter::parse() throws first. An earlier draft of this
        // test asserted the message contained 'bad-yaml.md' for exactly that
        // reason and was red.
        // WHY THIS STILL EARNS ITS PLACE: assertNotEmpty() alone is satisfied by
        // ANY message, including the one a swallowed fail() would have carried,
        // so it is paired with text this parse failure genuinely emits.
        $this->assertNotEmpty($caught->getMessage());
        $this->assertStringContainsString(
            'Malformed inline YAML',
            $caught->getMessage(),
            'load() threw, but not for the YAML parse failure this fixture writes -- so the '
            . 'test is pinning some other refusal and the parse path could be gone',
        );
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
    // Real shipped presets under .candy-code/agents/ (R23 coverage gap)
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
        $this->assertSame(['Read', 'Write', 'Edit', 'Bash', 'Grep', 'Skill'], $preset->tools);
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

    // -------------------------------------------------------------------------
    // rv-qa-3 / rv-enduser-7 — the package's own presets are never audited
    // -------------------------------------------------------------------------

    public function testThePackageBundledPresetsAreNeverAudited(): void
    {
        $registry = new AgentPresetRegistry([$this->shippedAgentsDir()]);

        $this->assertNotEmpty($registry->list());
        $this->assertSame([], $registry->ignoredFrontmatter(), 'shipping a preset is not a finding to report to the user');
    }

    public function testTheAuditStillSoundsForPresetsOutsideThePackage(): void
    {
        // C-AGT-3.b retired the preset's last inert key, so the sounding now
        // rides the unknown half: a key this format does not know at all.
        file_put_contents($this->tempDir . '/loud.md', <<<'YAML'
---
name: loud
description: A preset declaring a key this build does not know
enable: false
---
# loud
YAML);

        $registry = new AgentPresetRegistry([$this->tempDir]);
        $registry->list();

        $ignored = $registry->ignoredFrontmatter();
        $this->assertArrayHasKey('loud', $ignored);
        $this->assertStringContainsString('enable', implode(' ', $ignored['loud']));
    }

    /**
     * C-SES-9.c — a phar install keeps its bundled presets visible to the
     * audit gate. {@see AgentPresetRegistry::bundledPresetsDirectory()} used
     * to realpath() its `__DIR__`-derived candidate, and realpath() answers
     * false for every `phar://` path, so the gate collapsed to "nothing is
     * bundled" inside the published artifact — the exact opposite of its
     * contract (see the disclosure this step retired in tools/build-phar.php).
     *
     * The proof runs as a child PHP: archive WRITING needs `phar.readonly=0`,
     * which — like tests/Integration/PharCompatTest.php's own scratch builds —
     * lives in the argv only, never in the suite ini. The child mints the
     * scratch phar the way PharCompatTest::writePremisePhar() mints its
     * premise (setStub + addFromString + stopBuffering + unset), packs this
     * very class file at `src/Agents/AgentPresetRegistry.php` plus two preset
     * entries under `.candy-code/agents/`, requires the class from inside the
     * archive so its `__DIR__` IS a `phar://` path, and reports through JSON.
     * No roster entry and no skip fires on the gate box: ext-phar is loaded
     * and PHP_BINARY spawns here, so an arm that cannot run says so out loud
     * through the rc assertion.
     */
    public function testAPharInstallResolvesTheBundledPresetsInsideTheArchive(): void
    {
        if (!extension_loaded('phar')) {
            $this->markTestSkipped('ext-phar is not loaded in this process');
        }

        $registrySource = (new \ReflectionClass(AgentPresetRegistry::class))->getFileName();
        $this->assertIsString($registrySource);

        $pharPath = $this->tempDir . '/c-ses-9c-probe.phar';
        $childScript = $this->tempDir . '/c-ses-9c-child.php';
        file_put_contents($childScript, self::PHAR_PROBE_CHILD);

        $pipes = [];
        $process = proc_open(
            [\PHP_BINARY, '-d', 'phar.readonly=0', $childScript, $pharPath, $registrySource],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!\is_resource($process)) {
            $this->markTestSkipped('the scratch-phar child could not be spawned on this box');
        }

        // Drain both pipes to EOF before reaping (pipe-EOF law), then close.
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($process);

        $this->assertSame(0, $rc, 'the scratch-phar child exited ' . $rc . ': ' . $err);
        $report = json_decode($out, true);
        $this->assertIsArray($report, 'the child printed no JSON report: ' . $out . ' / ' . $err);
        $this->assertTrue((bool) ($report['ok'] ?? false), 'child reported: ' . (string) ($report['error'] ?? $err));

        $this->assertSame(
            'phar://' . realpath($pharPath) . '/.candy-code/agents',
            $report['dir'],
            'the bundled gate must name the directory INSIDE the archive, un-realpathed'
        );
        $this->assertSame(['bundled-alpha.md', 'bundled-beta.md'], $report['names'], 'scandir() must list both packed presets');
        $this->assertTrue($report['inPhar'], 'a preset inside the archive is a bundled preset');
        $this->assertFalse($report['outsidePhar'], 'a real file on disk is not inside the archive');
    }

    /**
     * The child side of the phar probe above. Reads its two inputs from
     * argv; prints one JSON report to stdout. Nowdoc so nothing here is
     * evaluated by the parent process.
     */
    private const PHAR_PROBE_CHILD = <<<'PHP'
<?php
$report = ['ok' => false, 'error' => null, 'dir' => null, 'names' => [], 'inPhar' => null, 'outsidePhar' => null];
try {
    $out = (string) $argv[1];
    $src = (string) $argv[2];
    if (file_exists($out)) {
        unlink($out);
    }
    $phar = new Phar($out, 0, basename($out));
    $phar->setStub('<?php __HALT_COMPILER();');
    $phar->addFromString('src/Agents/AgentPresetRegistry.php', (string) file_get_contents($src));
    $entries = ['bundled-alpha.md', 'bundled-beta.md'];
    foreach ($entries as $entry) {
        $phar->addFromString('.candy-code/agents/' . $entry, "---\nname: {$entry}\ndescription: packed by the c-ses-9c probe\ntools: []\n---\n# {$entry}\n");
    }
    $phar->stopBuffering();
    unset($phar);

    require 'phar://' . $out . '/src/Agents/AgentPresetRegistry.php';
    $class = 'SugarCraft\\Code\\Agents\\AgentPresetRegistry';
    $dirMethod = new ReflectionMethod($class, 'bundledPresetsDirectory');
    $dirMethod->setAccessible(true);
    $fileMethod = new ReflectionMethod($class, 'isBundledPresetPath');
    $fileMethod->setAccessible(true);

    $dir = $dirMethod->invoke(null);
    $report['dir'] = $dir;
    $report['names'] = $dir === null ? [] : array_values(array_filter(
        scandir($dir),
        static fn (string $item): bool => str_ends_with($item, '.md')
    ));
    $report['inPhar'] = $dir === null ? null : $fileMethod->invoke(null, $dir . '/' . $entries[0]);
    $report['outsidePhar'] = $fileMethod->invoke(null, $src);
    $report['ok'] = true;
} catch (Throwable $e) {
    $report['error'] = $e->getMessage();
    fwrite(STDERR, (string) $e . "\n");
}
fwrite(STDOUT, (string) json_encode($report));
exit($report['ok'] ? 0 : 1);
PHP;

    /**
     * Absolute path to the repo's shipped .candy-code/agents/ preset directory.
     */
    private function shippedAgentsDir(): string
    {
        return dirname(__DIR__, 2) . '/.candy-code/agents';
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
