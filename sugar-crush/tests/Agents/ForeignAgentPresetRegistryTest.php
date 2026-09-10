<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentPreset;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Agents\Effort;
use SugarCraft\Crush\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

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
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-foreign-agent-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        $this->origHome = $_SERVER['HOME'] ?? '/root';
        // Every discover* call also scans the real HOME's foreign-agent dirs;
        // point HOME at an empty sandbox so the machine running the suite
        // cannot leak its own .claude/agents into an assertion.
        $_SERVER['HOME'] = $this->tempDir . '/default-empty-home';
        putenv('HOME=' . $_SERVER['HOME']);
        mkdir($_SERVER['HOME'], 0777, true);
        // Keep the lossy-mapping error_log() calls out of the suite's stderr.
        $this->origErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->tempDir . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->origErrorLog);
        $_SERVER['HOME'] = $this->origHome;
        putenv('HOME=' . $_SERVER['HOME']);
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
        putenv('HOME=' . $_SERVER['HOME']);
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
        putenv('HOME=' . $_SERVER['HOME']);
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

    /**
     * E575, REACHED. The "Invalid YAML frontmatter in:" branch of
     * frontmatter() was suspected unreachable — the theory being the earlier
     * guards swallow every malformed input. Measured, it is not: the guards
     * only catch unreadable files and missing delimiters, and a genuinely
     * broken YAML body throws a ParseException from Frontmatter::parse()
     * BEFORE this check — neither of those classes reaches it. What DOES
     * reach it is frontmatter that parses CLEANLY to a non-array, which
     * Frontmatter::parse() documents as its contract — "an array for a
     * mapping, NULL for an empty or comment-only block, a scalar for a bare
     * value". All three shapes are valid YAML, so nothing before this line
     * fires. THE FINDING IS THEREFORE "untested", not "unreachable" — and
     * the standing rule is fix-or-wire, never delete, so the input arrives
     * here rather than in a comment. Each malformed file is then PAIRED with
     * its own error line (skipping <path> ... invalid in: <path>) rather
     * than merely sharing a log with it — otherwise any three files could
     * satisfy the assertion by naming each other.
     */
    public function testFrontmatterThatParsesToANonArrayIsReportedInvalidByNameAndSkipped(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/non-array-frontmatter';
        $dir = $projectRoot . '/.claude/agents';
        mkdir($dir, 0777, true);

        // One per non-array shape the parse contract publishes.
        file_put_contents($dir . '/scalar.md', "---\n42\n---\nBody.");
        file_put_contents($dir . '/comment-only.md', "---\n# just a note\n---\nBody.");
        file_put_contents($dir . '/empty-block.md', "---\n\n---\nBody.");
        $this->writeAgent($dir, 'good', 'description: Still imported');

        $result = $registry->discoverClaude($projectRoot);

        $this->assertSame(
            ['good'],
            array_keys($result),
            'non-array frontmatter is skipped per-file without aborting the directory',
        );

        $log = (string) file_get_contents($this->tempDir . '/error.log');
        $invalidLines = array_values(array_filter(
            explode("\n", $log),
            static fn (string $line): bool => str_contains($line, 'Invalid YAML frontmatter in: '),
        ));
        $this->assertCount(3, $invalidLines, 'exactly one invalid-frontmatter line per malformed fixture');

        // Bind each fixture to ITS OWN line: the scan guard logs
        // "skipping {path}: {message}", and the thrown message repeats the
        // same path — so a correct line names the fixture on BOTH sides.
        // Bare contains-checks would pass with any cross-referencing.
        foreach (['scalar.md', 'comment-only.md', 'empty-block.md'] as $name) {
            $pattern = '/skipping \S*' . preg_quote($name, '/') . ': Invalid YAML frontmatter in: \S*' . preg_quote($name, '/') . '(?!\S)/';
            $matching = array_filter(
                $invalidLines,
                static fn (string $line): bool => preg_match($pattern, $line) === 1,
            );
            $this->assertCount(1, $matching, "the report names {$name} in the line that skips {$name}");
        }

        $this->assertStringNotContainsString('good.md', $log, 'the well-formed preset is never reported invalid');
    }

    // -------------------------------------------------------------------------
    // The Claude prefix dialect (E645).
    //
    // Claude Code scopes an argument-bearing rule as `Bash(git:*)` (prefix
    // after a colon); PermissionRule reads it as `Bash(git *)` (glob after a
    // space). The foreign form PARSES CLEANLY and matches NOTHING — measured
    // at this base: fnmatch('git:*', 'git status') is false — so a verbatim
    // import grants the tool by name and refuses every call by argument. The
    // importer is the only place that knows the source dialect, so it is the
    // only place the translation can honestly live.
    // -------------------------------------------------------------------------

    public function testDiscoverClaudeTranslatesTheColonPrefixDialectToTheSpaceForm(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/dialect';
        $this->writeAgent(
            $projectRoot . '/.claude/agents',
            'git-safety',
            "description: Runs git\ntools: Bash(git:*), Read\ndisallowedTools: Bash(rm:*)",
        );
        $this->writeAgent(
            $projectRoot . '/.claude/agents',
            'untouched',
            "description: No prefix rules here\ntools: Bash(git *), WebFetch(domain:github.com), Read",
        );

        $presets = $registry->discoverClaude($projectRoot);

        $this->assertSame(['Bash(git *)', 'Read'], $presets['git-safety']->tools);
        $this->assertSame(['Bash(rm *)'], $presets['git-safety']->disallowedTools);

        // NON-DESTRUCTIVE PASS-THROUGH: the native form keeps its bytes, and a
        // colon that is NOT a `:*` prefix tail — Claude's exact-value rule
        // shape — is left alone rather than guessed at.
        $this->assertSame(
            ['Bash(git *)', 'WebFetch(domain:github.com)', 'Read'],
            $presets['untouched']->tools,
        );
    }

    /**
     * THE IMPORT ACTUALLY MATCHES A GIT CALL, end to end. The translation is
     * worthless if the imported declaration still cannot survive its own
     * grant, so this runs the FULL chain — foreign file -> preset -> Agent ->
     * AgentManager grant -> per-call enforcement — in both directions: the
     * call the rule was written for goes THROUGH (the pre-fix shape died
     * here, refused by an argument half that matched nothing), and a call it
     * never covered is still refused (proving the translation did not widen
     * into a bare `Bash`).
     */
    public function testATranslatedForeignGrantAdmitsTheCallItWasWrittenFor(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/dialect-e2e';
        $this->writeAgent(
            $projectRoot . '/.claude/agents',
            'git-runner',
            "description: Commits things\ntools: Bash(git:*)",
        );
        $preset = $registry->discoverClaude($projectRoot)['git-runner'];

        [$manager, $subAgent] = $this->managerRunning(
            $preset,
            new ToolCall(name: 'Bash', arguments: ['command' => 'git status']),
        );
        iterator_to_array($manager->executeSubAgent($subAgent->id));

        $this->assertSame(
            SubAgent::STATUS_COMPLETE,
            $subAgent->status,
            'the imported rule must ADMIT the git call it was written for — the pre-fix verbatim import died here',
        );

        [$manager2, $subAgent2] = $this->managerRunning(
            $preset,
            new ToolCall(name: 'Bash', arguments: ['command' => 'rm -rf /']),
        );
        $caught = null;

        try {
            iterator_to_array($manager2->executeSubAgent($subAgent2->id));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'translation must not widen the rule past git');
        $this->assertStringContainsString('is outside the tool grant', $caught->getMessage());
        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent2->status);
    }

    /**
     * The one matcher the enforcement path uses, applied to the imported
     * string itself — a cheap pin that survives even if AgentManager's
     * plumbing moves, and the direct refutation of the measured `false`
     * the verbatim import produced.
     */
    public function testTheImportedDeclarationMatchesTheGitCallInTheSameMatcherTheGrantUses(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/dialect-matcher';
        $this->writeAgent(
            $projectRoot . '/.claude/agents',
            'git-only',
            "description: Runs git\ntools: Bash(git:*)",
        );
        $imported = $registry->discoverClaude($projectRoot)['git-only']->tools[0];

        $this->assertTrue(
            (new PermissionRule($imported, PermissionAction::Allow))
                ->matches(new ToolCall(name: 'Bash', arguments: ['command' => 'git status'])),
            'the imported rule must match the call it was written for',
        );
        $this->assertFalse(
            (new PermissionRule($imported, PermissionAction::Allow))
                ->matches(new ToolCall(name: 'Bash', arguments: ['command' => 'rm -rf /'])),
            'and only that',
        );
    }

    /**
     * Run one preset through a real AgentManager: an in-process executor is
     * not needed because executeSubAgent() drives the provider directly; the
     * provider answers the scripted tool call exactly once and the gate is
     * the permissive BypassPermissions so any refusal observed here can only
     * have come from the GRANT — the same control AgentManagerTest uses.
     *
     * @return array{AgentManager, SubAgent}
     */
    private function managerRunning(AgentPreset $preset, ToolCall $call): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('supportsStreaming')->willReturn(false);
        $provider->method('complete')->willReturn(new CompleteResponse(
            content: 'Result',
            toolCalls: [$call],
        ));

        $manager = new AgentManager(
            provider: $provider,
            skillRegistry: new SkillRegistry(),
            permissionGateFactory: static fn(): PermissionGate => new PermissionGate(PermissionMode::BypassPermissions),
            toolRegistry: [$this->fakeBashTool()],
        );
        $manager->register(Agent::fromPreset($preset, 'anthropic', 'claude-sonnet-4-6', true));

        return [$manager, $manager->createSubAgent($preset->name, 'do it')];
    }

    /** The smallest Tool that satisfies the registry contract for name `Bash`. */
    private function fakeBashTool(): Tool
    {
        return new class implements Tool {
            public function __construct(private readonly string $name = 'Bash') {}

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'fake Bash';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult('id', 'ok');
            }
        };
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
        putenv('HOME=' . $_SERVER['HOME']);
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
    // Markdown body as the imported prompt
    // -------------------------------------------------------------------------

    /**
     * Claude Code writes a subagent's system prompt as the markdown BODY, and
     * `initialPrompt:` frontmatter is the rare form — so importing only the
     * frontmatter produced a preset with a null prompt for the common case.
     *
     * Fails if the fix is reverted: `initialPrompt` is null and the assertion
     * on the body text fails.
     */
    public function testDiscoverClaudeTakesThePromptFromTheMarkdownBody(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/claude-body';
        $this->writeAgent(
            $projectRoot . '/.claude/agents',
            'reviewer',
            "name: reviewer\ndescription: Reviews code",
            "You are a meticulous reviewer.\n\nCite line numbers.\n",
        );

        $preset = $registry->discoverClaude($projectRoot)['reviewer'];

        $this->assertSame("You are a meticulous reviewer.\n\nCite line numbers.", $preset->initialPrompt);
    }

    /** A declared `initialPrompt:` still wins over the body. */
    public function testDiscoverClaudePrefersADeclaredInitialPromptOverTheBody(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/claude-both';
        $this->writeAgent(
            $projectRoot . '/.claude/agents',
            'reviewer',
            "description: Reviews code\ninitialPrompt: The declared prompt.",
            "Notes for humans.\n",
        );

        $this->assertSame(
            'The declared prompt.',
            $registry->discoverClaude($projectRoot)['reviewer']->initialPrompt,
        );
    }

    /** Same convention, same fix, on the opencode side. */
    public function testDiscoverOpencodeTakesThePromptFromTheMarkdownBody(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-body';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'builder',
            'description: Builds things',
            "You build things carefully.\n",
        );

        $preset = $registry->discoverOpencode($projectRoot)['builder'];

        $this->assertSame('You build things carefully.', $preset->initialPrompt);
    }

    /** A declared `prompt:` still wins over the body. */
    public function testDiscoverOpencodePrefersADeclaredPromptOverTheBody(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/oc-both';
        $this->writeAgent(
            $projectRoot . '/.opencode/agents',
            'builder',
            "description: Builds things\nprompt: The declared prompt.",
            "Notes for humans.\n",
        );

        $this->assertSame(
            'The declared prompt.',
            $registry->discoverOpencode($projectRoot)['builder']->initialPrompt,
        );
    }

    /** Frontmatter-only files still report "no prompt" as null rather than ''. */
    public function testAnImportedAgentWithNoBodyAndNoPromptKeyHasANullInitialPrompt(): void
    {
        $registry = new ForeignAgentPresetRegistry();
        $projectRoot = $this->tempDir . '/claude-bare';
        $this->writeAgent($projectRoot . '/.claude/agents', 'bare', 'description: Nothing but frontmatter', '');

        $this->assertNull($registry->discoverClaude($projectRoot)['bare']->initialPrompt);
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
