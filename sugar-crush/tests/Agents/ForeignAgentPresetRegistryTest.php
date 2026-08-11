<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPreset;
use SugarCraft\Crush\Agents\Effort;
use SugarCraft\Crush\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;

/**
 * Tests for ForeignAgentPresetRegistry — imports Claude Code and opencode
 * agent definitions onto AgentPreset, tagged with the originating SkillSource,
 * and reports the permission rules that cannot survive the mapping.
 */
final class ForeignAgentPresetRegistryTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tempDir;
    private string $origHome;
    private string $origErrorLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-foreign-agent-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->origHome = $_SERVER['HOME'] ?? '/root';
        // Every discover* call also scans the real HOME's foreign-agent dirs;
        // point HOME at an empty sandbox so the machine running the suite
        // cannot leak its own .claude/agents into an assertion.
        $_SERVER['HOME'] = $this->tempDir . '/default-empty-home';
        mkdir($_SERVER['HOME'], 0777, true);
        // Keep the lossy-mapping error_log() calls out of the suite's stderr.
        $this->origErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->tempDir . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->origErrorLog);
        $_SERVER['HOME'] = $this->origHome;
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function writeAgent(string $dir, string $name, string $frontmatter, string $body = 'Body.'): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $name . '.md', "---\n{$frontmatter}\n---\n\n{$body}");
    }

    // -------------------------------------------------------------------------
    // discoverClaude()
    // -------------------------------------------------------------------------

    public function testDiscoverClaudeReturnsEmptyWhenNoDirExists(): void
    {
        $registry = new ForeignAgentPresetRegistry();

        $result = $registry->discoverClaude($this->tempDir . '/no-claude-here');

        $this->assertSame([], $result);
    }

    public function testDiscoverClaudeFindsProjectAgentsAndTagsSource(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/project';
        $this->writeAgent(
            $projectRoot . '/.claude/agents',
            'reviewer',
            "name: reviewer\ndescription: Reviews pull requests",
        );

        $result = $registry->discoverClaude($projectRoot);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('reviewer', $result);
        $this->assertSame('reviewer', $result['reviewer']->name);
        $this->assertSame('Reviews pull requests', $result['reviewer']->description);
        $this->assertSame(SkillSource::Claude, $result['reviewer']->source);
    }

    public function testDiscoverClaudeFindsUserHomeAgents(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $fakeHome = $this->tempDir . '/fake-home';
        $_SERVER['HOME'] = $fakeHome;
        $this->writeAgent($fakeHome . '/.claude/agents', 'home-agent', 'description: A home agent');

        $result = $registry->discoverClaude($this->tempDir . '/empty-project');

        $this->assertArrayHasKey('home-agent', $result);
        $this->assertSame(SkillSource::Claude, $result['home-agent']->source);
        // No `name:` key — the filename stem is the fallback, matching
        // AgentPresetRegistry's own name-from-filename behaviour.
        $this->assertSame('home-agent', $result['home-agent']->name);
    }

    public function testDiscoverClaudeMergesProjectAndHomeWithProjectWinning(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/project2';
        $fakeHome = $this->tempDir . '/fake-home2';
        $_SERVER['HOME'] = $fakeHome;
        $this->writeAgent($projectRoot . '/.claude/agents', 'shared', 'description: From project');
        $this->writeAgent($fakeHome . '/.claude/agents', 'shared', 'description: From home');
        $this->writeAgent($fakeHome . '/.claude/agents', 'home-only', 'description: Home only');

        $result = $registry->discoverClaude($projectRoot);

        $this->assertCount(2, $result);
        $this->assertSame('From project', $result['shared']->description);
        $this->assertArrayHasKey('home-only', $result);
    }

    public function testDiscoverClaudeMapsEveryFrontmatterField(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/full-claude';
        $this->writeAgent($projectRoot . '/.claude/agents', 'full', <<<'YAML'
            name: full-agent
            description: Every field set
            tools:
              - Read
              - Glob
            disallowedTools:
              - Bash
            model: opus
            permissionMode: accept-edits
            maxTurns: 12
            skills:
              - php-best-practices
            mcpServers:
              - filesystem
            memory: project
            background: true
            effort: xhigh
            isolation: worktree
            color: "#ff0000"
            initialPrompt: You are a reviewer.
            YAML);

        $preset = $registry->discoverClaude($projectRoot)['full'];

        $this->assertSame('full-agent', $preset->name);
        $this->assertSame(['Read', 'Glob'], $preset->tools);
        $this->assertSame(['Bash'], $preset->disallowedTools);
        $this->assertSame('opus', $preset->model);
        $this->assertSame(PermissionMode::AcceptEdits, $preset->permissionMode);
        $this->assertSame(12, $preset->maxTurns);
        $this->assertSame(['php-best-practices'], $preset->skills);
        $this->assertSame(['filesystem'], $preset->mcpServers);
        $this->assertSame(MemoryScope::Project, $preset->memory);
        $this->assertTrue($preset->background);
        $this->assertSame(Effort::XHigh, $preset->effort);
        $this->assertSame(Isolation::Worktree, $preset->isolation);
        $this->assertSame('#ff0000', $preset->color);
        $this->assertSame('You are a reviewer.', $preset->initialPrompt);
        $this->assertSame(SkillSource::Claude, $preset->source);
    }

    public function testDiscoverClaudeAcceptsCommaSeparatedToolStrings(): void
    {
        // Claude Code writes `tools: Read, Grep` as a scalar far more often
        // than as a YAML list; handing that straight to AgentPreset's array
        // parameter is a TypeError, so the string form must be split here.
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/csv-tools';
        $this->writeAgent(
            $projectRoot . '/.claude/agents',
            'csv',
            "description: CSV tools\ntools: Read, Grep , Glob\ndisallowedTools: Bash",
        );

        $preset = $registry->discoverClaude($projectRoot)['csv'];

        $this->assertSame(['Read', 'Grep', 'Glob'], $preset->tools);
        $this->assertSame(['Bash'], $preset->disallowedTools);
    }

    public function testDiscoverClaudeFallsBackToDefaultsForUnknownEnumValues(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/bad-enums';
        $this->writeAgent(
            $projectRoot . '/.claude/agents',
            'odd',
            "description: Unknown enum values\npermissionMode: teleport\neffort: gigantic\nmemory: cloud\nisolation: container",
        );

        $preset = $registry->discoverClaude($projectRoot)['odd'];

        $this->assertSame(PermissionMode::Default, $preset->permissionMode);
        $this->assertSame(Effort::Medium, $preset->effort);
        $this->assertSame(MemoryScope::User, $preset->memory);
        $this->assertNull($preset->isolation);
    }

    public function testDiscoverClaudeAcceptsCamelCasePermissionModeSpellings(): void
    {
        // A real Claude Code agent file spells these camelCase; sugar-crush's
        // PermissionMode is kebab-case. Lowercasing alone would leave both
        // silently falling back to PermissionMode::Default.
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/camel-enums';
        $dir = $projectRoot . '/.claude/agents';
        $this->writeAgent($dir, 'cc', "description: camelCase mode\npermissionMode: acceptEdits");
        $this->writeAgent($dir, 'yolo', "description: camelCase mode\npermissionMode: bypassPermissions");
        $this->writeAgent($dir, 'quiet', "description: camelCase mode\npermissionMode: dontAsk");

        $result = $registry->discoverClaude($projectRoot);

        $this->assertSame(PermissionMode::AcceptEdits, $result['cc']->permissionMode);
        $this->assertSame(PermissionMode::BypassPermissions, $result['yolo']->permissionMode);
        $this->assertSame(PermissionMode::DontAsk, $result['quiet']->permissionMode);
    }

    public function testDiscoverClaudeSkipsMalformedFilesWithoutAbortingTheDirectory(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/malformed';
        $dir = $projectRoot . '/.claude/agents';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/no-frontmatter.md', "Just a body, no YAML block.\n");
        $this->writeAgent($dir, 'good', 'description: Still imported');

        $result = $registry->discoverClaude($projectRoot);

        $this->assertSame(['good'], array_keys($result));
    }

    // -------------------------------------------------------------------------
    // discoverOpencode()
    // -------------------------------------------------------------------------

    public function testDiscoverOpencodeReturnsEmptyWhenNoDirExists(): void
    {
        $registry = new ForeignAgentPresetRegistry();

        $this->assertSame([], $registry->discoverOpencode($this->tempDir . '/no-opencode-here'));
    }

    public function testDiscoverOpencodeMapsPromptAndTagsSource(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-project';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'builder',
            "description: Builds things\nmodel: minimax/m2\nprompt: You build things.",
        );

        $preset = $registry->discoverOpencode($projectRoot)['builder'];

        $this->assertSame(SkillSource::Opencode, $preset->source);
        $this->assertSame('Builds things', $preset->description);
        $this->assertSame('minimax/m2', $preset->model);
        $this->assertSame('You build things.', $preset->initialPrompt);
    }

    public function testDiscoverOpencodeFindsUserConfigAgents(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $fakeHome = $this->tempDir . '/fake-home-oc';
        $_SERVER['HOME'] = $fakeHome;
        $this->writeAgent($fakeHome . '/.config/opencode/agents', 'oc-home', 'description: Home opencode agent');

        $result = $registry->discoverOpencode($this->tempDir . '/empty-oc-project');

        $this->assertArrayHasKey('oc-home', $result);
        $this->assertSame(SkillSource::Opencode, $result['oc-home']->source);
    }

    public function testDiscoverOpencodeSplitsToolMapIntoAllowAndDenyLists(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-tools';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'tooled',
            "description: Tool map\ntools:\n  read: true\n  bash: false\n  custom-thing: true",
        );

        $preset = $registry->discoverOpencode($projectRoot)['tooled'];

        // opencode's lowercase names are canonicalised to sugar-crush's own
        // tool names; a tool sugar-crush does not implement passes through.
        $this->assertSame(['Read', 'custom-thing'], $preset->tools);
        $this->assertSame(['Bash'], $preset->disallowedTools);
    }

    public function testTruthyNonBooleanToolValueDoesNotGrantAccess(): void
    {
        // Regression: `$enabled ? 'allow' : 'deny'` promoted ANY truthy value
        // to an ALLOW, so a quoted "yes", a number, or a nested map — anything
        // malformed or newer than this mapping — widened the agent's
        // permissions instead of withholding them.
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-truthy';
        $this->writeAgent($projectRoot . '/.opencode/agents', 'truthy', <<<'YAML'
            description: Non-boolean tool values
            tools:
              bash: "yes"
              read: 1
              webfetch:
                enabled: true
            YAML);

        $preset = $registry->discoverOpencode($projectRoot)['truthy'];

        $this->assertSame([], $preset->tools);
        $this->assertSame([], $preset->disallowedTools);
        $warnings = $registry->warnings();
        $this->assertCount(3, $warnings);
        $this->assertStringContainsString('tools.bash was string', $warnings[0]);
        $this->assertStringContainsString('tools.read was int', $warnings[1]);
        $this->assertStringContainsString('tools.webfetch was array', $warnings[2]);
    }

    public function testToolsListFormImportsToolNamesNotArrayIndices(): void
    {
        // Regression: the list form was read as a map, so `- read` / `- bash`
        // imported the integer keys 0 and 1 as tool names.
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-list-tools';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'listed',
            "description: List-form tools\ntools:\n  - read\n  - bash",
        );

        $preset = $registry->discoverOpencode($projectRoot)['listed'];

        $this->assertSame(['Read', 'Bash'], $preset->tools);
        $this->assertSame([], $preset->disallowedTools);
        $this->assertSame([], $registry->warnings());
    }

    public function testListedToolNameStillLosesToAPermissionDeny(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-list-deny';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'listed-denied',
            "description: List form vs permission\ntools:\n  - bash\npermission:\n  bash: deny",
        );

        $preset = $registry->discoverOpencode($projectRoot)['listed-denied'];

        $this->assertSame([], $preset->tools);
        $this->assertSame(['Bash'], $preset->disallowedTools);
    }

    public function testDiscoverOpencodeMapsScalarPermissionRules(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-perm';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'permed',
            "description: Scalar permissions\npermission:\n  edit: allow\n  webfetch: deny\n  bash: ask",
        );

        $preset = $registry->discoverOpencode($projectRoot)['permed'];

        $this->assertSame(['Edit'], $preset->tools);
        $this->assertSame(['WebFetch'], $preset->disallowedTools);
        // "ask" is the runtime default for an unlisted tool, so it belongs in
        // neither list.
        $this->assertNotContains('Bash', $preset->tools);
        $this->assertNotContains('Bash', $preset->disallowedTools);
        $this->assertSame([], $registry->warnings());
    }

    public function testUnrecognisedScalarPermissionRuleIsIgnoredAndWarned(): void
    {
        // An unreadable rule inside a fine-grained map is reported, so an
        // unreadable scalar rule has to be too — otherwise a typo silently
        // evaporates.
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-bad-scalar';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'typo',
            "description: Typo'd permission\npermission:\n  bash: block",
        );

        $preset = $registry->discoverOpencode($projectRoot)['typo'];

        $this->assertNotContains('Bash', $preset->tools);
        $this->assertNotContains('Bash', $preset->disallowedTools);
        $warnings = $registry->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('permission.bash', $warnings[0]);
        $this->assertStringContainsString('unrecognised value "block"', $warnings[0]);
    }

    public function testDiscoverOpencodeCollapsesFineGrainedBashRulesAndWarns(): void
    {
        // The spec's one explicitly lossy mapping: opencode's per-command bash
        // globs have no AgentPreset equivalent. Dropping them silently would
        // hand the imported agent the `git push` it was denied, so the rules
        // collapse to the strictest decision AND are reported.
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-fine';
        $this->writeAgent($projectRoot . '/.opencode/agents', 'fine', <<<'YAML'
            description: Fine-grained bash rules
            permission:
              bash:
                "git status": allow
                "git push": deny
            YAML);

        $preset = $registry->discoverOpencode($projectRoot)['fine'];

        $this->assertSame(['Bash'], $preset->disallowedTools);
        $this->assertSame([], $preset->tools);

        $warnings = $registry->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('fine', $warnings[0]);
        $this->assertStringContainsString('permission.bash', $warnings[0]);
        $this->assertStringContainsString('git push: deny', $warnings[0]);
        $this->assertStringContainsString('collapsed to "deny"', $warnings[0]);
    }

    public function testFineGrainedAllowOnlyRulesCollapseToAllow(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-fine-allow';
        $this->writeAgent($projectRoot . '/.opencode/agents', 'lenient', <<<'YAML'
            description: Only allow rules
            permission:
              bash:
                "git status": allow
                "ls *": allow
            YAML);

        $preset = $registry->discoverOpencode($projectRoot)['lenient'];

        $this->assertSame(['Bash'], $preset->tools);
        $this->assertSame([], $preset->disallowedTools);
        $this->assertCount(1, $registry->warnings());
    }

    public function testFineGrainedRulesWithNoRecognisedKeywordCollapseToAsk(): void
    {
        // Regression: seeding the collapse with "allow" let a typo'd keyword
        // ("block" instead of "deny") promote bash into the ALLOW list, i.e.
        // an import widened the permissions its author wrote to restrict.
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-typo';
        $this->writeAgent($projectRoot . '/.opencode/agents', 'typo', <<<'YAML'
            description: Typo'd rule keyword
            permission:
              bash:
                "git push": block
            YAML);

        $preset = $registry->discoverOpencode($projectRoot)['typo'];

        $this->assertSame([], $preset->tools);
        $this->assertSame([], $preset->disallowedTools);
        $this->assertStringContainsString('collapsed to "ask"', $registry->warnings()[0]);
        $this->assertStringContainsString('Unrecognised rule value(s) ignored: git push: block', $registry->warnings()[0]);
    }

    public function testEmptyFineGrainedRuleMapCollapsesToAsk(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-empty-map';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'empty',
            "description: Empty rule map\npermission:\n  bash: {}",
        );

        $preset = $registry->discoverOpencode($projectRoot)['empty'];

        $this->assertSame([], $preset->tools);
        $this->assertSame([], $preset->disallowedTools);
        $this->assertStringContainsString('collapsed to "ask"', $registry->warnings()[0]);
    }

    public function testWriteAndListFoldOntoTheToolThatPerformsTheCapability(): void
    {
        // opencode splits file mutation across edit/write/patch, but sugar-crush
        // writes files through Edit — passing `write` through untouched made
        // `write: false` an inert deny while `edit: true` still granted writes.
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-write';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'writer',
            "description: Split capability names\ntools:\n  edit: true\n  write: false\n  list: true",
        );

        $preset = $registry->discoverOpencode($projectRoot)['writer'];

        $this->assertSame(['Edit'], $preset->disallowedTools);
        $this->assertSame(['Glob'], $preset->tools);
    }

    public function testStrictestDecisionWinsWhenToolsAndPermissionDisagree(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-conflict';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'conflicted',
            "description: Conflicting blocks\ntools:\n  bash: true\npermission:\n  bash: deny",
        );

        $preset = $registry->discoverOpencode($projectRoot)['conflicted'];

        $this->assertSame(['Bash'], $preset->disallowedTools);
        $this->assertSame([], $preset->tools);
    }

    // -------------------------------------------------------------------------
    // discover()
    // -------------------------------------------------------------------------

    public function testDiscoverMergesBothToolsWithClaudeWinningCollisions(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/both';
        $this->writeAgent($projectRoot . '/.claude/agents', 'shared', 'description: From Claude');
        $this->writeAgent($projectRoot . '/.opencode/agents', 'shared', 'description: From opencode');
        $this->writeAgent($projectRoot . '/.opencode/agents', 'oc-only', 'description: opencode only');

        $result = $registry->discover($projectRoot);

        $this->assertCount(2, $result);
        $this->assertSame('From Claude', $result['shared']->description);
        $this->assertSame(SkillSource::Claude, $result['shared']->source);
        $this->assertSame(SkillSource::Opencode, $result['oc-only']->source);
    }

    public function testDiscoverKeepsNumericFilenameStemsAsKeysAndStillHonoursPrecedence(): void
    {
        // Regression: PHP casts a numeric-string array key to int, and the
        // array spread that used to merge the two legs renumbers int keys —
        // `12.md` in both trees came back as two entries under 0 and 1.
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/numeric-stems';
        $this->writeAgent($projectRoot . '/.claude/agents', '12', 'description: From Claude');
        $this->writeAgent($projectRoot . '/.opencode/agents', '12', 'description: From opencode');

        $result = $registry->discover($projectRoot);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey(12, $result);
        $this->assertSame('From Claude', $result[12]->description);
    }

    public function testDiscoverReturnsEmptyForAProjectWithNoForeignAgentDirs(): void
    {
        $registry = new ForeignAgentPresetRegistry();

        $this->assertSame([], $registry->discover($this->tempDir . '/bare-project'));
    }

    public function testDiscoverCollectsWarningsFromTheOpencodeLeg(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/both-warn';
        $this->writeAgent($projectRoot . '/.claude/agents', 'reviewer', 'description: Claude agent');
        $this->writeAgent($projectRoot . '/.opencode/agents', 'runner', <<<'YAML'
            description: Fine-grained
            permission:
              bash:
                "rm -rf *": deny
            YAML);

        $registry->discover($projectRoot);

        // discover() resets warnings once at its own entry point — the nested
        // per-tool scans must not clear what the other leg recorded.
        $this->assertCount(1, $registry->warnings());
        $this->assertStringContainsString('runner', $registry->warnings()[0]);
    }

    // -------------------------------------------------------------------------
    // warnings()
    // -------------------------------------------------------------------------

    public function testWarningsStartEmptyAndResetBetweenRuns(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $this->assertSame([], $registry->warnings());

        $projectRoot = $this->tempDir . '/reset';
        $this->writeAgent($projectRoot . '/.opencode/agents', 'noisy', <<<'YAML'
            description: Fine-grained
            permission:
              bash:
                "git push": deny
            YAML);

        $registry->discoverOpencode($projectRoot);
        $this->assertCount(1, $registry->warnings());

        $registry->discoverOpencode($this->tempDir . '/quiet-project');
        $this->assertSame([], $registry->warnings());
    }

    // -------------------------------------------------------------------------
    // AgentPreset::$source default (added for foreign-provenance badging)
    // -------------------------------------------------------------------------

    public function testAgentPresetSourceDefaultsToNative(): void
    {
        $preset = new AgentPreset(name: 'local', description: 'A sugar-crush-native preset');

        $this->assertSame(SkillSource::Native, $preset->source);
    }
}
