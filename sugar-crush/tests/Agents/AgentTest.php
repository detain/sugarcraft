<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentDefinition;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentPreset;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\ProcessExecutor;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * Tests for Agent value object - represents a configured agent instance.
 */
final class AgentTest extends TestCase
{
    /**
     * Structural landmarks that must survive anywhere in the MIDDLE of the
     * committed agent-prompt golden.
     *
     * WHY A LIST AND NOT JUST THE BYTE COUNT. The leak scan's landmarks were
     * a head (the fixture agent prompt), a tail (`</env>`) and
     * `strlen($golden) === 1060`. Every assertion the scan then runs is an
     * ABSENCE assertion, so any deletion that the landmarks miss reads
     * exactly like a clean golden. A byte count catches a deletion, but it
     * catches it only while the byte total moves - and a truncation that
     * REPLACES what it removes is invisible to it. MEASURED on the committed
     * golden: cutting the four git blocks (branch, status, recent commits,
     * staged changes - 356 bytes) and padding the surviving `Note:` line back
     * up to 1060 bytes left the whole leak test `OK` on the byte count,
     * because 1060 is 1060 whether or not the git section is still there.
     * These headings make that class of edit red on WHAT is missing rather
     * than on how much of it is.
     *
     * The sibling file has had this since its own rewrite
     * ({@see \SugarCraft\Crush\Tests\BaseSystemPromptTest} `REQUIRED_SECTIONS`);
     * this file was the copy that got the head, the tail and the count and
     * not the middle.
     *
     * @var list<string>
     */
    private const REQUIRED_GOLDEN_LANDMARKS = [
        'Is directory a git repo: Yes',
        'Current branch: main',
        'Status:',
        'Recent commits:',
        'Staged changes (git diff --cached, index vs HEAD):',
        'Unstaged changes (git diff, working tree vs index):',
    ];

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
     * absence assertions - FIVE literal roots, ONE identity literal
     * (`Joe Huss`), and a `/^\//m`. WHAT THIS SAID: "six literal roots",
     * and it then listed `/tmp/ /home/ /Users/ C:\Users\ /my/ /test/`. That
     * sentence was true of the SIBLING file and false of this one: the count
     * and the list were copied wholesale from
     * BaseSystemPromptTest::testGoldenSystemPromptLeaksNoHostPaths(), whose
     * pre-rewrite check really did carry six roots and three identities.
     * MEASURED on this file's own history - `git show 8fa2721d9` and
     * `git log -S"'/test/'" -- sugar-crush/tests/Agents/AgentTest.php`, which
     * returns no commit at all - this test has never at any point asserted
     * the absence of `/test/`. A doc-block that describes a neighbour's code
     * is worse than one that describes nothing, because it reads as a
     * measurement. MEASURED, both defects, before the rewrite:
     *
     *   * Truncating this golden to ZERO BYTES left the test `OK`. Every
     *     assertion in it was an ABSENCE assertion, and '' contains nothing,
     *     so a golden that had been emptied read exactly like a clean one.
     *   * Splicing in `Working directory: /var/www/build-agent-42/checkout`
     *     ALSO left it `OK`: `/^\//m` is anchored at column 0 and the five
     *     roots are `/tmp/ /home/ /Users/ C:\Users\ /my/`, so the path was
     *     neither at the start of a line nor under one of the five roots
     *     somebody had happened to think of. `/opt/`, `/srv/`, `/root/`,
     *     `/builds/`, `/workspace/` were all free to leak.
     *
     * The three answers, in order below. (1) LANDMARKS make the scan's input
     * falsifiable - head, tail, committed byte count, and every required
     * mid-body line in between ({@see REQUIRED_GOLDEN_LANDMARKS}) - so an
     * emptied, truncated, or silently-repadded golden reds here instead of
     * reading clean. (2) The five roots and the identity literal
     * STAY - they name the specific historic leaks and cost nothing - but
     * the column-0 regex is
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
        // branch, status, recent-commits and staged-changes blocks out of
        // this golden - 356 bytes, 1060 down to 704, leaving the `Note:`
        // preamble and the unstaged block in place, so NOT the whole git
        // section, which is 717 bytes here - left both leak tests
        // `OK (2 tests, 35 assertions)`, because
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
        // AND THE BYTE COUNT ALONE IS NOT ENOUGH EITHER: it moves only while
        // a deletion is not compensated for. A cut that pads itself back to
        // 1060 walks straight past it, and every assertion below is an
        // ABSENCE assertion that a shorter golden satisfies more easily, not
        // less. These landmarks name WHAT has to still be there - see
        // {@see REQUIRED_GOLDEN_LANDMARKS} for the measurement.
        foreach (self::REQUIRED_GOLDEN_LANDMARKS as $landmark) {
            self::assertStringContainsString(
                "\n" . $landmark . "\n",
                $golden,
                'the golden is missing the "' . $landmark . '" line - it has been truncated in the '
                . 'middle, and the byte count does not see a deletion that padded itself back',
            );
        }

        self::assertStringNotContainsString('/tmp/', $golden, 'golden leaks a /tmp/ host path');
        self::assertStringNotContainsString('/home/', $golden, 'golden leaks a /home/ host path');
        self::assertStringNotContainsString('/Users/', $golden, 'golden leaks a macOS /Users/ host path');
        self::assertStringNotContainsString('C:\\Users\\', $golden, 'golden leaks a Windows host path');
        self::assertStringNotContainsString('/my/', $golden, 'golden leaks the author username as a path segment');
        self::assertStringNotContainsString('Joe Huss', $golden, 'golden leaks the author identity');

        // WHAT TO CHECK WHEN THIS FIRES - the same caveat the sibling file
        // carries. hostPathLeaks() recognises a path by SHAPE and enumerates
        // no roots, which is what makes it worth having and also means it
        // cannot tell a build machine's cwd apart from an `/etc/hosts` or a
        // `[docs](/docs/x)` written deliberately into an agent's own prompt
        // text, which this golden embeds whole. Read the reported path
        // before believing the word "leak".
        self::assertSame(
            [],
            self::hostPathLeaks($golden),
            'the golden carries an absolute filesystem path - if it comes from the env or git '
            . 'section the fixture cwd must stay relative; if it is prose in the agent\'s own '
            . 'prompt text, nothing leaked - reword it or add the exact string to '
            . 'hostPathLeaks()\'s $allowed list',
        );
        // RESTORED, not replaced. hostPathLeaks() is not a superset of this
        // check: a path segment cannot be empty, so a slash followed by
        // whitespace (`/ home/ci`, `// comment`) is caught here and by
        // nothing there. Deleting this in favour of the scanner was a
        // narrowing on that class of input; both stand.
        self::assertDoesNotMatchRegularExpression(
            '/^\//m',
            $golden,
            'a golden line starts with an absolute path - the fixture cwd must stay relative',
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
     * {@see hostPathLeaks()} returns the EXACT list of absolute paths in a
     * line, and the empty list for everything a prompt legitimately carries.
     *
     * WHY THIS EXISTS, measured rather than argued. Before it, the scanner
     * was exercised by exactly two inputs: a clean golden, and one spliced
     * `/var/www/build-agent-42/checkout` control. MEASURED - replacing the
     * whole function body with
     *
     *     preg_match_all('#/var/www[\w./-]*#', $text, $posix);
     *     $windows = [[]];
     *     $unc = [[]];
     *
     * left both step files at `OK (39 tests, 246 assertions)`, byte for byte
     * the unmutated total. Every property the doc-block claims - the Windows
     * arm, the UNC arm, mid-line detection, doubled slashes, the git-prefix
     * and URL exclusions, the enumerate-no-roots design that item 2(c) asked
     * for - held in the implementation and in nothing the tree could check.
     * A hard-coded single root passed the leak tests exactly as well as the
     * shape-based scanner did. This table is what makes that mutation red.
     *
     * BOTH POLARITIES, and the exact string. A scanner that reports SOME hit
     * is not the same as one that reports the RIGHT hit: the narrow first
     * draft answered `/x` for `/'quoted'/x`, naming a path that is not in
     * the text while missing the one that is, and an `assertNotSame([], ...)`
     * would have called that a pass.
     */
    public function testHostPathLeaksReportsEveryAbsolutePathAndNothingElse(): void
    {
        $mustFire = [
            // Plain POSIX, at column 0 and mid-line.
            '/usr/local/bin' => ['/usr/local/bin'],
            '/home/my/thing' => ['/home/my/thing'],
            'Working directory: /var/www/build-agent-42/checkout' => ['/var/www/build-agent-42/checkout'],
            'Working directory: /opt/ci/work' => ['/opt/ci/work'],
            'Working directory: /srv/x' => ['/srv/x'],
            'Working directory: /builds/gitlab/proj' => ['/builds/gitlab/proj'],
            'Working directory: /workspace' => ['/workspace'],
            'Working directory: /Volumes/ci/x' => ['/Volumes/ci/x'],
            'trailing note here /root/agent then more' => ['/root/agent'],
            // A first segment that does not open with a word character. The
            // first draft's `[\w.~-]+` missed all four.
            '/@scope/pkg/build' => ['/@scope/pkg/build'],
            '/+build/out' => ['/+build/out'],
            '/$HOME/build' => ['/$HOME/build'],
            "/'quoted'/x" => ["/'quoted'/x"],
            // A doubled leading slash - what `$base . '/' . $rel` produces
            // when $base already ends in one.
            'Working directory: //opt/ci/work' => ['//opt/ci/work'],
            'cwd = //srv/agent/checkout' => ['//srv/agent/checkout'],
            '///opt/ci/work' => ['//opt/ci/work'],
            // A PATH ON A DELETED DIFF LINE, which is the likeliest real leak
            // shape in the thing this scanner exists to read: <env> embeds
            // `git diff` bodies verbatim, so a removed line carrying an
            // absolute path arrives here with a `-` welded to its leading
            // slash. It is also the one shape BOTH halves of the leak scan
            // used to miss at once - `/^\//m` is anchored at column 0 and the
            // `-` occupies column 0, while the `-` was itself inside this
            // scanner's lookbehind class. MEASURED on the class that still
            // carried it: `-/opt/ci/build` returned [] here, matched nothing
            // at column 0, and removing the `-` from `[\w.~\\<-]` left both
            // step files at `OK (41 tests, 354 assertions)` - nothing in the
            // tree noticed either the hole or its repair.
            '-/opt/ci/build' => ['/opt/ci/build'],
            '-Working directory: /opt/ci/build' => ['/opt/ci/build'],
            // The added-line polarity, which always fired because `+` was
            // never in the class. It is pinned so that widening the class
            // again - the exact edit that opened the `-` hole - reds here
            // instead of silently reopening it on the other polarity.
            '+/opt/ci/build' => ['/opt/ci/build'],
            // A TRAILING SLASH IS PART OF THE REPORTED PATH, which is the
            // whole of what the pattern's closing `/?` buys, and nothing
            // exercised it: MEASURED, deleting that `/?` left both step files
            // at `OK (41 tests, 354 assertions)`. Without it this line reports
            // `/opt/ci/work` - a real path in the text, but not the string
            // that is there - so this row reds on the near-miss rather than
            // accepting it.
            'Working directory: /opt/ci/work/' => ['/opt/ci/work/'],
            // Windows drive paths and a UNC share.
            'Working directory: C:\\Users\\bob\\proj' => ['C:\\Users\\bob\\proj'],
            'Working directory: D:/build/out' => ['D:/build/out'],
            'Working directory: \\\\fileserver\\share\\proj' => ['\\\\fileserver\\share\\proj'],
        ];

        $mustStaySilent = [
            // git's own diff prefixes and porcelain, and ordinary relative paths.
            '--- /dev/null',
            '+++ b/docs/notes.md',
            'diff --git a/src/app.php b/src/app.php',
            ' M src/Lib.php',
            'A  docs/notes.md',
            '?? scratch.txt',
            '@@ -0,0 +1 @@',
            'vendor/prompt-fixture/agent-repo',
            '- src/  ->  Fixture\\Lib\\  (2 files)',
            // Closing fence tags - the `<` lookbehind.
            '</env>',
            '</repo-map>',
            '</project-instructions>',
            // Prose and dates.
            'and/or, 2026/08/26, ok',
            'Recent commits:',
            // URLs - the two colon lookbehinds.
            'see https://example.com/docs/x for more',
            'http://a.b/c',
            // TILDE-HOME PATHS - the `~` in the lookbehind class, which
            // nothing exercised either: MEASURED, dropping the `~` left both
            // step files at `OK (41 tests, 354 assertions)`. `~/x` names a
            // home directory without naming a host, so it is not a leak; with
            // the `~` gone these two report `/projects/app` and
            // `/.config/crush/config.json`, and this pair reds.
            '~/projects/app',
            'edit ~/.config/crush/config.json then rerun',
        ];

        foreach ($mustFire as $line => $expected) {
            self::assertSame($expected, self::hostPathLeaks((string) $line), 'hostPathLeaks() on: ' . $line);
        }
        foreach ($mustStaySilent as $line) {
            self::assertSame([], self::hostPathLeaks($line), 'hostPathLeaks() falsely reports a leak in: ' . $line);
        }

        // THE SUPERSET RELATION, ASSERTED RATHER THAN CLAIMED. This scanner
        // did not replace `assertDoesNotMatchRegularExpression('/^\//m')`; it
        // stands beside it, because it is NOT a superset - a path segment
        // cannot be empty, so `/ home/ci` and `// comment` are caught by the
        // column-0 regex and by nothing here. Both halves are pinned: every
        // column-0 path the old check sees is either seen here too, or is
        // one of the two the leak scans still run that check for.
        $slashThenSpace = ['/ home/ci', '// comment'];
        foreach ([...array_keys($mustFire), ...$slashThenSpace] as $line) {
            $line = (string) $line;
            if (!str_starts_with($line, '/')) {
                continue;
            }
            self::assertSame(1, preg_match('/^\//m', $line), 'control: the column-0 check must see ' . $line);
            self::assertSame(
                !in_array($line, $slashThenSpace, true),
                self::hostPathLeaks($line) !== [],
                'the column-0 check and hostPathLeaks() disagree about ' . $line . ', and the leak '
                . 'scans rely on exactly one of them covering what the other misses',
            );
        }
    }

    /**
     * Every absolute filesystem path $text carries, de-duplicated, in
     * first-appearance order.
     *
     * THE DENY SIDE ENUMERATES NOTHING. An absolute path is recognised by its
     * SHAPE, which is the whole point: the check this replaced was five
     * literal roots (`/tmp/ /home/ /Users/ C:\Users\ /my/`) plus a
     * `/^\//m` anchored at column 0 - the sibling copy of this doc-block in
     * BaseSystemPromptTest says six and adds `/test/`, which is true THERE
     * and has never been true here - and `/opt/`, `/srv/`, `/root/`,
     * `/builds/`, `/workspace/` and any path not at the start of a line all
     * walked straight through it. A list of roots is only ever as good as the
     * last CI runner somebody thought about.
     *
     * WHAT IT MATCHES. A POSIX path is a run of ONE OR TWO leading slashes
     * that opens a run of path segments, each segment being any run of
     * characters that are not whitespace, a slash, a backslash, an angle
     * bracket, a quote or a pipe - NOT a run of word characters. The
     * narrower `[\w.~-]+` was measured to miss `/@scope/pkg/build`,
     * `/+build/out` and `/$HOME/build` outright, and to report `/x` for
     * `/'quoted'/x` - naming a path that is not in the text while missing
     * the one that is. A leading slash is not preceded by a path
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
     * `assertDoesNotMatchRegularExpression('/^\//m', ...)` DID catch that
     * case, so the draft was not the superset its own doc-block claimed.
     *
     * WHY THE LOOKBEHIND CLASS HAS NO HYPHEN, which is the hole that mattered
     * most of the several this pattern has had. It used to read
     * `[\w.~\\<-]`, and a `-` welded to a leading slash is exactly what
     * `git diff` writes on a REMOVED line: `-/opt/ci/build`. <env> embeds
     * diff bodies verbatim, so a deleted line carrying an absolute path is
     * the likeliest real leak shape in the very text this scanner was written
     * to read - and it was the one shape BOTH halves of the leak scan missed
     * at the same time, because the `-` occupies column 0 and
     * `/^\//m` is anchored there. MEASURED on the class that still carried
     * the `-`: `-/opt/ci/build` returned `[]`, and taking the `-` out left
     * both step files at `OK (41 tests, 354 assertions)`, byte for byte the
     * unmutated total - nothing in the tree could tell the hole from its
     * repair. Both diff polarities are rows in the known-answer table now.
     * WHAT THE `-` BOUGHT, and why losing it is cheap: it suppressed a match
     * on a token that ends in a hyphen and is immediately followed by a slash
     * (`a-/opt/x`). MEASURED: neither committed golden contains the two-byte
     * sequence `-/` anywhere (`grep -- '-/'` over both files exits 1), git's
     * diff headers and porcelain do not produce it, and the full suite is
     * green from both cwds without it. A hyphen inside an ordinary hyphenated
     * path (`build-agent-42/checkout`) was never affected either way - the
     * character before that slash is a word character, which is still in the
     * class.
     *
     * THIS FUNCTION IS STILL NOT A SUPERSET OF THAT CHECK, AND THE CHECK IS
     * THEREFORE BACK rather than replaced. MEASURED: `/ home/ci` and
     * `// comment` - a slash followed by whitespace - are caught by `/^\//m`
     * and by nothing here, because a path segment cannot be empty. Deleting
     * the column-0 regex in favour of this one was a NARROWING on that
     * class of input, which is exactly what §1.9 forbids; both checks now
     * stand side by side in the leak scans, and the superset relation is
     * asserted rather than claimed - see the second half of
     * {@see testHostPathLeaksReportsEveryAbsolutePathAndNothingElse()}.
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
     * THE KNOWN-ANSWER TABLE IS A TEST, NOT A SENTENCE IN THIS DOC-BLOCK.
     * It used to be prose here - "MEASURED over 27 known-answer inputs" -
     * describing a measurement that existed nowhere in the repository, and
     * the tree could not tell the difference: MEASURED, replacing this whole
     * function body with `preg_match_all('#/var/www[\w./-]*#', $text,
     * $posix); $windows = [[]]; $unc = [[]];` left both files at
     * `OK (39 tests, 246 assertions)`, because the only inputs anything ran
     * it over were a clean golden and one spliced `/var/www/...` control. A
     * scanner nothing exercises is a scanner nobody can grade. The rows now
     * live in {@see testHostPathLeaksReportsEveryAbsolutePathAndNothingElse()},
     * which asserts the exact returned list for every one of them and reds
     * on that mutation.
     *
     * @return list<string>
     */
    private static function hostPathLeaks(string $text): array
    {
        $allowed = ['/dev/null'];

        preg_match_all('#(?<![\w.~\\\\<])(?<!:)(?<!:/)/{1,2}[^\s/\\\\<>"|]+(?:/[^\s/\\\\<>"|]+)*/?#', $text, $posix);
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

    // =========================================================================
    // P3.S6 - the SECOND assembler's per-step seam, measured rather than assumed
    // =========================================================================

    /**
     * Every production call site of `Agent::systemPrompt()`, as a NAMED ROSTER
     * keyed on the file rather than as a bare total.
     *
     * WHY A ROSTER AND NOT A COUNT. A cardinality moves without saying what
     * moved; this says WHICH file gained or lost a call, which is the whole
     * difference between a census that reds usefully and one that reds. It is
     * keyed on the file and not on `file:line` on purpose: a line number is
     * wrong the next time anyone edits above it, and this roster is meant to
     * survive every edit that does not change the SET of callers.
     *
     * WHY IT EXISTS AT ALL. P3.S5 wired the per-step write signal into the
     * `Runtime` assembler and left the second one - this class's - untouched,
     * and the disposition of that gap turns entirely on whether any of these
     * callers renders more than once per dispatch. That question had no test
     * and the counts in prose disagreed with each other: prompt_plan.md's
     * P3.S6 section says EIGHT and `Runtime::markWriteSinceLastRender()`'s
     * doc-block says NINE. MEASURED at P3.S6 by the scanner below, over
     * `src/` entire: eight, in four files, and the doc-block's nine is the
     * stale one.
     *
     * @var array<string, int>
     */
    private const AGENT_ASSEMBLER_CALL_SITES = [
        'Agents/AgentManager.php' => 1,
        'Agents/ProcessExecutor.php' => 1,
        'App/App.php' => 1,
        'Workflows/WorkflowEngine.php' => 5,
    ];

    /**
     * The roster above, re-derived from `src/` on every run, with the scanner's
     * known-positive control in the SAME test.
     *
     * THE CONTROL IS NOT DECORATION. Every interesting thing this test says is
     * a statement about a count, and a scanner that has stopped matching
     * anything reports a count too - `[]` is what a dead instrument returns.
     * The fixture below carries THREE live calls and SIX near-misses that a
     * naive textual scan would take: the same call spelled inside a line
     * comment, inside a doc comment and inside a single-quoted string, the
     * method's own declaration, `buildSystemPrompt()` (the OTHER assembler,
     * whose name ends in the one this matches), and the bare word in array-key
     * position. The counts in this sentence used to say "one live call and six
     * near-misses" while the fixture held one and FIVE - the declaration the
     * scanner's own doc-block claimed to exclude was never in it, so that
     * exclusion had no coverage at all. It does now.
     *
     * THE THREE LIVE CALLS ARE THREE SPELLINGS, not three copies. `->`,
     * nullsafe `?->`, and a name separated from its `(` by a space are all
     * legal PHP for the same call, and the first version of this scanner saw
     * only the first: a review proved the hole by inserting
     * `if (false) { $subAgent->agent?->systemPrompt(); }` into
     * `src/Agents/AgentManager.php` and watching this test stay green, while
     * the `->` spelling of the same line reddened it. `?->` is idiomatic in
     * this tree - `/usr/bin/grep -ro -- '?->' src/ | wc -l` reports 98 - so
     * that was a live hole in the instrument the whole step rests on, not a
     * theoretical one. The scanner must answer with exactly the three live
     * lines.
     */
    public function testEveryProductionCallSiteOfTheAgentAssemblerIsDerivedAndAccountedFor(): void
    {
        $fixture = "<?php\n"
            . "// \$a->systemPrompt() spelled in a line comment\n"
            . "/** \$b->systemPrompt() spelled in a doc comment */\n"
            . "\$s = '\$c->systemPrompt()';\n"
            . "\$live = \$agent->systemPrompt();\n"
            . "\$other = \$agent->buildSystemPrompt();\n"
            . "\$key = ['systemPrompt' => 1];\n"
            . "function systemPrompt() {}\n"
            . "\$d?->systemPrompt();\n"
            . "\$e->systemPrompt ();\n";

        $this->assertSame(
            [5, 9, 10],
            self::agentAssemblerCallSites($fixture),
            'the call-site scanner no longer sees all three spellings of a live ->systemPrompt() call '
                . '(plain, nullsafe, spaced), or it sees one of the six near-misses beside them - every '
                . 'count below is worthless until this line passes',
        );

        $src = \dirname(__DIR__, 2) . '/src';
        $census = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $sites = self::agentAssemblerCallSites((string) file_get_contents($file->getPathname()));
            if ($sites !== []) {
                $census[str_replace('\\', '/', substr($file->getPathname(), \strlen($src) + 1))] = \count($sites);
            }
        }
        ksort($census);

        $this->assertSame(
            self::AGENT_ASSEMBLER_CALL_SITES,
            $census,
            'the set of files that call Agent::systemPrompt() moved. A NEW caller must be classified '
                . 'per-step or once-per-dispatch before this roster is updated - that classification is '
                . 'the entire content of P3.S6.',
        );

        $this->assertSame(
            8,
            array_sum($census),
            'prompt_plan.md P3.S6 states eight production call sites; Runtime::markWriteSinceLastRender()'
                . "'s doc-block states nine. This is the derivation that settles it.",
        );
    }

    /**
     * THE SEAM QUESTION, ANSWERED BY DRIVING IT: one sub-agent dispatch renders
     * the environment block exactly ONCE, however many chunks the provider
     * streams.
     *
     * This is the measurement P3.S6 turns on. The `Runtime` assembler re-renders
     * once per step of the agentic loop, which is what gave P3.S5's write signal
     * something to suppress; the agent assembler has no agentic loop at all -
     * `AgentManager::executeSubAgent()` builds one `CompleteRequest` and hands
     * it to one completion, and the transient-failure retry around that
     * completion re-sends the SAME request object rather than rebuilding it. So
     * there is no second render inside a dispatch for a write signal to
     * suppress, and no "step after a write" for it to be suppressed on.
     *
     * PINNED AS A DECISION, NOT AS AN ACCIDENT. If a later change gives the
     * agent path a real step loop, the provider is called more than once and
     * this reds - which is the moment the P3.S6 disposition has to be revisited
     * rather than a moment nobody notices.
     *
     * BOTH POLARITIES ARE IN HERE: the chunk count is varied from one to twenty
     * and the answer must not move. A test run at a single chunk count cannot
     * tell "renders once per dispatch" from "renders once per chunk".
     */
    public function testASubAgentDispatchRendersTheEnvironmentBlockOnceHoweverManyChunksTheProviderStreams(): void
    {
        $repo = self::ensureFixtureRepo();

        foreach ([1, 20] as $chunks) {
            $provider = new class ($chunks) implements ProviderInterface {
                /** @var list<?string> */
                public array $systemPrompts = [];
                public int $streamCalls = 0;

                public function __construct(private int $chunks) {}

                public function name(): string
                {
                    return 'p3s6-counting';
                }

                public function supportsStreaming(): bool
                {
                    return true;
                }

                public function supportsFunctionCalling(): bool
                {
                    return false;
                }

                public function supportsVision(): bool
                {
                    return false;
                }

                public function supportsJsonSchema(): bool
                {
                    return false;
                }

                public function contextWindow(): int
                {
                    return 100_000;
                }

                public function costPer1kTokens(string $model, string $direction): float
                {
                    return 0.0;
                }

                public function complete(CompleteRequest $request): CompleteResponse
                {
                    $this->systemPrompts[] = $request->systemPrompt;

                    return new CompleteResponse(content: 'done');
                }

                public function completeStream(CompleteRequest $request): \Generator
                {
                    $this->streamCalls++;
                    $this->systemPrompts[] = $request->systemPrompt;

                    for ($i = 0; $i < $this->chunks; $i++) {
                        yield new CompleteResponse(content: "chunk{$i} ");
                    }
                }

                public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
                {
                    throw new \LogicException('the P3.S6 counting provider is never asked for embeddings');
                }
            };

            $manager = new AgentManager($provider, new SkillRegistry());
            $manager->register(self::probeAgent(EnvironmentBlock::capture($repo, 'stub-model')));

            $subAgent = $manager->createSubAgent('p3s6-probe', 'do the thing');
            foreach ($manager->executeSubAgent($subAgent->id) as $_) {
                // drain the generator; the assertions are on what the provider saw
            }

            $this->assertSame(
                1,
                $provider->streamCalls,
                "the agent path grew a step loop: {$chunks} streamed chunks produced "
                    . "{$provider->streamCalls} completions, not one",
            );
            $this->assertCount(
                1,
                $provider->systemPrompts,
                "one dispatch handed the provider more than one system prompt at {$chunks} chunks",
            );

            $handed = $provider->systemPrompts[0];
            $this->assertIsString($handed);
            $this->assertStringStartsWith(
                'P3S6 PROBE PROMPT',
                $handed,
                'the prompt the provider received is not the one Agent::systemPrompt() assembled',
            );
            $this->assertSame(
                1,
                substr_count($handed, '<env>'),
                'the dispatch emitted the environment block more than once into a single prompt',
            );
            $this->assertSame(
                1,
                substr_count($handed, 'Staged changes (git diff --cached, index vs HEAD):'),
                'the default (unmarked) write signal must still emit the staged-diff section exactly once',
            );
        }
    }

    /**
     * THE PRICE OF THE SECOND ASSEMBLER, measured with a logging `git` shim on
     * `PATH` rather than quoted from a brief.
     *
     * Four figures, one instrument, one test, because three of them are
     * assertions of a SMALLER number and `0` is also what a shim nobody ever
     * invoked reports. The `5` in here is this test's known-positive control:
     * a dead shim reds it, and a shim that logs every process would blow the
     * `0`.
     *
     *   - `EnvironmentBlock::capture()` shells out to git ZERO times. It stores
     *     three values; the whole bill is in `render()`.
     *   - One `Agent::systemPrompt()` costs FIVE subprocesses on a repository:
     *     branch, status, log, `diff --cached`, `diff`.
     *   - With the P3.S5 write signal suppressed it costs THREE - the two diff
     *     sections and their two subprocesses are what the signal withholds.
     *   - And `render()` is NOT memoised, so three calls on ONE agent cost
     *     fifteen rather than five. That is the fact that makes the second
     *     assembler more expensive per call than the first, and it is asserted
     *     here rather than left in the prose of `Bootstrap::agentManager()`.
     */
    public function testTheAgentAssemblerCostsFiveGitSubprocessesPerRenderAndThreeWithTheDiffSuppressed(): void
    {
        $repo = self::ensureFixtureRepo();
        $block = EnvironmentBlock::capture($repo, 'stub-model');

        $this->assertSame(
            0,
            self::gitSubprocessesDuring(static function () use ($repo): void {
                for ($i = 0; $i < 10; $i++) {
                    EnvironmentBlock::capture($repo, 'stub-model');
                }
            }),
            'EnvironmentBlock::capture() reached git - it is documented as storing three values and '
                . 'shelling out to nothing',
        );

        $this->assertSame(
            5,
            self::gitSubprocessesDuring(static function () use ($block): void {
                self::probeAgent($block)->systemPrompt();
            }),
            'one Agent::systemPrompt() no longer costs five git subprocesses (branch, status, log, '
                . 'diff --cached, diff)',
        );

        $this->assertSame(
            3,
            self::gitSubprocessesDuring(static function () use ($block): void {
                self::probeAgent($block->withWriteSinceLastRender(false))->systemPrompt();
            }),
            'suppressing the write signal no longer withholds the two diff subprocesses',
        );

        $this->assertSame(
            15,
            self::gitSubprocessesDuring(static function () use ($block): void {
                $agent = self::probeAgent($block);
                $agent->systemPrompt();
                $agent->systemPrompt();
                $agent->systemPrompt();
            }),
            'render() appears to have become memoised - three calls on one agent cost fewer than '
                . 'three renders. That is a behaviour change, not an optimisation: the git section is '
                . 'polled per render on purpose.',
        );
    }

    /**
     * ONE DISPATCH THROUGH THE WORKER RENDERS THE AGENT PROMPT TWICE, and this
     * pins the cost rather than repairing it.
     *
     * `App::dispatchSkill()` and all five `WorkflowEngine` sites build the
     * `CompleteRequest` with `$agent->systemPrompt()` and then hand the SubAgent
     * to the pool, whose `ProcessExecutor::spawnWorker()` calls
     * `$agent->agent->systemPrompt()` a second time to build the worker's
     * startup message. `App::dispatchSkill()`'s own comment says the two
     * consumers "must agree"; nothing makes them agree, because each is a fresh
     * unmemoised render and the working tree can move between them.
     *
     * MEASURED: TEN git subprocesses for one dispatch, not five. That is
     * outside this step's declared file list to change - `ProcessExecutor.php`
     * is not in it - so P3.S6 records the number instead of narrowing the
     * second call away, and the escalation carries the finding.
     *
     * The simulated worker is used deliberately: the second render happens in
     * the PARENT, before `proc_open()`, so the child never has to reach a model
     * for this count to be the real one.
     */
    public function testOneDispatchThroughTheProcessExecutorRendersTheAgentPromptTwice(): void
    {
        $repo = self::ensureFixtureRepo();
        $agent = self::probeAgent(EnvironmentBlock::capture($repo, 'stub-model'));

        $requestCost = self::gitSubprocessesDuring(static function () use ($agent): void {
            new CompleteRequest(
                model: $agent->model,
                messages: [['role' => 'user', 'content' => 'task']],
                systemPrompt: $agent->systemPrompt(),
            );
        });

        $this->assertSame(5, $requestCost, 'building the CompleteRequest no longer costs one render');

        $dispatchCost = self::gitSubprocessesDuring(static function () use ($agent): void {
            $request = new CompleteRequest(
                model: $agent->model,
                messages: [['role' => 'user', 'content' => 'task']],
                systemPrompt: $agent->systemPrompt(),
            );

            $result = (new ProcessExecutor(simulatedWorker: true))->execute(
                new SubAgent(id: 'p3s6-dispatch', agent: $agent, task: 'task'),
                $request,
            );

            self::assertSame(
                'Completed',
                $result->status->name,
                'the simulated worker did not run to completion, so the subprocess count below is '
                    . 'a count of a dispatch that did not happen',
            );
        });

        $this->assertSame(
            10,
            $dispatchCost,
            'one dispatch no longer renders Agent::systemPrompt() exactly twice. If it now renders '
                . 'once, the double render was repaired and this pin should be replaced by one asserting '
                . 'five; if it renders more, a new caller was added.',
        );
    }

    /**
     * THE LIVE SHAPE, WHICH THE TEST ABOVE DOES NOT DRIVE: a workflow-shaped
     * pipeline re-renders the SAME environment block once per stage, and
     * nothing on this path can tell it not to.
     *
     * WHY A SECOND TEST AND NOT AN EDIT TO THE FIRST.
     * {@see testASubAgentDispatchRendersTheEnvironmentBlockOnceHoweverManyChunksTheProviderStreams()}
     * drives `AgentManager::executeSubAgent()`, which
     * `/usr/bin/grep -rn -- '->executeSubAgent(' src/ bin/` finds no caller for
     * anywhere in `src/` or `bin/`. That test pins a real property of a dormant
     * path and it stays. What it cannot see is the per-RUN question, because
     * the shape that asks it is a loop in a different file:
     * `WorkflowEngine.php:1105` `foreach ($nestedStages as $nestedStage)`
     * encloses a render at `:1152`, `executeVerificationStage()` renders twice
     * straight-line at `:1252` and `:1294`, and `WorkflowEngine.php:875`
     * reaches `:1042`/`:1252`/`:1294`/`:1397` once per stage - and unlike the
     * dormant pair, that engine is LIVE from `bin/sugarcrush` via
     * `Bootstrap.php:1183`, wired at `Bootstrap.php:1058`.
     *
     * WHAT IS REPRODUCED HERE. A FRESH `Agent` per iteration with `environment`
     * null and `prompt` empty, rendered inside a loop with the process
     * directory inside the committed git fixture - which is exactly how
     * `WorkflowEngine` builds its per-stage agent, and which is what sends
     * `Agent::systemPrompt()` down its own
     * `EnvironmentBlock::capture(getcwd(), ...)` last resort every time.
     *
     * WHAT IS PINNED, AND WHY EACH FIGURE IS DERIVED RATHER THAN QUOTED. The
     * subprocess count and the distinct-render count are properties of the
     * mechanism and are asserted as exact numbers. The SIZE of the repeated
     * diff tail is a property of the repository being rendered - a brief that
     * reached this file quoted 405 bytes of an 864-byte render from one
     * fixture, and this fixture answers 498 of 967 - so it is computed from a
     * `withWriteSinceLastRender(false)` render of the same block instead of
     * being written down, and then pinned EXACTLY by rebuilding the suppressed
     * render out of the emitted one.
     *
     * AND THE MISSING CHANNEL IS PINNED AS A DECISION. The reason P3.S6 does
     * not wire the write signal here is not only that `WorkflowEngine.php` is
     * outside its declared file list: the parent has nothing to derive the
     * signal FROM. `Runtime::markWriteSinceLastRender()` reads the step's
     * assistant tool calls, and a workflow stage's answer comes back as an
     * {@see AgentResult} that carries none. The constructor's parameter list is
     * asserted here by reflection so that the day a tool-call field is added -
     * which is exactly the change that makes this seam wireable - this test
     * reds and says so, instead of the opportunity passing unnoticed.
     */
    public function testTheWorkflowShapedPipelineReRendersTheSameEnvironmentBlockOncePerStageAndNothingCanTellItNotTo(): void
    {
        $repo = self::ensureFixtureRepo();
        $originalCwd = (string) getcwd();
        $staged = 'Staged changes (git diff --cached, index vs HEAD):';
        $unstaged = 'Unstaged changes (git diff, working tree vs index):';

        try {
            $this->assertTrue(chdir($repo), "could not enter the fixture repository {$repo}");

            foreach ([2, 5] as $stages) {
                /** @var list<string> $renders */
                $renders = [];

                $subprocesses = self::gitSubprocessesDuring(
                    static function () use ($stages, &$renders): void {
                        for ($stage = 0; $stage < $stages; $stage++) {
                            $renders[] = (new Agent(
                                name: 'p3s6-pipeline-stage',
                                description: 'the WorkflowEngine nested-pipeline shape',
                                prompt: '',
                                model: 'stub-model',
                                provider: 'p3s6-counting',
                                tools: [],
                                skillNames: [],
                                hooks: [],
                                isActive: true,
                                environment: null,
                            ))->systemPrompt();
                        }
                    },
                );

                $this->assertSame(
                    5 * $stages,
                    $subprocesses,
                    "a {$stages}-stage pipeline no longer costs five git subprocesses per stage. If it "
                        . 'costs fewer, either render() became memoised or a caller started sharing one '
                        . 'EnvironmentBlock across stages - both change the P3.S6 disposition.',
                );

                $this->assertCount(
                    $stages,
                    $renders,
                    'the loop did not render once per stage, so the counts above describe something else',
                );

                $this->assertSame(
                    1,
                    \count(array_unique($renders)),
                    "the {$stages} stages no longer see one byte-identical prompt. That is the whole "
                        . 'reason the repeated render is waste rather than information: nothing between '
                        . 'stages changed, and the block was re-shelled-out and re-sent anyway.',
                );

                foreach ($renders as $index => $render) {
                    $this->assertSame(
                        1,
                        substr_count($render, $staged),
                        "stage {$index} did not emit the staged-diff section exactly once",
                    );
                    $this->assertSame(
                        1,
                        substr_count($render, $unstaged),
                        "stage {$index} did not emit the unstaged-diff section exactly once",
                    );
                }
            }
        } finally {
            chdir($originalCwd);
        }

        // The size of what every stage after the first re-sends unchanged,
        // DERIVED from the same block rather than written down.
        $block = EnvironmentBlock::capture($repo, 'stub-model');
        $emitted = $block->render();
        $suppressed = $block->withWriteSinceLastRender(false)->render();
        $tail = \strlen($emitted) - \strlen($suppressed);

        $this->assertGreaterThan(
            0,
            $tail,
            'suppressing the write signal no longer removes any bytes, so there is nothing for a '
                . 'per-stage write signal to have saved and this measurement has lost its subject',
        );

        $this->assertStringNotContainsString(
            $staged,
            $suppressed,
            'the suppressed render still carries the staged-diff section',
        );
        $this->assertSame(
            1,
            substr_count($emitted, $staged),
            'the emitted render no longer carries the staged-diff section exactly once',
        );

        $cut = strpos($emitted, "\n\n" . $staged);
        $this->assertIsInt($cut);
        $this->assertSame(
            $suppressed,
            substr($emitted, 0, $cut) . substr($emitted, $cut + $tail),
            'the suppressed render is no longer the emitted render minus one contiguous run of bytes '
                . 'starting at the staged-diff heading. Until that holds, "N bytes re-sent per stage" '
                . 'is a subtraction of two lengths rather than a statement about what is re-sent.',
        );

        $this->assertSame(
            [
                'agentId',
                'status',
                'output',
                'error',
                'tokensUsed',
                'costUsd',
                'startedAt',
                'completedAt',
            ],
            array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                (new \ReflectionMethod(AgentResult::class, '__construct'))->getParameters(),
            ),
            'AgentResult::__construct()\'s parameter list moved. If a tool-call field was ADDED, the '
                . 'parent can finally answer "did this stage write?" for a workflow stage, and the '
                . 'P3.S6 disposition - that the per-step write signal is unwireable on the Agent '
                . 'assembler path because no signal reaches the parent - must be revisited rather '
                . 'than left standing.',
        );
    }

    /**
     * The line numbers of every live `->systemPrompt(` call in one PHP source.
     *
     * Token-driven rather than textual, and the difference is four call sites.
     * MEASURED on this tree with
     * `/usr/bin/grep -ro -- '->systemPrompt(' src/ | wc -l`: TWELVE
     * occurrences, against the EIGHT this scanner reports. The four extras are
     * all prose - one comment inside `App::dispatchSkill()` describing the
     * second render (`ProcessExecutor sends the request's systemPrompt AND,
     * separately,` ...) and three inside `Agent::systemPrompt()`'s own
     * doc-block - so a textual census of the agent assembler's callers would
     * over-count by four, in two of the four files whose classification is the
     * whole content of P3.S6. (That figure was NINE and one before the P3.S6
     * doc-block was corrected in place; it is re-derived here rather than
     * carried forward.)
     *
     * WHAT THE ALPHABET COVERS, stated because an alphabet IS the coverage and
     * a guard must report what it cannot read:
     *
     * - `$x->systemPrompt(` and `$x?->systemPrompt(`. Nullsafe was added after
     *   a review proved its absence by mutation; `?->` appears 98 times in
     *   `src/`, so leaving it out was a hole a real call could hide in.
     * - Whitespace, line comments and doc comments between the operator, the
     *   name and the `(`. `$x-> systemPrompt ();` is legal PHP and the first
     *   version of this scanner, which indexed `$tokens[$i + 1]` and
     *   `$tokens[$i + 2]` directly, dropped it SILENTLY - reporting a smaller
     *   census rather than an unreadable one.
     * - Comments and strings are their own tokens, so skipping them is free;
     *   the declaration is excluded because it is preceded by `T_FUNCTION`
     *   rather than by an object operator, and `buildSystemPrompt()` because a
     *   token is compared whole rather than by suffix. All three exclusions
     *   have a fixture line in
     *   {@see testEveryProductionCallSiteOfTheAgentAssemblerIsDerivedAndAccountedFor()}.
     *
     * WHAT IT STILL CANNOT EXPRESS, and would report as absence rather than as
     * a refusal:
     *
     * - a dynamic name - `$m = 'systemPrompt'; $x->$m();` or
     *   `$x->{'systemPrompt'}()` - because the name is not a `T_STRING` at that
     *   position;
     * - a call routed through `call_user_func([$agent, 'systemPrompt'])`, a
     *   first-class callable stored and invoked later, or any reflection;
     * - a static or parent-scoped spelling (`Agent::systemPrompt()`,
     *   `parent::systemPrompt()`), because it matches only object operators.
     *   MEASURED: none of these shapes occurs in `src/` today, which is why the
     *   roster below is trustworthy and why this paragraph exists - the day one
     *   is written, this scanner under-counts and says nothing.
     *
     * Deliberately NOT a brace walk - it needs no depth - so it is outside the
     * population {@see \SugarCraft\Crush\Tests\Support\InterpolationOpenerTokenTest}
     * polices.
     *
     * @return list<int>
     */
    private static function agentAssemblerCallSites(string $php): array
    {
        $tokens = token_get_all($php);
        $lines = [];

        // Index of the next token that is not whitespace or a comment. Both
        // gaps this steps over - operator to name, name to `(` - are legal PHP.
        $next = static function (array $tokens, int $from): int {
            $k = $from + 1;
            while (
                \is_array($tokens[$k] ?? null)
                && \in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                $k++;
            }

            return $k;
        };

        foreach ($tokens as $i => $token) {
            if (
                !\is_array($token)
                || ($token[0] !== T_OBJECT_OPERATOR && $token[0] !== T_NULLSAFE_OBJECT_OPERATOR)
            ) {
                continue;
            }

            $nameIndex = $next($tokens, $i);
            $name = $tokens[$nameIndex] ?? null;
            if (!\is_array($name) || $name[0] !== T_STRING || $name[1] !== 'systemPrompt') {
                continue;
            }

            if (($tokens[$next($tokens, $nameIndex)] ?? null) !== '(') {
                continue;
            }

            $lines[] = $name[2];
        }

        return $lines;
    }

    /**
     * The fixture agent every P3.S6 measurement runs through: a non-empty
     * prompt (so the assembled string is distinguishable from a bare block)
     * and whatever environment snapshot the caller wants tested.
     */
    private static function probeAgent(?EnvironmentBlock $environment): Agent
    {
        return new Agent(
            name: 'p3s6-probe',
            description: 'P3.S6 measurement fixture',
            prompt: 'P3S6 PROBE PROMPT',
            model: 'stub-model',
            provider: 'p3s6-counting',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
            environment: $environment,
        );
    }

    /**
     * Counts the `git` subprocesses $body starts, by putting a logging shim
     * ahead of the real binary on `PATH`.
     *
     * The shim logs one line and then `exec`s the real git, so the values the
     * block renders are the real repository's and the count is of REAL work
     * rather than of a stub that answers instantly. `PATH` is restored in a
     * `finally`, because a leaked shim would silently re-point every later test
     * in the process at a temp directory that this method then deletes.
     *
     * THE COUNT AND THE CLEANUP ARE IN THE SAME `finally`, and that is a fix
     * rather than a style. The first version computed both AFTER the
     * try/finally, so a `$body()` that threw - an in-body `assertSame()`
     * failure is a PHPUnit exception, and two callers have one - left the
     * directory behind under `sys_get_temp_dir()` with an EXECUTABLE `git`
     * inside it, once per failing run.
     */
    private static function gitSubprocessesDuring(callable $body): int
    {
        $real = trim((string) shell_exec('command -v git 2>/dev/null'));
        self::assertNotSame('', $real, 'no git on PATH: the subprocess census has nothing to shim');

        $dir = sys_get_temp_dir() . '/sugarcrush-p3s6-' . getmypid() . '-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o700, true), "could not create the shim directory {$dir}");

        $log = $dir . '/invocations.log';
        file_put_contents(
            $dir . '/git',
            "#!/bin/sh\n"
            . 'printf \'git\n\' >> ' . escapeshellarg($log) . "\n"
            . 'exec ' . escapeshellarg($real) . ' "$@"' . "\n",
        );
        self::assertTrue(chmod($dir . '/git', 0o755), 'could not make the git shim executable');

        $originalPath = (string) getenv('PATH');
        putenv('PATH=' . $dir . ':' . $originalPath);

        $count = 0;

        try {
            $body();
        } finally {
            putenv('PATH=' . $originalPath);

            $count = is_file($log)
                ? \count((array) file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
                : 0;

            self::removeTree($dir);
        }

        return $count;
    }
}
