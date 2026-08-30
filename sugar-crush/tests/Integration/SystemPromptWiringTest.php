<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Prompt\PromptFixture;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * W1.B3c (crush_feat.md section 6 recommendation #5): integration-level proof
 * that BOTH halves of the section-6 gap actually reach the model.
 *
 * `tests/RuntimeTest.php` (W1.B3a) covers the same two features by reflecting
 * into the private `Runtime::buildSystemPrompt()`. That proves the method
 * assembles the right string, not that any production caller ever receives it:
 * `loadRoot()`/`loadForced()` and `EnvironmentBlock` were each individually
 * correct-and-unit-tested *and* completely unreachable before this wave, which
 * is precisely the failure mode a reflection test cannot catch.
 *
 * So every assertion here reads the `CompleteRequest::$systemPrompt` a real
 * provider is handed, driven from a production entry point:
 * `EngineBackend::complete()` (what `Chat::submit()` calls every turn) and, in
 * the last test, `Chat::update(Enter)` itself, across the real
 * `completeAsync()` fork boundary. Construction mirrors `Bootstrap::backend()`
 * -- one shared `Bootstrap::instructionLoader($root)` threaded into both the
 * engine and `Bootstrap::tools()` -- with only the provider's HTTP layer
 * stubbed, the same seam `BinSugarcrushWiringTest` stubs.
 */
final class SystemPromptWiringTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    /** @var list<PromptFixture> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_sysprompt_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        // BOTH spellings. registryWithProjectSkill() runs
        // SkillManager::loadAll(), which walks ~/.claude/skills and
        // ~/.config/opencode/skills — and those trees read HOME through
        // getenv() now, the same resolution Bootstrap uses, while the
        // team/workflow stores still read $_SERVER['HOME']. Redirecting one
        // and not the other is how a suite ends up quietly reading the
        // developer's own skills. See Tests\Support\HomeSandboxTrait.
        $this->useHomeSandbox($this->tempDir . '/empty-home');
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }
        $this->fixtures = [];

        $this->restoreHomeSandbox();

        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * The headline gap: a repo-root AGENTS.md had zero effect on a session
     * unless the agent happened to touch a file in the root directory, because
     * `loadRoot()` had no caller. Asserted against the request the provider is
     * actually given.
     */
    public function testRootAgentsMdReachesTheProviderSystemPrompt(): void
    {
        file_put_contents($this->tempDir . '/AGENTS.md', 'ROOT AGENTS INTEGRATION MARKER');

        $provider = $this->completeOneTurn();

        $prompt = $this->soleSystemPrompt($provider);
        $this->assertStringContainsString('ROOT AGENTS INTEGRATION MARKER', $prompt);
        $this->assertStringContainsString('<project-instructions>', $prompt);
    }

    /**
     * Root CLAUDE.md is `loadRoot()`'s other half, and its `@import` expansion
     * has to have happened before the wire: an unexpanded `@./AGENTS.md` would
     * reach the model as literal dead text.
     */
    public function testRootClaudeMdArrivesWithItsAtImportsAlreadyExpanded(): void
    {
        file_put_contents($this->tempDir . '/CLAUDE.md', "# Root\n@./AGENTS.md\n");
        file_put_contents($this->tempDir . '/AGENTS.md', 'IMPORTED BODY INTEGRATION MARKER');

        $provider = $this->completeOneTurn();

        $prompt = $this->soleSystemPrompt($provider);
        $this->assertStringContainsString('IMPORTED BODY INTEGRATION MARKER', $prompt);
        $this->assertStringNotContainsString('@./AGENTS.md', $prompt);
        $this->assertSame(1, substr_count($prompt, 'IMPORTED BODY INTEGRATION MARKER'));
    }

    /**
     * The environment half: cwd, git flag, platform, PHP version, model and
     * date existed as `EnvironmentBlock` with no caller anywhere in `src/`, so
     * none of it reached the model at all.
     */
    public function testEnvironmentBlockReachesTheProviderSystemPrompt(): void
    {
        $provider = $this->completeOneTurn();

        $prompt = $this->soleSystemPrompt($provider);
        $this->assertStringContainsString('<env>', $prompt);
        $this->assertStringContainsString('</env>', $prompt);
        $this->assertStringContainsString('Working directory: ' . getcwd(), $prompt);
        $this->assertStringContainsString('Platform: ' . strtolower(PHP_OS_FAMILY), $prompt);
        $this->assertStringContainsString('PHP version: ' . PHP_VERSION, $prompt);
        $this->assertStringContainsString('Model: stub-sysprompt', $prompt);
        $this->assertStringContainsString('Current date: ' . date('Y-m-d'), $prompt);
    }

    /**
     * Both features must land in the SAME prompt. P3.S1 moved <env> to the
     * END of the assembly (stable layers first, volatile last — prompt_expand.md
     * §9.2), so this pin is inverted, not deleted: an inverted assertion still
     * pins an order, and a future reorder that put <env> back ahead of the
     * project instructions would red it. The model still receives the same
     * orientation facts; only their position changed.
     *
     * THE `assertStringEndsWith` BELOW IS A PRODUCTION-PATH PIN: it reads a
     * real `CompleteRequest`, so a layer appended after the assembled prompt
     * on the way to the provider reds it. MEASURED: a suffix added after
     * `Runtime::run()`'s `$systemPrompt = $this->buildSystemPrompt($app);`
     * reds this test and
     * {@see testDiscoveredSkillsAreListedInTheProviderSystemPrompt()} — the
     * only two transmitted-prompt tests carrying that pin — and nothing else
     * across tests/Integration, tests/Context, tests/RuntimeTest.php,
     * tests/Agents/AgentTest.php and tests/Providers/PromptStabilityTest.php.
     *
     * What it cannot see is a reorder that still leaves <env> at the TAIL:
     * the layer-5 move, after <project-memory> but before the skill layers,
     * which this App cannot expose because it enables and discovers no skills,
     * so those layers render empty and <env> ends the prompt anyway. A reorder
     * that leaves any non-empty layer behind <env> — <env> back to layer 2,
     * say — does red this pin. That is the narrow gap, and
     * testDiscoveredSkillsAreListedInTheProviderSystemPrompt() covers it.
     */
    public function testBothHalvesLandInOneSystemPromptWithEnvironmentLast(): void
    {
        file_put_contents($this->tempDir . '/AGENTS.md', 'BOTH HALVES INTEGRATION MARKER');

        $provider = $this->completeOneTurn();

        $prompt = $this->soleSystemPrompt($provider);
        $this->assertStringContainsString('<env>', $prompt);
        $this->assertStringContainsString('BOTH HALVES INTEGRATION MARKER', $prompt);
        $this->assertLessThan(
            strpos($prompt, '<env>'),
            strpos($prompt, '<project-instructions>'),
            'the environment block must follow the project instructions in the assembled prompt',
        );
        $this->assertStringEndsWith(
            "\n</env>",
            $prompt,
            '<env> must be the LAST bytes of the system prompt a real provider is handed (P3.S1)',
        );
    }

    /**
     * `EngineBackend::complete()` runs a bounded agentic loop, calling
     * `Runtime::run()` once per step, and every step of a turn must be handed
     * a byte-identical prompt: a per-step re-capture would let the reported
     * working directory, model name and date drift inside a single turn. Only
     * a real multi-step loop can demonstrate that -- a single
     * `buildSystemPrompt()` call cannot.
     *
     * THIS PARAGRAPH USED TO SAY the environment block "documents itself as a
     * point-in-time snapshot and shells out to git three times to build one",
     * and neither half is true. `EnvironmentBlock::capture()` freezes exactly
     * three values -- working directory, model name and timestamp -- while the
     * git section is polled LIVE on every render() (pinned by
     * `PromptStabilityTest::testEnvironmentBlockGitSnapshotIsLivePolledNotFrozenAtCapture()`),
     * and the per-render subprocess count and its qualifications are
     * documented on `EnvironmentBlock` itself rather than restated here.
     *
     * The correction bounds what the assertion below proves. The block in
     * THIS test is all frozen triple and no git section: `backend()`
     * never calls `withRoot()`, so the captured directory is `getcwd()` --
     * asserted green in
     * {@see testEnvironmentBlockReachesTheProviderSystemPrompt()} as
     * `'Working directory: ' . getcwd()` -- and `isGitRepo()`, a bare
     * `file_exists($cwd . '/.git')`, is false there. So byte-identity across
     * steps is a statement about that frozen triple, NOT a promise that a
     * real repository's status cannot legitimately change mid-turn.
     *
     * AND THAT IS A FACT ABOUT THE DIRECTORY THE SUITE IS RUN FROM, not about
     * this checkout. Run phpunit from the monorepo root and `getcwd()` is the
     * worktree root, whose `.git` exists (as a file, for a worktree), so the
     * same test renders a live git section; the same happens in a split-repo
     * clone of `sugarcraft/sugar-crush`, where the package directory IS the
     * repository root. The assertion is still the right one -- what it is
     * exercising just is not constant across working directories, and this
     * paragraph should not imply it is.
     *
     * P3.S5 INVERTED THIS PIN, deliberately, the way P3.S1 inverted the three
     * ordering pins above. `EngineBackend::complete()`'s loop now calls
     * {@see \SugarCraft\Crush\Runtime::markWriteSinceLastRender()} after every
     * step, and the only tool call step 0 makes here is `no_such_tool`, which
     * is not write-capable, so `Runtime::stepRequestedAWrite()` derives FALSE
     * and step 1's `<env>` block suppresses the two git diff sections.
     *
     * NOTHING IS DROPPED: an inverted assertion still pins a relationship, a
     * deleted one pins nothing. The single equality became an equality against
     * the RECONSTRUCTED suppressed form, plus a pin that the marker it cuts on
     * occurs exactly once in the emitting prompt and not at all in the
     * suppressed one, plus a full-shape pin on the tail that came off. The
     * surviving invariant is the one this test was always really about — every
     * byte before that cut, the frozen triple included, is still identical
     * across the two steps, and the two diff sections are the ONLY licensed
     * mid-turn difference.
     *
     * THE METHOD NAME NOW OVERSTATES AND IS KEPT ANYWAY, and the reason first
     * written here was wrong. It claimed
     * {@see \SugarCraft\Crush\Tests\SymbolCitationDriftTest} would red on a
     * renamed method because `src/Runtime.php` cites this test in a backticked
     * doc-block reference. MEASURED, twice, against that census run from
     * `sugar-crush/`: rewriting the cited METHOD name to one that was never
     * written leaves it `OK (7 tests, 2952 assertions)`, and rewriting the
     * cited CLASS name to one that does not exist leaves it `OK (7 tests, 2952
     * assertions)` as well. That census does not police this citation form at
     * all, so the constraint invoked did not exist.
     *
     * WHAT IS ACTUALLY TRUE is smaller and is not a census: the citing bullet
     * in `Runtime.php` names this method in PROSE, `Runtime.php` is outside
     * this step's one-file declared list, and nothing in the tree would catch
     * the stranded reference — which is the argument for renaming BOTH in one
     * diff rather than for renaming neither. That is a §1.10 outcome-3
     * escalation, recorded for the orchestrator, not a decision this step may
     * take on its own.
     */
    public function testEveryStepOfOneTurnGetsTheIdenticalSystemPrompt(): void
    {
        file_put_contents($this->tempDir . '/AGENTS.md', 'MULTI STEP INTEGRATION MARKER');

        // THE GIT REGIME IS FORCED HERE, not inherited from `getcwd()`, and
        // that is the whole reason this test bites at all.
        //
        // `EnvironmentBlock::isGitRepo()` is a bare `file_exists($cwd .
        // '/.git')` against the CAPTURED directory, and the captured directory
        // is `Runtime::projectRoot()`, which is `$app->root ?? getcwd()`.
        // Every other test in this file leaves the root null, so the block
        // describes wherever phpunit was started from — and a `sugar-crush/`
        // package directory holds no `.git`, so it renders `Is directory a git
        // repo: No` and NO git section at all. There is then nothing for the
        // write signal to suppress.
        //
        // MEASURED, and this is why the fixture below is not optional: with
        // the root left null and `markWriteSinceLastRender()` deleted from
        // `EngineBackend::complete()`'s loop, this test stayed GREEN from
        // `sugar-crush/` — the invocation CLAUDE.md, AGENTS.md and
        // CONTRIBUTING.md all document — and only reds from the checkout root.
        // Half the runs of the assertion below would have been decorative.
        //
        // An EMPTY `.git` DIRECTORY IS ENOUGH, because `isGitRepo()` looks at
        // nothing else. It is the same shape
        // {@see \SugarCraft\Crush\Tests\Context\EnvironmentBlockTest} builds:
        // the gate opens, every `git` invocation then fails against a
        // directory that is not a repository, and each field reports its own
        // exit code rather than an empty string. That is what makes this
        // fixture better than a real repository for THIS test — the two
        // sections are one deterministic line each, independent of whatever
        // the developer's working tree happens to hold, so the tail can be
        // pinned by shape instead of by a heuristic over diff bodies.
        mkdir($this->tempDir . '/.git');

        $provider = $this->completeOneTurn(toolCallOnFirstStep: true, root: $this->tempDir);

        $this->assertCount(2, $provider->requests, 'expected a tool-calling step followed by an answering step');

        $first = (string) $provider->requests[0]->systemPrompt;
        $second = (string) $provider->requests[1]->systemPrompt;

        $this->assertStringContainsString(
            "\nIs directory a git repo: Yes\n",
            $first,
            'the fixture must put the block in the git regime, or nothing below tests the suppression',
        );

        $marker = "\n\nStaged changes (git diff --cached, index vs HEAD):";

        // The cut marker must be unambiguous or the reconstruction below would
        // silently cut in the wrong place, and this scans the WHOLE prompt.
        // `Recent commits:` renders commit subjects verbatim and a subject
        // could carry this text, but `git log --oneline` emits one line per
        // commit and `git status --porcelain` one line per path, so nothing
        // above can be preceded by the blank line the marker opens with. Under
        // this fixture both of those fields are an `unavailable (git exited N)`
        // line anyway. Asserted, not argued.
        $this->assertSame(1, substr_count($first, $marker), 'the cut marker must occur exactly once in the emitting step prompt');
        $this->assertSame(0, substr_count($second, $marker), 'the suppressed step must carry no staged-diff section at all');

        $cut = (int) strpos($first, $marker);
        $tail = substr($first, $cut);

        $this->assertSame(
            substr($first, 0, $cut) . "\n</env>",
            $second,
            'the second step must be the first with exactly the two diff sections cut — nothing else may drift mid-turn',
        );

        // EXCLUSIVITY, and it is the deterministic fixture that buys it. There
        // is NO `/s` here and no `.*`: `[^\n]*` cannot cross a line, so this
        // says the tail is exactly two one-line sections and the closing
        // fence, in that order, and nothing else. A third section — the
        // failure mode the reconstruction above structurally cannot see, since
        // anything added after the cut is suppressed on both sides of that
        // comparison — reds here whether it is separated by a blank line or by
        // a single newline.
        //
        // MEASURED, both mutations applied to the two `gitDiffSection()` calls
        // in `src/Context/EnvironmentBlock.php` and both reverted: appending
        // `. "\n\nSmuggled third section: yes"` reds, and appending
        // `. "\nSmuggled third section: yes"` reds. An earlier version of this
        // assertion counted blank-line separators instead; it caught the first
        // mutation and passed the second, `OK (1 test, 7 assertions)`. This
        // shape is strictly stronger, which is why the count is gone rather
        // than weakened — it also carried a false-red bound on git's
        // `diff.suppressBlankEmpty` that a one-line section cannot have.
        $this->assertMatchesRegularExpression(
            '~^\n\nStaged changes \(git diff --cached, index vs HEAD\): [^\n]*\n\nUnstaged changes \(git diff, working tree vs index\): [^\n]*\n</env>$~',
            $tail,
            'the tail must be exactly the two one-line diff sections and the closing fence',
        );

        $this->assertStringContainsString('MULTI STEP INTEGRATION MARKER', $second);
    }

    /**
     * Top of the production chain: a keystroke. `Chat::update(Enter)` ->
     * `Chat::submit()` -> `EngineBackend::completeAsync()` (pcntl_fork(), the
     * same boundary every live bin/sugarcrush turn crosses) ->
     * `Runtime::run()`. The stub provider echoes the system prompt back as its
     * answer because only the returned Message survives the fork -- state
     * recorded on a provider inside the child dies with the child.
     */
    public function testARealChatKeystrokeTurnDeliversBothHalves(): void
    {
        file_put_contents($this->tempDir . '/AGENTS.md', 'CHAT TURN INTEGRATION MARKER');

        $chat = new Chat(backend: $this->backend($this->echoingProvider()));

        $withInput = new \ReflectionMethod($chat, 'withInputBuf');
        $withInput->setAccessible(true);
        $chat = $withInput->invoke($chat, 'what are this project conventions?');

        [$afterSubmit, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);

        $asyncCmd = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\AsyncCmd::class, $asyncCmd);

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertInstanceOf(\SugarCraft\Crush\AssistantMsg::class, $resolved, 'the completion did not finish within the test timeout');

        [$final] = $afterSubmit->update($resolved);
        $answer = $final->history[array_key_last($final->history)]->content;

        $this->assertStringContainsString('CHAT TURN INTEGRATION MARKER', $answer);
        $this->assertStringContainsString('<env>', $answer);
        $this->assertStringContainsString('Model: echo-sysprompt', $answer);
    }

    /**
     * W3.S8 (crush_feat.md section 7 E1/E2): a populated skill registry is
     * only half the wiring — the model needs the Level-1 listing in its
     * system prompt, or the `Skill` tool is one it has no reason to call.
     * Asserted on the request a provider is actually handed, for the same
     * reason as the tests above: `SkillMatcher` was unit-tested and had no
     * production caller at all before this step.
     *
     * IT ALSO CARRIES THE ONLY WIRE-LEVEL ORDERING PIN, because it is the one
     * test in this file that both drives a populated `SkillRegistry` through
     * `EngineBackend::complete()` and reads a real `CompleteRequest`. P3.S1's
     * decision is that <env> is emitted after every cacheable layer, the
     * skill listing included; the assembler-side pin for that lives in
     * {@see testTheFixtureAssemblesEveryControlledHalfInTheRealOrder()},
     * which calls the private `buildSystemPrompt()` through a scoped Closure.
     * Without the two assertions below the wire had NO pin on it: the other
     * transmitted-prompt test,
     * {@see testBothHalvesLandInOneSystemPromptWithEnvironmentLast()},
     * registers no skill registry, so both skill layers render empty there and
     * <env> is trivially last however the append is ordered.
     *
     * MEASURED 2026-08-29: with the env append moved to layer 5 — after the
     * memory block, before the skill bodies and this listing — this test reds
     * "Failed asserting that 5415 is less than 5185" while
     * testBothHalvesLandInOneSystemPromptWithEnvironmentLast() stays green.
     * That is the reorder the step exists to forbid, and before this it was
     * caught only by a reflection test and a regenerable byte golden.
     */
    public function testDiscoveredSkillsAreListedInTheProviderSystemPrompt(): void
    {
        $registry = $this->registryWithProjectSkill('sysprompt-marker-skill', 'Marker skill for the system-prompt listing.');

        $provider = $this->capturingProvider(false);
        $this->backend($provider)->withSkillRegistry($registry)->complete([Message::user('hello')]);

        $prompt = $this->soleSystemPrompt($provider);
        $this->assertStringContainsString('Available skills (invoke via Skill tool):', $prompt);
        $this->assertStringContainsString(
            '- sysprompt-marker-skill: Marker skill for the system-prompt listing.',
            $prompt,
        );
        $this->assertLessThan(
            strpos($prompt, '<env>'),
            strpos($prompt, 'Available skills (invoke via Skill tool):'),
            'the skill listing must precede <env> on the bytes a provider receives, not only in '
            . 'the assembler return value (P3.S1)',
        );
        $this->assertStringEndsWith(
            "\n</env>",
            $prompt,
            '<env> must be the LAST bytes of the transmitted system prompt in a session that '
            . 'actually rendered skill layers (P3.S1)',
        );
    }

    /**
     * A session that discovered nothing must be byte-identical to before the
     * listing existed — an empty registry may not leave a dangling header.
     */
    public function testAnEmptyRegistryAddsNothingToTheSystemPrompt(): void
    {
        $provider = $this->completeOneTurn();

        $this->assertStringNotContainsString('Available skills', $this->soleSystemPrompt($provider));
    }

    /**
     * The fixture's own reason to exist, asserted end-to-end: one fixture
     * instance controls EVERY context half the real assembly reads — root
     * instruction files, project memory, a composer-manifest workspace and a
     * registered skill — and the assembled prompt carries each in the order
     * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} documents, with
     * no mocks anywhere in the chain (real EnvironmentBlock, MemoryBlock,
     * RepoMapBlock, InstructionFileLoader and SkillMatcher).
     *
     * P3.S1 moved <env> to the END of that order (stable layers first,
     * volatile last), so the first chain link is inverted, not deleted: the
     * repo map must now precede the environment block, and a reorder that
     * put <env> back ahead of it reds this assertion.
     *
     * THE CHAIN OF `assertLessThan`s IS NOT ENOUGH ON ITS OWN, and the two
     * assertions after it are why. Every ordering pin P3.S1 inverted — here
     * and in RuntimeTest, MemoryPromptWiringTest, FeatWiringReachabilityTest
     * and RepoMapBlockTest — compares <env> against a layer that precedes
     * the skills, so all of them stay green with <env> emitted at layer 5,
     * after <project-memory> and BEFORE the skill bodies and the skill
     * listing. MEASURED 2026-08-29, before these assertions existed: that
     * move left 1164 tests / 5250 assertions green across tests/Integration,
     * tests/Context, tests/RuntimeTest.php, tests/Agents/AgentTest.php and
     * tests/Providers/PromptStabilityTest.php, and the only red anywhere was
     * the byte golden — a file six scheduled steps are licensed to
     * regenerate.
     *
     * THE GOLDEN WAS NEVER INSIDE THAT 1164:
     * `BaseSystemPromptTest::testSystemPromptMatchesCommittedGolden()` lives
     * in tests/BaseSystemPromptTest.php, which is in NONE of those five
     * paths. The run was green because the only guard that could catch the
     * reorder was in a different file. Layer 5 is precisely the position the
     * cache argument rules out: the skill bodies and the listing would then
     * sit downstream of the block that changes on every file write. So the
     * listing is pinned before <env> explicitly here, and the prompt is
     * pinned to END at </env> — stated as an assertion rather than left to a
     * regenerable fixture.
     *
     * MEASURED under that same layer-5 move with these assertions in place:
     * this test reds "Failed asserting that 4025 is less than 3764".
     *
     * This is the ASSEMBLER-side pin; it reads {@see PromptFixture}, which
     * calls the private `buildSystemPrompt()` through a scoped Closure. The
     * wire-side pin for the same invariant is in
     * {@see testDiscoveredSkillsAreListedInTheProviderSystemPrompt()}.
     */
    public function testTheFixtureAssemblesEveryControlledHalfInTheRealOrder(): void
    {
        $fixture = new PromptFixture();
        $this->fixtures[] = $fixture;
        $fixture->write('AGENTS.md', 'FIXTURE AGENTS MARKER');
        $fixture->memoryStore()->add('FIXTURE MEMORY MARKER', MemoryScope::Project);
        $fixture->writeJson('composer.json', ['name' => 'acme/fixture-lib', 'autoload' => ['psr-4' => ['Acme\\Fixture\\' => 'src/']]]);
        $fixture->write('src/One.php', '<?php');
        $fixture->addSkill(new Skill(
            name: 'fixture-demo-skill',
            description: 'Fixture skill for the harness test.',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'low',
            context: 'thread',
            paths: [],
            content: 'FIXTURE SKILL BODY',
            sourcePath: '/fixture/SKILL.md',
        ));

        $prompt = $fixture->systemPrompt();

        $envAt = strpos($prompt, '<env>');
        $mapAt = strpos($prompt, '<repo-map>');
        $instructionsAt = strpos($prompt, '<project-instructions>');
        $memoryAt = strpos($prompt, '<project-memory>');
        $skillAt = strpos($prompt, '## Skill: fixture-demo-skill');
        $listingAt = strpos($prompt, 'Available skills (invoke via Skill tool):');

        foreach ([$envAt, $mapAt, $instructionsAt, $memoryAt, $skillAt, $listingAt] as $at) {
            $this->assertIsInt($at, 'one of the six prompt halves never reached the assembled prompt');
        }
        $this->assertLessThan($envAt, $mapAt);
        $this->assertLessThan($instructionsAt, $mapAt);
        $this->assertLessThan($memoryAt, $instructionsAt);
        $this->assertLessThan($skillAt, $memoryAt);
        $this->assertLessThan($listingAt, $skillAt);
        $this->assertLessThan(
            $envAt,
            $listingAt,
            '<env> must follow every cacheable layer, the skill listing included',
        );
        $this->assertStringEndsWith(
            "\n</env>",
            $prompt,
            '<env> must be the LAST layer of the assembled prompt (P3.S1)',
        );

        $this->assertStringContainsString('FIXTURE AGENTS MARKER', $prompt);
        $this->assertStringContainsString('FIXTURE MEMORY MARKER', $prompt);
        $this->assertStringContainsString('- src/  ->  Acme\\Fixture\\  (1 files)', $prompt);
        $this->assertStringContainsString('## Skill: fixture-demo-skill', $prompt);
        $this->assertStringContainsString('FIXTURE SKILL BODY', $prompt);
        $this->assertStringContainsString(
            '- fixture-demo-skill: Fixture skill for the harness test.',
            $prompt,
        );
    }

    /**
     * P2.S1's injectability, pinned through the fixture: the assembled prompt
     * names the fixture's own directory, a fixed platform and a fixed date,
     * so later phases can assert on prompt content without a single
     * host-dependent byte.
     */
    public function testTheFixturePinsDatePlatformAndRootIntoThePrompt(): void
    {
        $fixture = new PromptFixture();
        $this->fixtures[] = $fixture;

        $prompt = $fixture->systemPrompt();

        $this->assertStringContainsString('Working directory: ' . $fixture->root(), $prompt);
        $this->assertStringContainsString('Platform: linux', $prompt);
        $this->assertStringContainsString('Current date: 2026-01-15', $prompt);
        $this->assertStringContainsString('Is directory a git repo: No', $prompt);
    }

    /**
     * The empty side of the fixture: an instance given nothing to control
     * must assemble the same prompt shape a bare Runtime does — base +
     * environment only — with no empty containers the model has to
     * interpret.
     */
    public function testAFixtureWithoutMemoryOrSkillsAddsNeitherBlock(): void
    {
        $fixture = new PromptFixture();
        $this->fixtures[] = $fixture;

        $prompt = $fixture->systemPrompt();

        $this->assertStringContainsString('<env>', $prompt);
        $this->assertStringNotContainsString('<repo-map>', $prompt);
        $this->assertStringNotContainsString('<project-instructions>', $prompt);
        $this->assertStringNotContainsString('<project-memory>', $prompt);
        $this->assertStringNotContainsString('Available skills', $prompt);
    }

    /**
     * Discover one project-scoped SKILL.md written under this test's temp
     * root, the same way `Bootstrap::skillRegistry()` does (that method is
     * private, so the SkillManager pair it uses is constructed here).
     */
    private function registryWithProjectSkill(string $name, string $description): SkillRegistry
    {
        $dir = $this->tempDir . '/.sugar-crush/skills/' . $name;
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/SKILL.md',
            "---\ndescription: {$description}\nuser-invocable: true\ndisable-model-invocation: false\n---\n# {$name}\n\nBody.\n",
        );

        $registry = new SkillRegistry();
        (new SkillManager(new SkillLoader(), $registry))->loadAll($this->tempDir);

        return $registry;
    }

    /**
     * Drive one real `EngineBackend::complete()` turn against a capturing
     * provider and hand the provider back for assertions.
     */
    private function completeOneTurn(bool $toolCallOnFirstStep = false, ?string $root = null): object
    {
        $provider = $this->capturingProvider($toolCallOnFirstStep);

        // `withRoot(null)` is the constructor default, so every caller that
        // omits $root keeps the behaviour it had: the `EnvironmentBlock`
        // describes `getcwd()`. Only the caller that needs a DETERMINISTIC
        // git regime passes one -- see
        // {@see testEveryStepOfOneTurnGetsTheIdenticalSystemPrompt()}.
        $this->backend($provider)->withRoot($root)->complete([Message::user('hello')]);

        return $provider;
    }

    /**
     * Construct the backend the way `Bootstrap::backend()` does -- one shared
     * `InstructionFileLoader` threaded into both the engine and the
     * Read/Edit/Glob tools -- swapping only the provider.
     *
     * `Bootstrap::hooks()`/`::skillRegistry()` are private, so they cannot be
     * called from here; `EngineBackend` falls back to the equivalent defaults
     * (a `HookManager` with `registerBuiltIns()`, an empty `SkillRegistry`),
     * neither of which touches system-prompt assembly.
     */
    private function backend(ProviderInterface $provider): EngineBackend
    {
        $loader = Bootstrap::instructionLoader($this->tempDir);

        return (new EngineBackend($provider, $provider->name()))
            ->withTools(Bootstrap::tools($this->tempDir, $loader))
            ->withInstructionLoader($loader);
    }

    /**
     * Records every {@see CompleteRequest} the engine builds so the system
     * prompt can be asserted on exactly as the provider receives it.
     *
     * Non-streaming on purpose: `Runtime::run()` picks `runBatch()` over
     * `runStreaming()` from `supportsStreaming()`, and only `runBatch()`
     * hands back a single deterministic response per step.
     *
     * @param bool $toolCallOnFirstStep Emit an unresolvable tool call on the
     *        first step so `EngineBackend::complete()` feeds the error result
     *        back and takes a genuine second lap of its agentic loop.
     */
    private function capturingProvider(bool $toolCallOnFirstStep): object
    {
        return new class($toolCallOnFirstStep) implements ProviderInterface {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            public function __construct(private readonly bool $toolCallOnFirstStep) {}

            public function name(): string
            {
                return 'stub-sysprompt';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
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
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->requests[] = $request;

                return $this->toolCallOnFirstStep && count($this->requests) === 1
                    ? new CompleteResponse(
                        content: 'looking that up',
                        toolCalls: [new ToolCall('call_sysprompt_1', 'no_such_tool', [])],
                    )
                    : new CompleteResponse(content: 'answered');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }

    /**
     * Answers with the system prompt it was given.
     *
     * `EngineBackend::completeAsync()` forks, so a provider that merely
     * recorded requests would record them in a child whose memory is thrown
     * away. Echoing the prompt into the response content routes it back over
     * the result socket as ordinary message text -- the only channel that
     * survives that boundary.
     */
    private function echoingProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function name(): string
            {
                return 'echo-sysprompt';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
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
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return new CompleteResponse(content: (string) $request->systemPrompt);
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }

    private function soleSystemPrompt(object $provider): string
    {
        $this->assertCount(1, $provider->requests, 'expected exactly one provider round-trip');

        $prompt = $provider->requests[0]->systemPrompt;
        $this->assertIsString($prompt);

        return $prompt;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
