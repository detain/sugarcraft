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
     * fixed model, an injected platform ('linux') and a RELATIVE cwd - so the
     * committed golden contains no host path. Only the two host-property
     * lines that are NOT injectable, OS version and PHP version, are
     * normalized, and only on the RENDERED side ({@see pinHostLines()}): the
     * committed golden carries the `<host>` placeholder itself, so those
     * lines are pinned rather than masked away on both sides.
     *
     * THE RENDER RUNS AT THE PACKAGE ROOT, pinned by {@see inPackageRoot()}
     * and located from __DIR__, because the fixture paths are relative and a
     * relative path resolves against the PROCESS cwd - which PHPUnit does not
     * set. Read that method for the CI failure this cost.
     *
     * REGENERATION DISCIPLINE: regenerate the golden ONLY when the rendered
     * output legitimately changes (prose change, new env field, git-section
     * wording). Regenerate with a recorded reason in the commit message and
     * paste the old->new diff into the worklog. A raw dump of the render is
     * NOT a valid golden - it has to go through {@see pinHostLines()} first,
     * or it puts the generator's kernel back into the committed file and reds
     * both this test and the leak scan. NEVER regenerate to silence a failing
     * test.
     */
    public function testSystemPromptMatchesCommittedGolden(): void
    {
        $repo = self::ensureFixtureRepo();
        // WHAT THIS MESSAGE USED TO SAY: "run phpunit from sugar-crush/ so
        // the relative cwd vendor/prompt-fixture/agent-repo resolves against
        // the phpunit working directory". It blamed a failure mode that
        // CANNOT happen -- ensureFixtureRepo() anchors on __DIR__, so the
        // fixture is materialised under sugar-crush/vendor/ from any cwd, and
        // this assertion has never once been the one that failed. It sent a
        // reader hunting for a materialisation bug when the real defect was
        // the process cwd downstream of here, now fixed by inPackageRoot().
        self::assertDirectoryExists(
            $repo,
            'Fixture repo was not materialised at ' . $repo . ' - git init or the fixture writes '
            . 'failed; the path is __DIR__-anchored, so this is not a working-directory problem.',
        );

        $rendered = self::inPackageRoot(static function (): string {
            [$agent, $block] = self::goldenContext();

            return $agent->systemPrompt($block);
        });

        self::assertSame(
            self::readGolden(),
            self::pinHostLines($rendered),
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
     * path at all, and this test pins that absence.
     *
     * WHAT THIS TEST USED TO BE, and why the shape changed. It was seven
     * absence assertions - six literal roots plus `/^\//m`. MEASURED, both
     * defects, before the rewrite:
     *
     *   * Truncating this golden to ZERO BYTES left the test `OK`. Every
     *     assertion in it was an ABSENCE assertion, and '' contains nothing,
     *     so a golden that had been emptied read exactly like a clean one.
     *   * Splicing in `Working directory: /var/www/build-agent-42/checkout`
     *     ALSO left it `OK`: `/^\//m` is anchored at column 0 and the six
     *     literals are `/tmp/ /home/ /Users/ C:\Users\ /my/ /test/`, so the
     *     path was neither at the start of a line nor under one of the six
     *     roots somebody had happened to think of. `/opt/`, `/srv/`,
     *     `/root/`, `/builds/`, `/workspace/` were all free to leak.
     *
     * The three answers, in order below. (1) LANDMARKS make the scan's input
     * falsifiable, so an empty or head/tail-truncated golden reds here
     * instead of reading clean. (2) The six literals STAY - they name the
     * specific historic leaks and cost nothing - but the column-0 regex is
     * replaced by {@see hostPathLeaks()}, which recognises an absolute path
     * by its SHAPE at any column and so enumerates no roots at all; the one
     * thing it allows is git's own `/dev/null`. (3) A KNOWN-POSITIVE CONTROL
     * runs the same scanner over the same golden with a host path spliced
     * in, because a scanner that answers "clean" for every input is
     * indistinguishable from a clean golden.
     *
     * The generator-host VALUE lines are pinned here too - see the assertions
     * at the end and {@see pinHostLines()} for why they are placeholders in
     * the committed file rather than this machine's kernel.
     */
    public function testGoldenAgentPromptLeaksNoHostPaths(): void
    {
        $golden = self::readGolden();
        [$agent] = self::goldenContext();

        self::assertNotSame('', $golden, 'the golden is empty - every absence assertion below is vacuous on it');
        self::assertStringStartsWith(
            $agent->prompt,
            $golden,
            'the golden no longer opens with the fixture agent prompt - it has been truncated at the head, '
            . 'and a truncated golden satisfies every absence assertion below without being scanned',
        );
        self::assertStringEndsWith(
            "\n</env>",
            $golden,
            'the golden no longer closes with </env> - it has been truncated at the tail',
        );
        // A HEAD AND A TAIL LANDMARK LEAVE A MID-BODY DELETION INVISIBLE, and
        // the middle of this file is where the volatile half lives. MEASURED
        // on the draft that had only the three landmarks above: cutting the
        // entire git section out of this golden - 356 bytes, 1060 down to
        // 704 - left both leak tests `OK (2 tests, 35 assertions)`, because
        // every assertion below is an ABSENCE assertion and the survivors
        // still satisfied all of them. A byte count is the only landmark that
        // catches a truncation ANYWHERE, and it is stable across machines
        // only because the two host-derived lines are placeholders now
        // ({@see pinHostLines()}) rather than this host's kernel and PHP
        // build. It has to move when the golden is legitimately regenerated,
        // which is the cheap half of a discipline that already requires
        // diffing old against new.
        self::assertSame(
            1060,
            strlen($golden),
            'the agent-prompt golden is not its committed length - it has been truncated or padded '
            . 'somewhere the absence assertions below would scan straight past',
        );

        self::assertStringNotContainsString('/tmp/', $golden, 'golden leaks a /tmp/ host path');
        self::assertStringNotContainsString('/home/', $golden, 'golden leaks a /home/ host path');
        self::assertStringNotContainsString('/Users/', $golden, 'golden leaks a macOS /Users/ host path');
        self::assertStringNotContainsString('C:\\Users\\', $golden, 'golden leaks a Windows host path');
        self::assertStringNotContainsString('/my/', $golden, 'golden leaks the author username as a path segment');
        self::assertStringNotContainsString('Joe Huss', $golden, 'golden leaks the author identity');

        self::assertSame(
            [],
            self::hostPathLeaks($golden),
            'the golden carries an absolute filesystem path - the fixture cwd must stay relative',
        );
        self::assertSame(
            ['/var/www/build-agent-42/checkout'],
            self::hostPathLeaks($golden . "\nWorking directory: /var/www/build-agent-42/checkout"),
            'the leak scanner reports nothing for a golden with a known host path spliced into it, '
            . 'so its verdict on the real golden means nothing either',
        );

        self::assertStringContainsString(
            "\nPlatform: linux\n",
            $golden,
            'the golden no longer pins the INJECTED platform - goldenContext() passes it, so it is '
            . 'real prompt output and must not go back to being masked',
        );
        self::assertStringContainsString(
            "\nOS version: <host>\n",
            $golden,
            'the golden carries a generator-host OS version instead of the placeholder it is compared as',
        );
        self::assertStringContainsString(
            "\nPHP version: <host>\n",
            $golden,
            'the golden carries a generator-host PHP version instead of the placeholder it is compared as',
        );
    }

    /**
     * The exact fixture context the golden is generated from.
     *
     * Shared by the golden test and the regeneration procedure (a /tmp script
     * that reflects this method through AgentTest), so the committed golden
     * can never drift from what the test renders. The cwd is deliberately
     * RELATIVE so the golden contains no host path; it resolves against the
     * package root, which {@see inPackageRoot()} pins for the render rather
     * than leaving it to whatever directory phpunit was launched from.
     *
     * The platform is INJECTED ('linux', the fourth constructor argument P2.S1
     * added) rather than read from the host, which is what closes the
     * `pinHostLines()` follow-up this file used to carry - see that method.
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
            'linux',
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
     * {@see pinHostLines()} normalizes a REAL host value and leaves an empty
     * or whitespace-only one alone.
     *
     * A mask is a hole in a golden, and a mask written `.*` is a hole with no
     * floor: it rewrites `OS version: ` - a line the block emitted nothing at
     * all for - into the very placeholder the committed golden carries, so
     * the two compare equal and the render's failure is invisible. That is
     * the same defect this step closed for `Platform: `, which was masked by
     * VALUE and so stayed green against the wrong platform AND against no
     * platform. MEASURED across the three candidate masks on PHP 8.3.6, over
     * `OS version: Linux 6.8.0-138-generic`, `OS version: `, `OS version:  `
     * and `OS version:   x`: `.*` masks all four; `.+` masks all but the
     * empty one; `(?=.*\S).*` masks only the two that carry a value, which is
     * the polarity this asserts in both directions.
     */
    public function testPinHostLinesNormalizesAValueAndLeavesAnEmptyOneAlone(): void
    {
        self::assertSame(
            "OS version: <host>\nPHP version: <host>\n",
            self::pinHostLines("OS version: Linux 6.8.0-138-generic\nPHP version: 8.3.6\n"),
            'pinHostLines() no longer normalizes a real host value, so the golden would red on every '
            . 'machine but this one',
        );
        self::assertSame(
            "OS version: \nPHP version: \n",
            self::pinHostLines("OS version: \nPHP version: \n"),
            'pinHostLines() masks an EMPTY host value into the placeholder, so a render that emitted '
            . 'nothing at all for these two lines would compare equal to the committed golden',
        );
        self::assertSame(
            "OS version:   \nPHP version:  \n",
            self::pinHostLines("OS version:   \nPHP version:  \n"),
            'pinHostLines() masks a WHITESPACE-ONLY host value into the placeholder',
        );
    }

    /**
     * Normalizes the two host-property lines render() reads from the runtime,
     * so the committed golden is byte-stable across machines.
     *
     * APPLIED TO THE RENDERED SIDE ONLY. It used to be applied to BOTH sides,
     * and that made the very lines it names UNPINNED: masking the golden's
     * copy too meant the committed bytes were compared against nothing.
     * MEASURED before the change - writing `OS version: Windows 95` into the
     * committed golden left this suite green. The golden now carries the
     * PLACEHOLDER, `<host>`, which is exactly what the comparison
     * constrains, so any other value there reds the golden test.
     * Consequence for the regeneration procedure: a raw dump of the render
     * is NOT a valid golden; it has to go through this method first.
     *
     * PLATFORM IS NO LONGER MASKED AT ALL, which closes the follow-up this
     * docblock used to carry ("when P2.S1 lands injectable platform/clock
     * ... this normalization can be dropped"). P2.S1 landed; the drop is
     * done here rather than left pending. What the mask cost while it stood:
     * `^Platform: .*$` masks by VALUE, so the golden stayed green on a Darwin
     * or Windows host with the WRONG platform in the prompt, and an EMPTY
     * value ('Platform: ') survived it too. {@see goldenContext()} now
     * injects 'linux' through EnvironmentBlock's fourth constructor
     * argument, so the golden's `Platform: linux` is real, pinned output.
     *
     * `(?=.*\S).*` AND NOT `.*`, deliberately. A mask written `.*` matches an
     * EMPTY value, so a render emitting a bare `OS version: ` would be rewritten
     * to the placeholder and compare equal to the golden - the identical
     * hole this step closed for `Platform: `, one line down. MEASURED on the
     * `.*` form: `preg_replace('/^OS version: .*$/m', ...)` over
     * "OS version:  \nPHP version: \n" yields exactly the golden's two
     * lines. With `.+` the empty value no longer matches, the placeholder is
     * not substituted, and the golden test reds.
     *
     * OS version and PHP version cannot get the same treatment here:
     * EnvironmentBlock's own docblock calls php_uname() and PHP_VERSION
     * "read AT RENDER TIME" - constants of the HOST, not behaviour of the
     * prompt builder - and neither is injectable. Making them injectable
     * needs src/Context/EnvironmentBlock.php, which is outside this step's
     * declared file list; it is reported as a follow-up rather than done
     * here.
     */
    private static function pinHostLines(string $block): string
    {
        $block = preg_replace('/^OS version: (?=.*\S).*$/m', 'OS version: <host>', $block);
        $block = preg_replace('/^PHP version: (?=.*\S).*$/m', 'PHP version: <host>', $block);

        return $block;
    }

    /**
     * {@see inPackageRoot()} restores the working directory on BOTH exit
     * paths, and this is the assertion that matters more than the fix it
     * guards.
     *
     * chdir() is process-global and PHPUnit runs one test after another in
     * one process, so a render that threw while the cwd was moved would hand
     * a changed working directory to every test that runs after it - roughly
     * half this suite, failing in places that have nothing to do with the
     * prompt. That is a strictly worse defect than the CI red the pin was
     * written to fix, so the `finally` is checked here rather than trusted:
     * the second half of this test drives the throw path deliberately, and
     * also pins that the exception is RE-RAISED rather than swallowed.
     *
     * WHY THIS TEST MOVES THE CWD FIRST, and it is not tidiness. The check is
     * only meaningful from a directory that is NOT the package root: run from
     * the package root, `$before` and the pinned root are the same string and
     * a missing restore is invisible. MEASURED - the first draft of this test
     * had no chdir() and stayed `OK (2 tests, 8 assertions)` with the
     * `finally` deleted and the restore moved onto the success path only. It
     * was decorative, in exactly the way §1.11 describes, until this line.
     * Its own restore is in a `finally` for the same reason the subject's is.
     */
    public function testInPackageRootRestoresTheWorkingDirectoryOnEveryExitPath(): void
    {
        $outer = (string) getcwd();
        self::assertTrue(chdir(__DIR__), 'could not move to a directory distinct from the package root');

        try {
            $before = getcwd();

            $inside = self::inPackageRoot(static fn (): string => (string) getcwd());
            self::assertNotSame($before, $inside, 'inPackageRoot() did not move the working directory at all');
            self::assertFileExists($inside . '/composer.json', 'inPackageRoot() did not run at a package root');
            // WHICH root, not merely SOME root. The monorepo above this
            // package has a composer.json of its own, so an assertion that
            // stops at the file's existence is satisfied by the very
            // directory whose selection IS the CI bug this pin exists to
            // fix. MEASURED on the draft that stopped there: replacing the
            // walk with `$root = \dirname(__DIR__, 3);` pinned the monorepo
            // root and left this test `OK (1 test, 6 assertions)` - only the
            // golden test noticed. The two assertions below name the thing
            // that actually has to be true, which is that the fixture paths
            // the goldens are rendered from RESOLVE against $inside.
            self::assertSame(
                'sugarcraft/sugar-crush',
                json_decode((string) file_get_contents($inside . '/composer.json'), true)['name'] ?? null,
                'inPackageRoot() pinned the wrong composer.json - the monorepo root has one too',
            );
            self::assertDirectoryExists(
                $inside . '/tests/fixtures/prompt/memory',
                'inPackageRoot() pinned a root the golden fixtures\' relative paths do not resolve against',
            );
            self::assertSame($before, getcwd(), 'inPackageRoot() leaked a changed working directory on the SUCCESS path');

            try {
                self::inPackageRoot(static function (): string {
                    throw new \LogicException('the render threw');
                });
                self::fail('inPackageRoot() swallowed the exception the render threw');
            } catch (\LogicException $thrown) {
                self::assertSame('the render threw', $thrown->getMessage());
            }

            self::assertSame(
                $before,
                getcwd(),
                'inPackageRoot() leaked a changed working directory on the FAILURE path - every test '
                . 'that runs after this one would have inherited it',
            );
        } finally {
            chdir($outer);
        }
    }

    /**
     * Runs $render with the process working directory pinned to the package
     * root - the directory holding composer.json, vendor/ and tests/ - and
     * restores the previous working directory on every exit path.
     *
     * WHY THIS EXISTS, and it is not hypothetical: master's CI was RED on
     * exactly this. The golden fixture's cwd, repo root and memory-store path
     * are deliberately RELATIVE so the committed golden carries no host path
     * ({@see goldenContext()}), and a relative path resolves against the
     * PROCESS working directory. PHPUnit never sets that directory: `-c
     * <lib>/phpunit.xml` relocates test DISCOVERY and leaves getcwd() alone,
     * and tests/bootstrap.php contains no chdir(). CI runs
     * `php sugar-crush/vendor/bin/phpunit -c sugar-crush/phpunit.xml` with no
     * `cd` and no `working-directory:`, so its cwd is the REPO ROOT, where
     * `vendor/prompt-fixture/...` and `tests/fixtures/...` name nothing.
     * MEASURED from the repo root: EnvironmentBlock::isGitRepo() is
     * `file_exists($cwd . '/.git')`, so the block rendered `Is directory a git
     * repo: No` and dropped the whole git section against a golden that says
     * `Yes`; and MemoryStore's constructor threw
     * `Memory path must be a writable directory`. One failure and one error,
     * on both PHP 8.3 and 8.4, and nothing else in the suite.
     *
     * THE INVARIANT: the golden render executes at the package root whatever
     * directory the process was launched from. The root is located by walking
     * up from __DIR__ and never by consulting getcwd(), so this is not "works
     * from the two directories somebody tried" - no launch directory can
     * defeat it, including one above the repo or one inside vendor/.
     *
     * WHY NOT MAKE THE PATHS ABSOLUTE INSTEAD: because the relative path is
     * the load-bearing part. It is what keeps the committed golden free of
     * this machine's paths, and it is pinned by the leak scan in this file.
     *
     * WHY THE RESTORE IS IN A `finally`: chdir() is process-global and
     * PHPUnit runs one test after another in one process. A failed assertion
     * or a thrown fixture error inside $render that leaked a changed cwd into
     * every subsequent test would be a far worse defect than the one this
     * fixes, so the restore cannot be on the success path only.
     *
     * @param callable():string $render
     */
    private static function inPackageRoot(callable $render): string
    {
        $root = __DIR__;
        while (!is_file($root . '/composer.json')) {
            $parent = \dirname($root);
            if ($parent === $root) {
                self::fail('no package root (a directory holding composer.json) above ' . __DIR__);
            }
            $root = $parent;
        }

        $previous = getcwd();
        if ($previous === false) {
            self::fail('getcwd() failed - the golden render cannot be pinned to ' . $root);
        }
        if (!chdir($root)) {
            self::fail('chdir() to the package root failed: ' . $root);
        }

        try {
            return $render();
        } finally {
            chdir($previous);
        }
    }

    /**
     * Every absolute filesystem path $text carries, de-duplicated, in
     * first-appearance order.
     *
     * THE DENY SIDE ENUMERATES NOTHING. An absolute path is recognised by its
     * SHAPE, which is the whole point: the check this replaced was six
     * literal roots (`/tmp/ /home/ /Users/ C:\Users\ /my/ /test/`) plus a
     * `/^\//m` anchored at column 0, and `/opt/`, `/srv/`, `/root/`,
     * `/builds/`, `/workspace/` and any path not at the start of a line all
     * walked straight through it. A list of roots is only ever as good as the
     * last CI runner somebody thought about.
     *
     * WHAT IT MATCHES. A POSIX path is a run of ONE OR TWO leading slashes
     * that opens a run of path segments and is not preceded by a path
     * character, so git's own diff prefixes (`a/docs/notes.md`,
     * `+++ b/src/app.php`), ordinary relative paths (`src/Lib.php`,
     * `vendor/prompt-fixture/agent-repo`), closing tags (`</env>`,
     * `</repo-map>`) and prose (`and/or`) do not match, while
     * ` /var/www/build-agent-42/checkout` does at any column. A Windows path
     * is a drive letter not preceded by a letter followed by a separator; a
     * UNC path is a doubled backslash followed by a host.
     *
     * WHY `/{1,2}` AND NOT `/`, and it is not a flourish. The first draft
     * excluded `/` in the lookbehind, so a `/` could never open a match when
     * the character before it was also a `/` - and a DOUBLED slash is what
     * `$base . '/' . $rel` produces whenever `$base` already ends in one,
     * which MSYS and git-bash emit routinely. MEASURED on that draft:
     * `//opt/ci/work` and `//srv/agent/checkout` both reported `[]`, and
     * writing `//opt/ci/work-agent-42` into the committed agent golden at
     * column 0 left its leak test `OK (1 test, 14 assertions)`. The
     * `assertDoesNotMatchRegularExpression('/^\//m', ...)` this function
     * replaced DID catch that case, so the draft was not the superset its
     * own doc-block claimed. It is now: `/` is out of the lookbehind and the
     * leading run may be one slash or two.
     *
     * WHY THE TWO COLON LOOKBEHINDS. Taking `/` out of the lookbehind let a
     * URL through - `https://example.com/x` matched `//example.com/x` - so
     * `(?<!:)` blocks the scheme's first slash and `(?<!:/)` blocks its
     * second. They also stop a Windows `D:/build/out` being counted twice,
     * once by each arm. The blind spot they buy is a path written with no
     * space after a colon (`foo:/opt/x`), which is not a spelling anything
     * in this prompt produces; a spurious red on every URL in the base
     * prompt would be the worse trade.
     *
     * THE ONE ALLOWED PATH is `/dev/null`. git renders the absent side of an
     * added file as `--- /dev/null`; it is git's own literal, byte-identical
     * on every machine, and names nothing about the host. Allowing expected
     * CONTENT is a different thing from enumerating forbidden ROOTS - this
     * list cannot grow silently, because anything not on it fails.
     *
     * MEASURED on this tree over 27 known-answer inputs: it reports nothing
     * for either committed golden, and reports EXACTLY ONE path for each of
     * `/var/www/build-agent-42/checkout`, `//opt/ci/work`, `//srv/agent/checkout`,
     * `///opt/ci/work`, `/opt/ci/work`, `/srv/x`, `/builds/gitlab/proj`,
     * `/workspace`, `/Volumes/ci/x`, a mid-line `/root/agent`,
     * `C:\Users\bob\proj`, `D:/build/out` and `\\fileserver\share\proj`. It
     * reports nothing for `a/docs/notes.md`, `+++ b/src/app.php`, `src/`,
     * `vendor/prompt-fixture/agent-repo`, `</env>`, `</repo-map>`,
     * `</project-instructions>`, `and/or`, `2026/08/26`, `--- /dev/null`,
     * `http://a.b/c` and `https://example.com/docs/x`.
     *
     * @return list<string>
     */
    private static function hostPathLeaks(string $text): array
    {
        $allowed = ['/dev/null'];

        preg_match_all('#(?<![\w.~\\\\<-])(?<!:)(?<!:/)/{1,2}[\w.~-]+(?:/[\w.~-]+)*/?#', $text, $posix);
        preg_match_all('#(?<![A-Za-z])[A-Za-z]:[\\\\/][^\r\n]*#', $text, $windows);
        preg_match_all('#\\\\\\\\[\w.-]+\\\\[^\r\n]*#', $text, $unc);

        $hits = [];
        foreach ([...$posix[0], ...$windows[0], ...$unc[0]] as $hit) {
            if (!in_array($hit, $allowed, true) && !in_array($hit, $hits, true)) {
                $hits[] = $hit;
            }
        }

        return $hits;
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
