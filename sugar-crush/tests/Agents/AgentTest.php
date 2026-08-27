<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentDefinition;
use SugarCraft\Crush\Agents\AgentPreset;
use SugarCraft\Crush\Context\EnvironmentBlock;

/**
 * Tests for Agent value object - represents a configured agent instance.
 */
final class AgentTest extends TestCase
{
    // -------------------------------------------------------------------------
    // fromArray() - deserialization
    // -------------------------------------------------------------------------

    public function testFromArray(): void
    {
        // Arrange
        $data = [
            'name' => 'test-agent',
            'description' => 'A test agent',
            'prompt' => 'You are a test agent.',
            'model' => 'claude-sonnet-4-6',
            'provider' => 'anthropic',
            'tools' => ['Read', 'Edit', 'Bash'],
            'skills' => ['php-best-practices'],
            'hooks' => ['pre_task'],
            'is_active' => true,
        ];

        // Act
        $agent = Agent::fromArray($data);

        // Assert
        $this->assertSame('test-agent', $agent->name);
        $this->assertSame('A test agent', $agent->description);
        $this->assertSame('You are a test agent.', $agent->prompt);
        $this->assertSame('claude-sonnet-4-6', $agent->model);
        $this->assertSame('anthropic', $agent->provider);
        $this->assertSame(['Read', 'Edit', 'Bash'], $agent->tools);
        $this->assertSame(['php-best-practices'], $agent->skillNames);
        $this->assertSame(['pre_task'], $agent->hooks);
        $this->assertTrue($agent->isActive);
    }

    public function testFromArrayWithDefaults(): void
    {
        // Act
        $agent = Agent::fromArray([]);

        // Assert - defaults
        $this->assertSame('', $agent->name);
        $this->assertSame('', $agent->description);
        $this->assertSame('', $agent->prompt);
        $this->assertSame('claude-sonnet-4-6', $agent->model);
        $this->assertSame('anthropic', $agent->provider);
        $this->assertSame([], $agent->tools);
        $this->assertSame([], $agent->skillNames);
        $this->assertSame([], $agent->hooks);
        $this->assertFalse($agent->isActive);
    }

    // -------------------------------------------------------------------------
    // toArray() - serialization
    // -------------------------------------------------------------------------

    public function testToArray(): void
    {
        // Arrange
        $agent = new Agent(
            name: 'my-agent',
            description: 'My agent description',
            prompt: 'You are my agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Read', 'Edit'],
            skillNames: ['php-best-practices', 'security-audit'],
            hooks: ['pre_task', 'post_task'],
            isActive: true,
        );

        // Act
        $array = $agent->toArray();

        // Assert
        $this->assertIsArray($array);
        $this->assertSame('my-agent', $array['name']);
        $this->assertSame('My agent description', $array['description']);
        $this->assertSame('You are my agent.', $array['prompt']);
        $this->assertSame('claude-sonnet-4-6', $array['model']);
        $this->assertSame('anthropic', $array['provider']);
        $this->assertSame(['Read', 'Edit'], $array['tools']);
        $this->assertSame(['php-best-practices', 'security-audit'], $array['skills']);
        $this->assertSame(['pre_task', 'post_task'], $array['hooks']);
        $this->assertTrue($array['is_active']);
    }

    // -------------------------------------------------------------------------
    // withName() - immutable builder
    // -------------------------------------------------------------------------

    public function testWithName(): void
    {
        // Arrange
        $original = new Agent(
            name: 'original-name',
            description: 'Original description',
            prompt: 'Original prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Read'],
            skillNames: ['skill-a'],
            hooks: ['hook-a'],
            isActive: false,
        );

        // Act
        $renamed = $original->withName('new-name');

        // Assert
        $this->assertSame('new-name', $renamed->name);
        $this->assertNotSame($original, $renamed); // new instance
    }

    public function testWithNamePreservesOtherFields(): void
    {
        // Arrange
        $original = new Agent(
            name: 'original-name',
            description: 'Original description',
            prompt: 'Original prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Read', 'Edit', 'Bash'],
            skillNames: ['php-best-practices', 'security-audit'],
            hooks: ['pre_task'],
            isActive: true,
        );

        // Act
        $renamed = $original->withName('renamed-agent');

        // Assert - name changed
        $this->assertSame('renamed-agent', $renamed->name);
        // Assert - other fields preserved
        $this->assertSame('Original description', $renamed->description);
        $this->assertSame('Original prompt', $renamed->prompt);
        $this->assertSame('claude-sonnet-4-6', $renamed->model);
        $this->assertSame('anthropic', $renamed->provider);
        $this->assertSame(['Read', 'Edit', 'Bash'], $renamed->tools);
        $this->assertSame(['php-best-practices', 'security-audit'], $renamed->skillNames);
        $this->assertSame(['pre_task'], $renamed->hooks);
        $this->assertTrue($renamed->isActive);
        // Assert - original unchanged
        $this->assertSame('original-name', $original->name);
    }

    // -------------------------------------------------------------------------
    // withActive() - immutable builder
    // -------------------------------------------------------------------------

    public function testWithActive(): void
    {
        // Arrange
        $original = new Agent(
            name: 'my-agent',
            description: 'My agent',
            prompt: 'You are my agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
        );

        // Act
        $activated = $original->withActive(true);
        $deactivated = $activated->withActive(false);

        // Assert
        $this->assertTrue($activated->isActive);
        $this->assertFalse($deactivated->isActive);
        $this->assertNotSame($original, $activated); // new instance
        $this->assertNotSame($activated, $deactivated); // new instance
    }

    public function testWithActivePreservesOtherFields(): void
    {
        // Arrange
        $original = new Agent(
            name: 'my-agent',
            description: 'My agent description',
            prompt: 'You are my agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Read', 'Edit', 'Bash'],
            skillNames: ['php-best-practices'],
            hooks: ['pre_task', 'post_task'],
            isActive: false,
        );

        // Act
        $activated = $original->withActive(true);

        // Assert - isActive changed
        $this->assertTrue($activated->isActive);
        // Assert - other fields preserved
        $this->assertSame('my-agent', $activated->name);
        $this->assertSame('My agent description', $activated->description);
        $this->assertSame('You are my agent.', $activated->prompt);
        $this->assertSame('claude-sonnet-4-6', $activated->model);
        $this->assertSame('anthropic', $activated->provider);
        $this->assertSame(['Read', 'Edit', 'Bash'], $activated->tools);
        $this->assertSame(['php-best-practices'], $activated->skillNames);
        $this->assertSame(['pre_task', 'post_task'], $activated->hooks);
        // Assert - original unchanged
        $this->assertFalse($original->isActive);
    }

    // -------------------------------------------------------------------------
    // systemPrompt() - prompt plus the session environment block
    // -------------------------------------------------------------------------

    public function testSystemPrompt(): void
    {
        // Arrange
        $agent = new Agent(
            name: 'test-agent',
            description: 'Test agent',
            prompt: 'You are a specialized test agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );

        // Act
        $systemPrompt = $agent->systemPrompt();

        // Assert
        $this->assertStringStartsWith("You are a specialized test agent.\n\n", $systemPrompt);
    }

    public function testSystemPromptEmpty(): void
    {
        // Arrange
        $agent = Agent::fromArray(['prompt' => '']);

        // Act
        $systemPrompt = $agent->systemPrompt();

        // Assert - no leading blank line is glued onto a promptless agent
        $this->assertStringStartsWith('<env>', $systemPrompt);
    }

    /**
     * The gap this closes: subagent prompts were a bare passthrough of
     * $this->prompt, so a subagent had no idea which directory, branch,
     * platform or model it was running under. Fails against the old
     * systemPrompt().
     */
    public function testSystemPromptAppendsCapturedEnvironmentBlock(): void
    {
        $agent = Agent::fromArray(['prompt' => 'Do the thing.', 'model' => 'minimax-m2.7']);

        $systemPrompt = $agent->systemPrompt();

        $this->assertStringContainsString('<env>', $systemPrompt);
        $this->assertStringContainsString('</env>', $systemPrompt);
        $this->assertStringContainsString('Working directory: ' . getcwd(), $systemPrompt);
        $this->assertStringContainsString('Model: minimax-m2.7', $systemPrompt);
        $this->assertStringContainsString('Current date: ' . date('Y-m-d'), $systemPrompt);
    }

    public function testSystemPromptPrefersTheCallerSuppliedEnvironmentBlock(): void
    {
        $agent = Agent::fromArray(['prompt' => 'Do the thing.', 'model' => 'agent-model']);

        $systemPrompt = $agent->systemPrompt(
            new EnvironmentBlock('/session/cwd', 'session-model', new DateTimeImmutable('2026-03-04 05:06:07')),
        );

        $this->assertStringContainsString('Working directory: /session/cwd', $systemPrompt);
        $this->assertStringContainsString('Model: session-model', $systemPrompt);
        $this->assertStringContainsString('Current date: 2026-03-04', $systemPrompt);
    }

    public function testSystemPromptUsesTheAttachedEnvironmentBlock(): void
    {
        $agent = Agent::fromArray(['prompt' => 'Do the thing.'])
            ->withEnvironment(new EnvironmentBlock('/attached/cwd', 'attached-model'));

        $systemPrompt = $agent->systemPrompt();

        $this->assertStringContainsString('Working directory: /attached/cwd', $systemPrompt);
        $this->assertStringContainsString('Model: attached-model', $systemPrompt);
    }
    // -------------------------------------------------------------------------
    // Golden prompt pin - committed byte golden + host-path leak scan
    // -------------------------------------------------------------------------

    /**
     * Byte-golden pin for Agent::systemPrompt().
     *
     * ORDER IS DELIBERATELY OPPOSITE TO Runtime::buildSystemPrompt(). The
     * Runtime assembler seats the <env> block EARLY - layer 2 of 7, right
     * after the identity literal, ahead of every variable layer (repo map,
     * instructions, memory, skills). Agent::systemPrompt() seats <env> at the
     * TAIL: agent prose first, environment after. The two must never share
     * one builder: AgentTest::testSystemPrompt() (this file, line 251)
     * asserts the agent text precedes <env>, while
     * BaseSystemPromptTest::testBasePromptStillIdentifiesItselfAsSugarCrush()
     * (line 135) asserts the identity literal precedes <env> with everything
     * after it treated as the data half. Under a unified assembler those two
     * assertions are mutually contradictory. Two assemblers, deliberately
     * separate.
     *
     * The golden is generated from the SAME fixture context this test builds
     * ({@see goldenContext()}): a deterministic fixture repo materialised at
     * test time under vendor/prompt-fixture/agent-repo, a pinned clock, a
     * fixed model, and a RELATIVE cwd - so the committed golden contains no
     * host path. The three host-property lines render() reads from the
     * runtime (Platform, OS version, PHP version) are normalized before the
     * comparison ({@see pinHostLines()}), which keeps the golden byte-stable
     * across machines while still failing on any one-byte change to prose,
     * ordering, separators or the git section.
     *
     * REGENERATION DISCIPLINE: regenerate the golden ONLY when the rendered
     * output legitimately changes (prose change, new env field, git-section
     * wording, P2.S1's platform injection landing). Regenerate with a
     * recorded reason in the commit message and paste the old->new diff into
     * the worklog. NEVER regenerate to silence a failing test.
     */
    public function testSystemPromptMatchesCommittedGolden(): void
    {
        $repo = self::ensureFixtureRepo();
        self::assertDirectoryExists(
            $repo,
            'Fixture repo was not materialised - run phpunit from sugar-crush/ so the relative '
            . 'cwd vendor/prompt-fixture/agent-repo resolves against the phpunit working directory.',
        );

        [$agent, $block] = self::goldenContext();

        self::assertSame(
            self::pinHostLines(self::readGolden()),
            self::pinHostLines($agent->systemPrompt($block)),
            'Agent::systemPrompt() drifted from the committed golden - see the regeneration discipline note.',
        );
    }

    /**
     * Host-path leak scan over the committed golden.
     *
     * THE ROO BUG CLASS: a production agent shipped a hardcoded '/test/path'
     * in its prompt prose because a fixture path leaked into a golden and no
     * test ever looked at the file again. An agent prompt must not carry its
     * generator's host paths. The fixture cwd is deliberately RELATIVE
     * (vendor/prompt-fixture/agent-repo), so the golden contains no absolute
     * path at all; this test pins that absence deliberately - no line may
     * start with '/', and the literal host-path fragments below must not
     * appear anywhere in the file.
     */
    public function testGoldenAgentPromptLeaksNoHostPaths(): void
    {
        $golden = self::readGolden();

        self::assertStringNotContainsString('/tmp/', $golden, 'golden leaks a /tmp/ host path');
        self::assertStringNotContainsString('/home/', $golden, 'golden leaks a /home/ host path');
        self::assertStringNotContainsString('/Users/', $golden, 'golden leaks a macOS /Users/ host path');
        self::assertStringNotContainsString('C:\\Users\\', $golden, 'golden leaks a Windows host path');
        self::assertStringNotContainsString('/my/', $golden, 'golden leaks the author username as a path segment');
        self::assertStringNotContainsString('Joe Huss', $golden, 'golden leaks the author identity');
        self::assertDoesNotMatchRegularExpression(
            '/^\//m',
            $golden,
            'a golden line starts with an absolute path - the fixture cwd must stay relative',
        );
    }

    /**
     * The exact fixture context the golden is generated from.
     *
     * Shared by the golden test and the regeneration procedure (a /tmp script
     * that reflects this method through AgentTest), so the committed golden
     * can never drift from what the test renders. The cwd is deliberately
     * RELATIVE so the golden contains no host path; it resolves against the
     * phpunit working directory (sugar-crush/).
     *
     * @return array{0: Agent, 1: EnvironmentBlock}
     */
    private static function goldenContext(): array
    {
        $agent = new Agent(
            name: 'golden-fixture',
            description: 'Fixture agent for the golden prompt',
            prompt: 'You are a focused coding subagent. Work only in the given workspace, prefer the jailed tools, and report findings before acting destructively.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
        );

        $block = new EnvironmentBlock(
            'vendor/prompt-fixture/agent-repo',
            'claude-sonnet-4-6',
            new DateTimeImmutable('2026-08-26 00:00:00'),
        );

        return [$agent, $block];
    }

    /**
     * Reads the committed golden, failing loudly when it is missing rather
     * than comparing against an empty string.
     */
    private static function readGolden(): string
    {
        $goldenPath = __DIR__ . '/../fixtures/prompt/golden-agent-prompt.txt';
        $golden = file_get_contents($goldenPath);
        if ($golden === false) {
            self::fail('Golden file missing: ' . $goldenPath . ' - regenerate per the discipline note above.');
        }

        return $golden;
    }

    /**
     * Materialises the deterministic fixture repo the golden renders.
     *
     * Built under vendor/prompt-fixture/agent-repo - gitignored via the root
     * vendor/ ignore rule, so the outer tree stays clean and the fixture never
     * shows up in `git status`. Rebuilt from scratch whenever the .git
     * directory is missing, with every step pinned: host git config is
     * neutralized (GIT_CONFIG_GLOBAL/SYSTEM), the commit author/committer
     * dates are fixed (deterministic commit hash), and every file is chmod
     * 0644 AFTER writing (umask-proof - a mode change would leak
     * `old mode`/`new mode` lines into the pinned diffs).
     *
     * The final state exercises every git field the block renders: branch
     * (main), a three-line --porcelain status (one staged add, one unstaged
     * edit, one untracked file), one recent commit, a staged diff and an
     * unstaged diff.
     */
    private static function ensureFixtureRepo(): string
    {
        $repo = __DIR__ . '/../../vendor/prompt-fixture/agent-repo';

        if (is_dir($repo . '/.git')) {
            return $repo;
        }

        if (is_dir($repo)) {
            self::removeTree($repo);
        }

        mkdir($repo . '/src', 0777, true);
        mkdir($repo . '/docs', 0777, true);

        self::gitRun($repo, ['init', '-q', '-b', 'main']);
        self::gitRun($repo, ['config', 'user.name', 'Fixture Author']);
        self::gitRun($repo, ['config', 'user.email', 'fixture@example.invalid']);
        self::gitRun($repo, ['config', 'core.abbrev', '7']);
        self::gitRun($repo, ['config', 'commit.gpgsign', 'false']);

        self::writeFixtureFile($repo . '/README.md', "# Fixture repo\n\nDeterministic test fixture for the Agent::systemPrompt() golden.\n");
        self::writeFixtureFile($repo . '/src/app.php', "<?php\n\ndeclare(strict_types=1);\n\necho \"hello\\n\";\n");

        self::gitRun($repo, ['add', 'README.md', 'src/app.php']);
        self::gitRun($repo, ['commit', '-m', 'fixture: initial import'], [
            'GIT_AUTHOR_NAME' => 'Fixture Author',
            'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid',
            'GIT_AUTHOR_DATE' => '2026-08-26T00:00:00+0000',
            'GIT_COMMITTER_NAME' => 'Fixture Author',
            'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid',
            'GIT_COMMITTER_DATE' => '2026-08-26T00:00:00+0000',
        ]);

        // Unstaged edit: src/app.php gains a line after the commit.
        self::writeFixtureFile($repo . '/src/app.php', "<?php\n\ndeclare(strict_types=1);\n\necho \"hello\\n\";\necho \"world\\n\";\n");
        // Staged add: docs/notes.md is added but not committed.
        self::writeFixtureFile($repo . '/docs/notes.md', "# Notes\n");
        self::gitRun($repo, ['add', 'docs/notes.md']);
        // Untracked: scratch.txt is never added.
        self::writeFixtureFile($repo . '/scratch.txt', "scratch\n");

        return $repo;
    }

    /**
     * Runs one git command inside the fixture repo, host config neutralized.
     */
    private static function gitRun(string $repo, array $args, array $env = []): void
    {
        $command = 'git -C ' . escapeshellarg($repo);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            array_merge(getenv() ?: [], ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null'], $env),
        );
        self::assertIsResource($process, 'proc_open failed for: ' . $command);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, 'git ' . implode(' ', $args) . " failed (exit {$exit}): {$stderr}");
    }

    /**
     * Writes a fixture file, pinning the mode AFTER the write so umask cannot
     * leak a mode change into the golden's diffs.
     */
    private static function writeFixtureFile(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0644);
    }

    /**
     * Recursively removes a directory tree (fixture rebuild).
     */
    private static function removeTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * Normalizes the three host-property lines render() reads from the
     * runtime, so the committed golden is byte-stable across machines.
     *
     * EnvironmentBlock's own docblock calls PHP_OS_FAMILY, php_uname() and
     * PHP_VERSION "read AT RENDER TIME" - constants of the HOST, not
     * behaviour of the prompt builder - and EnvironmentBlockTest interpolates
     * them dynamically (lines 114/162) for the same reason. A golden that
     * pinned the generator's kernel would red on every other machine. When
     * P2.S1 lands injectable platform/clock for the Runtime builder, this
     * normalization can be dropped and the golden re-pinned byte-for-byte.
     */
    private static function pinHostLines(string $block): string
    {
        $block = preg_replace('/^Platform: .*$/m', 'Platform: <host>', $block);
        $block = preg_replace('/^OS version: .*$/m', 'OS version: <host>', $block);
        $block = preg_replace('/^PHP version: .*$/m', 'PHP version: <host>', $block);

        return $block;
    }
    // -------------------------------------------------------------------------
    // withEnvironment() - immutable builder
    // -------------------------------------------------------------------------

    public function testWithEnvironmentReturnsNewInstanceAndPreservesOtherFields(): void
    {
        $original = new Agent(
            name: 'my-agent',
            description: 'My agent description',
            prompt: 'You are my agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Read'],
            skillNames: ['php-best-practices'],
            hooks: ['pre_task'],
            isActive: true,
        );

        $block = new EnvironmentBlock('/some/cwd', 'some-model');
        $attached = $original->withEnvironment($block);

        $this->assertNotSame($original, $attached);
        $this->assertNull($original->environment);
        $this->assertSame($block, $attached->environment);
        $this->assertSame('my-agent', $attached->name);
        $this->assertSame('My agent description', $attached->description);
        $this->assertSame('You are my agent.', $attached->prompt);
        $this->assertSame('claude-sonnet-4-6', $attached->model);
        $this->assertSame('anthropic', $attached->provider);
        $this->assertSame(['Read'], $attached->tools);
        $this->assertSame(['php-best-practices'], $attached->skillNames);
        $this->assertSame(['pre_task'], $attached->hooks);
        $this->assertTrue($attached->isActive);
    }

    public function testWithNameAndWithActiveCarryTheEnvironmentBlockForward(): void
    {
        $block = new EnvironmentBlock('/some/cwd', 'some-model');
        $agent = Agent::fromArray(['name' => 'a'])->withEnvironment($block);

        $this->assertSame($block, $agent->withName('b')->environment);
        $this->assertSame($block, $agent->withActive(true)->environment);
    }

    public function testToArrayOmitsTheEnvironmentSnapshot(): void
    {
        // A snapshot written into a persisted agent definition would outlive
        // the session that captured it.
        $agent = Agent::fromArray(['name' => 'a'])
            ->withEnvironment(new EnvironmentBlock('/some/cwd', 'some-model'));

        $this->assertArrayNotHasKey('environment', $agent->toArray());
    }

    // -------------------------------------------------------------------------
    // fromDefinition() / fromPreset() - the bridges into AgentManager::register()
    // -------------------------------------------------------------------------

    public function testFromDefinitionCarriesTheTemplateAndTheCallersProviderAndModel(): void
    {
        $agent = Agent::fromDefinition(AgentDefinition::reviewer(), 'openai', 'gpt-4o');

        $this->assertSame('reviewer', $agent->name);
        $this->assertSame('Code review specialist', $agent->description);
        $this->assertStringContainsString('code review specialist', $agent->prompt);
        $this->assertSame(['Read', 'Grep', 'Bash(git *)'], $agent->tools);
        $this->assertSame(['php-best-practices', 'security-audit'], $agent->skillNames);
        // The definition carries no provider/model of its own - it is a library
        // template, not a session's configuration.
        $this->assertSame('openai', $agent->provider);
        $this->assertSame('gpt-4o', $agent->model);
    }

    public function testFromDefinitionRegistersIdleByDefault(): void
    {
        // On this class active means "currently working" - the renderers turn
        // it into the literal word - so a template nobody has delegated to is
        // not active.
        $this->assertFalse(Agent::fromDefinition(AgentDefinition::coder(), 'echo', 'echo')->isActive);
        $this->assertTrue(Agent::fromDefinition(AgentDefinition::coder(), 'echo', 'echo', isActive: true)->isActive);
    }

    public function testFromPresetResolvesInheritOntoTheSessionModel(): void
    {
        $agent = Agent::fromPreset(
            new AgentPreset(name: 'docs', description: 'Writes docs'),
            'openai',
            'gpt-4o',
        );

        // 'inherit' is AgentPreset's default and its documented "use whatever
        // model the session is on" - passing it through verbatim would hand a
        // provider a model name it would reject.
        $this->assertSame('gpt-4o', $agent->model);
    }

    public function testFromPresetKeepsAnExplicitModel(): void
    {
        $agent = Agent::fromPreset(
            new AgentPreset(name: 'docs', description: 'Writes docs', model: 'claude-opus-4-1'),
            'openai',
            'gpt-4o',
        );

        $this->assertSame('claude-opus-4-1', $agent->model);
    }

    public function testFromPresetMapsToolsSkillsAndTheInitialPrompt(): void
    {
        $agent = Agent::fromPreset(
            new AgentPreset(
                name: 'docs',
                description: 'Writes docs',
                tools: ['Read', 'Edit'],
                skills: ['markdown'],
                initialPrompt: 'You write documentation.',
            ),
            'openai',
            'gpt-4o',
        );

        $this->assertSame('docs', $agent->name);
        $this->assertSame('Writes docs', $agent->description);
        $this->assertSame('You write documentation.', $agent->prompt);
        $this->assertSame(['Read', 'Edit'], $agent->tools);
        $this->assertSame(['markdown'], $agent->skillNames);
        $this->assertFalse($agent->isActive);
    }

    public function testFromPresetWithNoInitialPromptYieldsAnEmptyPrompt(): void
    {
        // Agent::systemPrompt() treats '' as "environment block only", which is
        // the right degradation for a preset that declares no prose.
        $agent = Agent::fromPreset(new AgentPreset(name: 'bare', description: ''), 'echo', 'echo');

        $this->assertSame('', $agent->prompt);
    }
}
