<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\Tool;

/**
 * The base system prompt was one sentence — `'You are SugarCrush, an AI coding
 * assistant.'` — and everything appended after it is DATA (the `<env>` block,
 * `<project-instructions>` fences, skill bodies, the skill listing), not
 * guidance. crush_code.md Phase 5 item 1 calls replacing it the
 * highest-leverage single change in that phase.
 *
 * These tests assert the prompt's STRUCTURE and the TRUTHFULNESS of its
 * factual claims, never its wording. A test pinning the paragraph would be
 * rewritten the next time anyone tunes a sentence and would catch nothing in
 * the meantime; what actually matters, and what actually regresses, is a
 * section going missing or a clause describing a tool this app does not ship.
 *
 * `tests/RuntimeTest.php` already reflects into `buildSystemPrompt()` for the
 * assembly order, and `tests/Integration/SystemPromptWiringTest.php` proves a
 * real turn is handed the result. This file covers only the base literal.
 *
 * @see Runtime::buildSystemPrompt()
 */
final class BaseSystemPromptTest extends TestCase
{
    /**
     * Every `# ` heading the base prompt is required to carry, i.e. the four
     * guidance categories the finding enumerates as missing.
     */
    private const REQUIRED_SECTIONS = [
        '# Tone and style',
        '# Tool use',
        '# Acting vs. asking',
        '# Security',
    ];

    /**
     * The final line of the base heredoc in
     * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()}.
     *
     * The base prompt used to be *defined* as "everything before the first
     * <env>", which worked while <env> was layer 2 of the assembly. P3.S1
     * moved <env> to the very end (stable layers first, volatile last), so
     * that slice would now return the whole prompt; the base is instead
     * delimited by this marker, the sentence its heredoc closes with. A
     * reword of that closing sentence reds {@see basePrompt()} until the
     * marker moves with it — the deliberate cost of an explicit boundary.
     */
    private const BASE_END_MARKER = 'commands to follow.';

    private function basePrompt(): string
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('echo');

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $method = new \ReflectionMethod($runtime, 'buildSystemPrompt');
        $method->setAccessible(true);

        $whole = (string) $method->invoke($runtime, App::new($provider, 'echo'));

        // The base prompt is delimited by its own explicit end-of-base
        // marker, the final line of the base heredoc — NOT by the first
        // <env>, which P3.S1 moved from layer 2 to the very end of the
        // assembly (stable layers first, volatile last — prompt_expand.md
        // §9.2). "Everything before the first <env>" would now be the whole
        // prompt, so the slice cuts at the marker instead; cutting there is
        // what keeps these assertions about the base literal rather than
        // about the repo-map and <env> data half that now follows it. If the
        // heredoc's closing line is ever reworded, this marker must move with
        // it — the assertion below says so out loud.
        $markerAt = strpos($whole, self::BASE_END_MARKER);
        self::assertNotFalse(
            $markerAt,
            'the base prompt no longer ends with its end-of-base marker "' . self::BASE_END_MARKER . '"',
        );

        return substr($whole, 0, $markerAt + strlen(self::BASE_END_MARKER));
    }

    /**
     * The run of words between the previous clause boundary and $subject.
     *
     * This exists because substring checks have no POLARITY, and that is not a
     * theoretical objection: an adversarial round flipped `"they are confined"`
     * to `"they are never confined"` and the whole 6,800-test suite stayed
     * green, because `assertStringContainsString('confined', $base)` is true of
     * both. Reading the words immediately BEFORE the claim is what catches the
     * inserted negator.
     *
     * The window stops at the nearest `. ; : ,` or newline rather than taking
     * the whole sentence, and that bound is deliberate: the prompt legitimately
     * says "never walked" and "not exhaustive" elsewhere in the same sentence,
     * so a sentence-wide negator scan would fire on honest prose. A negation of
     * THIS claim has to sit between the boundary and the claim.
     */
    private static function qualifierBefore(string $text, string $subject): string
    {
        $at = strpos($text, $subject);
        self::assertNotFalse($at, sprintf('the prompt no longer says anything about "%s"', $subject));

        $boundary = 0;
        foreach (['.', ';', ':', ',', "\n"] as $delimiter) {
            $found = strrpos(substr($text, 0, $at), $delimiter);
            if ($found !== false && $found + 1 > $boundary) {
                $boundary = $found + 1;
            }
        }

        return substr($text, $boundary, $at - $boundary);
    }

    /**
     * Assert the prompt asserts $subject rather than denying it.
     *
     * Pair this with a probe that proves the underlying fact. On its own it
     * only says the sentence is not negated; the fact itself is what the
     * driving half of each test below establishes.
     */
    private function assertPromptDoesNotNegate(string $base, string $subject): void
    {
        $qualifier = self::qualifierBefore($base, $subject);

        foreach (['never', 'not', 'no', 'nor', 'rarely', 'seldom', 'cannot', "n't"] as $negator) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?:^|\W)' . preg_quote($negator, '/') . '(?:\W|$)/i',
                $qualifier,
                sprintf(
                    'the prompt negates its own claim about "%s": the words immediately before '
                    . 'it are "%s", and the code says the claim HOLDS',
                    $subject,
                    trim($qualifier),
                ),
            );
        }
    }

    /**
     * The identity clause two existing RuntimeTest assertions already depend
     * on. Restated here so the coupling is visible from this file: rewording it
     * away is a deliberate change, not an incidental one.
     */
    public function testBasePromptStillIdentifiesItselfAsSugarCrush(): void
    {
        $base = $this->basePrompt();

        $this->assertStringStartsWith('You are SugarCrush', $base);
        $this->assertStringContainsString('AI coding assistant', $base);
    }

    /**
     * The four missing guidance categories, as sections. Headings rather than
     * keywords: a heading is what makes the prompt navigable for the model and
     * is the thing a careless edit actually deletes.
     *
     * Anchored to the start of a line, which is the whole difference between
     * asserting a heading and asserting a heading LEVEL: `'# Security'` is a
     * substring of `'## Security'`, so a plain `assertStringContainsString`
     * cannot tell a top-level section from a nested one — a mutation demoting
     * every heading by one level survived exactly that assertion. Either the
     * level is enforced or it should not be claimed; this enforces it.
     */
    public function testBasePromptCarriesEveryRequiredGuidanceSection(): void
    {
        $base = $this->basePrompt();

        foreach (self::REQUIRED_SECTIONS as $section) {
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($section, '/') . '$/m',
                $base,
                sprintf(
                    '"%s" must appear as a whole line at exactly this heading level — not '
                    . 'nested under a deeper one, and not with trailing text',
                    $section,
                ),
            );
        }
    }

    /**
     * Sections in a fixed order, each non-empty. Ordering is asserted because
     * a heading followed immediately by the next heading satisfies the test
     * above while carrying no guidance at all.
     */
    public function testEveryGuidanceSectionHasABodyUnderIt(): void
    {
        $base = $this->basePrompt();
        $offsets = [];

        foreach (self::REQUIRED_SECTIONS as $section) {
            $at = strpos($base, $section);
            $this->assertNotFalse($at, $section);
            $offsets[$section] = $at;
        }

        $this->assertSame(
            array_keys($offsets),
            array_keys((static function (array $o): array {
                asort($o);

                return $o;
            })($offsets)),
            'guidance sections are out of order',
        );

        $bounds = array_values($offsets);
        foreach (self::REQUIRED_SECTIONS as $index => $section) {
            $start = $bounds[$index] + strlen($section);
            $end = $bounds[$index + 1] ?? strlen($base);
            $this->assertGreaterThan(
                40,
                strlen(trim(substr($base, $start, $end - $start))),
                sprintf('section "%s" has no body under it', $section),
            );
        }
    }

    /**
     * Ordinary capitalised English the prompt is allowed to contain.
     *
     * This is an ALLOWLIST on purpose, and it is the whole reason the test
     * below has power. Filtering candidates down to words that are already
     * known tool names, and then asserting they are tool names, is a
     * tautology — the first draft of this test did exactly that, and a
     * mutation inserting a nonexistent `NotebookEdit` sailed through it.
     * Diffing against prose instead means anything unrecognised fails, so a
     * tool name that is not registered cannot slip in unnoticed.
     *
     * Adding a word here is the correct fix when the prompt gains ordinary
     * prose; adding a TOOL name here would be wrong, and the failure message
     * says so.
     */
    private const PROSE_WORDS = [
        'AI', 'Act', 'Acting', 'Before', 'If', 'Keep', 'Never', 'Reach',
        'Security', 'Skip', 'SugarCrush', 'That', 'They', 'Tone', 'Tool',
        'Treat', 'When', 'You',
    ];

    /**
     * The one-line prompt's replacement is a large volume of prose making
     * factual claims about tools, which is exactly where a claim written next
     * to the wrong subject ships. So: every capitalised word in the prompt is
     * either recognisable prose ({@see PROSE_WORDS}) or a tool a real session
     * actually has.
     *
     * The tool set is READ OFF {@see Bootstrap::tools()} rather than listed
     * here — a literal list is the thing that goes stale the next time the tool
     * set changes, and it is the tool set, not this file, that decides what is
     * true.
     */
    public function testBasePromptNamesNoToolThisAppDoesNotShip(): void
    {
        $real = array_map(static fn (Tool $tool): string => $tool->name(), Bootstrap::tools(sys_get_temp_dir()));
        $this->assertNotEmpty($real, 'Bootstrap::tools() returned nothing to check against');

        preg_match_all('/\b[A-Z][A-Za-z]+\b/', $this->basePrompt(), $matches);

        // "several Reads" is a reference to the Read tool, so a trailing plural
        // is folded away before the comparison rather than being allowlisted as
        // if it were prose.
        $words = array_map(
            static fn (string $word): string => in_array(rtrim($word, 's'), $real, true) ? rtrim($word, 's') : $word,
            array_values(array_unique($matches[0])),
        );

        $unrecognised = array_values(array_diff($words, $real, self::PROSE_WORDS));

        $this->assertSame(
            [],
            $unrecognised,
            sprintf(
                'the base prompt contains unrecognised capitalised word(s): %s. '
                . 'If one is a tool, it must be registered in Bootstrap::tools(); '
                . 'if it is ordinary prose, add it to self::PROSE_WORDS.',
                implode(', ', $unrecognised),
            ),
        );

        // And the prompt has to be talking about tools at all, or the check
        // above is satisfied by a prompt that names none.
        $this->assertNotEmpty(
            array_intersect($words, $real),
            'the tool-use guidance names no registered tool at all',
        );
    }

    /**
     * The specific tools the tool-use guidance is ABOUT have to be present in
     * the shipped set, or the guidance is advice about nothing. This is the
     * assertion that would have caught the draft's original mistake of telling
     * the model to use a permission-request path it has no tool for.
     */
    public function testEveryToolTheGuidanceRecommendsIsActuallyRegistered(): void
    {
        $real = array_map(static fn (Tool $tool): string => $tool->name(), Bootstrap::tools(sys_get_temp_dir()));
        $base = $this->basePrompt();

        foreach (['Grep', 'Glob', 'Read', 'Edit', 'Write', 'WebFetch', 'WebSearch'] as $name) {
            $this->assertStringContainsString($name, $base, "the guidance never mentions {$name}");
            $this->assertContains($name, $real, "{$name} is named in the prompt but not registered");
        }
    }

    /**
     * Three claims the prompt makes about Edit, asserted against the prompt so
     * a future reword cannot quietly drop them. Each is enforced in
     * `Edit::execute()` and separately proven behaviourally in
     * `tests/Tools/ToolDescriptionGuidanceTest.php`.
     */
    public function testToolUseGuidanceStatesEditsRealMatchContract(): void
    {
        $base = $this->basePrompt();

        $this->assertStringContainsString('byte for byte', $base);
        $this->assertStringContainsString('left untouched', $base);
        $this->assertStringContainsString('Edit cannot create a file', $base);
    }

    /**
     * The batching clause is only honest because {@see
     * Runtime::executeToolCalls()} really does segment a same-turn batch. The
     * constructor flag that enables it defaults to on, which is what makes the
     * instruction actionable rather than aspirational.
     *
     * BOTH of {@see Runtime::runsConcurrently()}'s conditions are covered here.
     * The earlier version of this test asserted only the flag, which left the
     * fork half unasserted — and unmentioned in the prompt, which promised
     * concurrency flatly while the code delivers it only where `pcntl_fork` and
     * `pcntl_waitpid` exist. A number or a claim must not travel without its
     * domain, and "concurrently" had lost half of its.
     */
    public function testBatchingGuidanceMatchesTheRuntimeDefault(): void
    {
        $base = $this->basePrompt();
        $this->assertStringContainsString('concurrently', $base);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('echo');

        $flag = new \ReflectionProperty(new Runtime($provider, new HookManager(new HookRegistry())), 'parallelToolCalls');
        $flag->setAccessible(true);

        $this->assertTrue(
            $flag->getValue(new Runtime($provider, new HookManager(new HookRegistry()))),
            'the prompt tells the model to batch independent calls, so batching must be on by default',
        );

        // The second condition. Graded differently from the first on purpose,
        // because the code grades them differently: the ORDERING guarantee holds
        // on every build (a barrier is a barrier with or without pcntl), the
        // CONCURRENCY does not. So the ordering clause may be flat and the
        // concurrency clause must be conditional and must name its condition.
        $concurrency = self::qualifierBefore($base, 'run concurrently')
            . 'run concurrently'
            . substr($base, (int) strpos($base, 'run concurrently') + strlen('run concurrently'), 120);

        $this->assertMatchesRegularExpression(
            '/\b(where|when|if|unless)\b/',
            $concurrency,
            'the concurrency promise is unconditional, but runsConcurrently() requires canFork()',
        );
        $this->assertMatchesRegularExpression(
            '/\bfork\b/',
            $concurrency,
            'the concurrency clause is qualified but does not name the condition (forking)',
        );
    }

    /**
     * `canFork()` really is what gates concurrency, proven by taking pcntl away.
     *
     * `function_exists()` cannot be made to lie in-process, so the negative
     * branch is driven in a CHILD php with `-d disable_functions=pcntl_fork`.
     * Without this the fork dependency is unfalsifiable on any host that has
     * ext-pcntl — which is every host that runs this suite — and the prompt's
     * new "where this build can fork" qualifier would rest on nothing.
     */
    public function testConcurrencyReallyDependsOnForkSupport(): void
    {
        $canFork = new \ReflectionMethod(Runtime::class, 'canFork');
        $canFork->setAccessible(true);

        // Positive branch, in-process: this host has pcntl, so it can fork.
        $this->assertTrue(function_exists('pcntl_fork'), 'this host has no pcntl to test with');
        $this->assertTrue((bool) $canFork->invoke(null));

        // Negative branch, in a child with pcntl_fork taken away.
        $autoload = dirname((new \ReflectionClass(Runtime::class))->getFileName(), 2) . '/vendor/autoload.php';
        $this->assertFileExists($autoload);

        $script = sprintf(
            'require %s; $m = new ReflectionMethod(%s::class, "canFork"); '
            . '$m->setAccessible(true); echo $m->invoke(null) ? "CAN" : "CANNOT";',
            var_export($autoload, true),
            '\\' . Runtime::class,
        );

        $output = [];
        $status = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' -d disable_functions=pcntl_fork -r ' . escapeshellarg($script) . ' 2>&1',
            $output,
            $status,
        );

        $this->assertSame(0, $status, 'the child probe failed: ' . implode("\n", $output));
        $this->assertSame(
            'CANNOT',
            trim(implode("\n", $output)),
            'canFork() no longer gates on pcntl, so the prompt\'s fork qualifier describes nothing',
        );
    }

    /**
     * The containment claim, driven. The prompt tells the model to prefer Grep
     * and Glob partly because "they are confined to the workspace root", so a
     * rooted Grep has to actually refuse a path outside that root.
     *
     * Two halves, and the second is the one that was missing: the fact is
     * proven by running the tool, and the prompt is then checked for a negator
     * in front of the claim. Presence alone had no power — flipping this exact
     * clause to "they are never confined" left the suite green.
     */
    public function testTheConfinementClaimHoldsAgainstARootedTool(): void
    {
        $root = sys_get_temp_dir() . '/crush_base_jail_' . uniqid('', true);
        mkdir($root);
        file_put_contents($root . '/inside.txt', "needle\n");

        try {
            $grep = new Grep($root);

            $inside = $grep->execute(['pattern' => 'needle', 'path' => $root, 'description' => 'probe']);
            $this->assertStringContainsString('inside.txt', $inside->content());

            $outside = $grep->execute(['pattern' => 'root', 'path' => '/etc', 'description' => 'probe']);
            $this->assertTrue($outside->isError(), 'a rooted Grep must refuse a path outside its root');
            $this->assertStringNotContainsString('/etc/', $outside->content());
        } finally {
            @unlink($root . '/inside.txt');
            @rmdir($root);
        }

        $this->assertPromptDoesNotNegate($this->basePrompt(), 'confined');
    }

    /**
     * The skip-annotation claim and its BOUND, both measured.
     *
     * `Grep::presentExcludedDirs()` globs `/`, `/*&#47;` and `/*&#47;*&#47;` only, so an
     * excluded directory nested deeper goes unannounced — its own docblock says
     * so. The prompt used to promise, flatly, that "an empty result is
     * distinguishable from a directory that was never walked", which is false
     * past that depth: at depth 4 the tree is skipped and nothing says a word.
     *
     * The depth is DERIVED here rather than written down: `vendor/` is planted
     * at successive depths and the deepest one Grep still announces is
     * measured, then the prompt's stated figure has to equal it. Writing "three"
     * as a literal in both places is how the pair drifts.
     */
    public function testTheSkipAnnotationClaimIsBoundedExactlyWhereTheCodeBoundsIt(): void
    {
        $deepestAnnounced = 0;
        $shallowestSilent = null;

        for ($depth = 1; $depth <= 5; $depth++) {
            $root = sys_get_temp_dir() . '/crush_base_depth_' . $depth . '_' . uniqid('', true);
            $nest = $root . str_repeat('/lvl', $depth - 1);
            mkdir($nest . '/vendor', 0777, true);
            file_put_contents($nest . '/vendor/hidden.php', "needle\n");
            file_put_contents($root . '/top.txt', "needle\n");

            $content = (new Grep($root))
                ->execute(['pattern' => 'needle', 'path' => $root, 'description' => 'probe'])
                ->content();
            exec('rm -rf ' . escapeshellarg($root));

            // The hit inside vendor is never returned at ANY depth — that half
            // is not the bug. Whether the model is TOLD is what varies.
            $this->assertStringNotContainsString('hidden.php', $content, "depth $depth");

            if (str_contains($content, 'skipped:')) {
                $deepestAnnounced = $depth;
            } elseif ($shallowestSilent === null) {
                $shallowestSilent = $depth;
            }
        }

        $this->assertSame(
            3,
            $deepestAnnounced,
            'the measured annotation depth changed; presentExcludedDirs() probes / , /*/ and /*/*/',
        );
        $this->assertSame(
            4,
            $shallowestSilent,
            'a skip should first go unannounced one level past the deepest probe',
        );

        // The prompt has to carry that same number, spelled however it likes.
        $base = $this->basePrompt();
        $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5];
        $this->assertSame(
            1,
            preg_match('/within (\w+) levels/', $base, $stated),
            'the prompt no longer states how deep the skip annotation reaches',
        );
        $this->assertSame(
            $deepestAnnounced,
            $words[strtolower($stated[1])] ?? (int) $stated[1],
            'the prompt states a different annotation depth than the one just measured',
        );
    }

    // -------------------------------------------------------------------------
    // Golden system-prompt pin - committed byte golden + host-path leak scan
    // -------------------------------------------------------------------------

    /**
     * Byte-golden pin for Runtime::buildSystemPrompt().
     *
     * Everything above this test asserts the base literal's structure and
     * truthfulness, deliberately never its wording. This test is the other
     * half of that bargain: the FULL ASSEMBLED prompt — the seven layers
     * buildSystemPrompt() concatenates, in order:
     *
     *   1. the base heredoc (the four guidance sections the tests above check)
     *   2. the <repo-map> block, when the root has anything to map
     *   3. the <project-instructions> documents (loadRoot(), then loadForced())
     *   4. the <project-memory> block, when the app has a memory store
     *   5. each enabled skill's systemPromptContribution()
     *   6. the SkillMatcher listing of every auto-invocable discovered skill
     *   7. the <env> block
     *
     * — is pinned byte-for-byte against a committed golden. A one-byte change
     * to any layer's prose, separators, ordering or git-section wording fails
     * this test, which is the regression class the structural tests above are
     * deliberately blind to.
     *
     * The golden is generated from the SAME fixture context this test builds
     * ({@see goldenContext()}): a deterministic fixture repo materialised at
     * test time under vendor/prompt-fixture/system-repo, a pinned clock, a
     * fixed model, an injected platform ('linux' — P2.S1 made the platform
     * injectable, so it is pinned in the golden rather than normalized), and
     * RELATIVE paths (cwd, repo root and memory store) so the committed golden
     * contains no host path. The two host-property lines render() reads from
     * the runtime — OS version and PHP version, which are php_uname()/
     * PHP_VERSION constants of the HOST and not injectable — are normalized
     * before the comparison ({@see pinHostLines()}), which keeps the golden
     * byte-stable across machines while still failing on any one-byte change
     * to anything the prompt builder actually controls.
     *
     * REGENERATION DISCIPLINE: regenerate the golden ONLY when the rendered
     * output legitimately changes (prose change, new prompt layer, env-field
     * or git-section wording). Regenerate with a recorded human-readable
     * reason in the commit message, and the regenerating step MUST diff
     * old-vs-new and paste the diff into the worklog. NEVER regenerate to
     * silence a failing test — a red here means the prompt changed under the
     * golden, and that change has to be argued, not smoothed over.
     */
    public function testSystemPromptMatchesCommittedGolden(): void
    {
        $repo = self::ensureFixtureRepo();
        self::assertDirectoryExists(
            $repo,
            'Fixture repo was not materialised - run phpunit from sugar-crush/ so the relative '
            . 'cwd vendor/prompt-fixture/system-repo resolves against the phpunit working directory.',
        );

        [$runtime, $app] = self::goldenContext();

        $build = new \ReflectionMethod($runtime, 'buildSystemPrompt');
        $build->setAccessible(true);

        self::assertSame(
            self::pinHostLines(self::readSystemPromptGolden()),
            self::pinHostLines((string) $build->invoke($runtime, $app)),
            'Runtime::buildSystemPrompt() drifted from the committed golden - see the regeneration discipline note.',
        );
    }

    /**
     * Host-path leak scan over the committed golden.
     *
     * THE ROO BUG CLASS: a production agent shipped a hardcoded '/test/path'
     * in its prompt prose because a fixture path leaked into a golden and no
     * test ever looked at the file again. An agent prompt must not carry its
     * generator's host paths. The fixture cwd, repo root and memory store are
     * deliberately RELATIVE (vendor/prompt-fixture/system-repo,
     * tests/fixtures/prompt/memory), so the golden contains no absolute path
     * at all; this test pins that absence deliberately — no line may start
     * with '/', and the literal host-path fragments below must not appear
     * anywhere in the file. The fixture author identity is scanned for too:
     * a golden that leaked 'Fixture Author' or the fixture email would be
     * leaking the test harness's own environment, the same class of leak one
     * step closer to home.
     */
    public function testGoldenSystemPromptLeaksNoHostPaths(): void
    {
        $golden = self::readSystemPromptGolden();

        self::assertStringNotContainsString('/tmp/', $golden, 'golden leaks a /tmp/ host path');
        self::assertStringNotContainsString('/home/', $golden, 'golden leaks a /home/ host path');
        self::assertStringNotContainsString('/Users/', $golden, 'golden leaks a macOS /Users/ host path');
        self::assertStringNotContainsString('C:\\Users\\', $golden, 'golden leaks a Windows host path');
        self::assertStringNotContainsString('/my/', $golden, 'golden leaks the author username as a path segment');
        self::assertStringNotContainsString('/test/', $golden, 'golden leaks a /test/ path fragment');
        self::assertStringNotContainsString('Joe Huss', $golden, 'golden leaks the author identity');
        self::assertStringNotContainsString('Fixture Author', $golden, 'golden leaks the fixture commit author identity');
        self::assertStringNotContainsString('fixture@example.invalid', $golden, 'golden leaks the fixture commit author email');
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
     * that reflects this method through BaseSystemPromptTest), so the
     * committed golden can never drift from what the test renders. The cwd,
     * the repo root and the memory-store path are deliberately RELATIVE so
     * the golden contains no host path; they resolve against the phpunit
     * working directory (sugar-crush/), exactly as AgentTest's golden fixture
     * documents.
     *
     * A real {@see EchoProvider} rather than a PHPUnit mock, so the same
     * reflection a /tmp regeneration script uses works outside a test
     * context. The provider is never called during buildSystemPrompt() — the
     * environment block is injected through the Runtime constructor — which is
     * also what pins the platform and the clock, leaving only the OS-version
     * and PHP-version lines host-derived ({@see pinHostLines()}).
     *
     * @return array{0: Runtime, 1: App}
     */
    private static function goldenContext(): array
    {
        $provider = new EchoProvider();

        $block = new EnvironmentBlock(
            'vendor/prompt-fixture/system-repo',
            'claude-sonnet-4-6',
            new DateTimeImmutable('2026-08-26 00:00:00'),
            'linux',
        );

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), $block);

        $skill = Skill::parse(
            "---\ndescription: Fixture skill for the golden prompt\n---\nWork only inside the fixture workspace.",
            'fixture-helper',
        );
        $registry = new SkillRegistry();
        $registry->register([$skill]);

        $app = App::new($provider, 'claude-sonnet-4-6')
            ->withRoot('vendor/prompt-fixture/system-repo')
            ->withInstructionLoader(new InstructionFileLoader('vendor/prompt-fixture/system-repo'))
            ->withMemoryStore(new MemoryStore('tests/fixtures/prompt/memory'))
            ->withEnabledSkills([$skill])
            ->withAvailableSkills($registry);

        return [$runtime, $app];
    }

    /**
     * Reads the committed golden, failing loudly when it is missing rather
     * than comparing against an empty string.
     *
     * Named readSystemPromptGolden() rather than readGolden() on purpose: the
     * drift census (tests/Support/DuplicatedTestHelperDriftTest) pairs private
     * helpers BY NAME across test files, and AgentTest already has a
     * readGolden() whose body is this one's except for the golden filename —
     * the one token that MUST differ. A same-name pair one token apart is the
     * census's exact subject, so the name is kept distinct and the deliberate
     * divergence never looks like a drifted copy.
     */
    private static function readSystemPromptGolden(): string
    {
        $goldenPath = __DIR__ . '/fixtures/prompt/golden-system-prompt.txt';
        $golden = file_get_contents($goldenPath);
        if ($golden === false) {
            self::fail('Golden file missing: ' . $goldenPath . ' - regenerate per the discipline note above.');
        }

        return $golden;
    }

    /**
     * Materialises the deterministic fixture repo the golden renders.
     *
     * Built under vendor/prompt-fixture/system-repo - gitignored via the root
     * vendor/ ignore rule, so the outer tree stays clean and the fixture
     * never shows up in `git status`. The committed tree under
     * tests/fixtures/prompt/tree is copied in (umask-proof chmod 0644 after
     * every write), then the repo is rebuilt from scratch whenever the .git
     * directory is missing, with every step pinned: host git config is
     * neutralized (GIT_CONFIG_GLOBAL/SYSTEM), the commit author/committer
     * dates are fixed (deterministic commit hash), and every file is chmod
     * 0644 AFTER writing so a mode change cannot leak `old mode`/`new mode`
     * lines into the pinned diffs.
     *
     * The final state exercises every git field the <env> block renders:
     * branch (main), a three-line --porcelain status (one staged add, one
     * unstaged edit, one untracked file), one recent commit, a staged diff
     * and an unstaged diff. The memory fixture is deliberately NOT inside
     * the repo (it lives at tests/fixtures/prompt/memory), so the porcelain
     * stays exactly those three lines.
     */
    private static function ensureFixtureRepo(): string
    {
        $repo = __DIR__ . '/../vendor/prompt-fixture/system-repo';

        if (is_dir($repo . '/.git')) {
            return $repo;
        }

        if (is_dir($repo)) {
            self::removeTree($repo);
        }

        self::copyTree(__DIR__ . '/fixtures/prompt/tree', $repo);

        self::gitRun($repo, ['init', '-q', '-b', 'main']);
        self::gitRun($repo, ['config', 'user.name', 'Fixture Author']);
        self::gitRun($repo, ['config', 'user.email', 'fixture@example.invalid']);
        self::gitRun($repo, ['config', 'core.abbrev', '7']);
        self::gitRun($repo, ['config', 'commit.gpgsign', 'false']);

        self::gitRun($repo, ['add', '.']);
        self::gitRun($repo, ['commit', '-m', 'fixture: initial import'], [
            'GIT_AUTHOR_NAME' => 'Fixture Author',
            'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid',
            'GIT_AUTHOR_DATE' => '2026-08-26T00:00:00+0000',
            'GIT_COMMITTER_NAME' => 'Fixture Author',
            'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid',
            'GIT_COMMITTER_DATE' => '2026-08-26T00:00:00+0000',
        ]);

        // Unstaged edit: src/Lib.php gains a line after the commit.
        self::writeFixtureFile(
            $repo . '/src/Lib.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture\\Lib;\n\nfinal class Lib\n{\n"
                . "    // touched after the initial import\n}\n",
        );
        // Staged add: docs/notes.md is added but not committed.
        mkdir($repo . '/docs', 0777, true);
        self::writeFixtureFile($repo . '/docs/notes.md', "# Notes\n");
        self::gitRun($repo, ['add', 'docs/notes.md']);
        // Untracked: scratch.txt is never added.
        self::writeFixtureFile($repo . '/scratch.txt', "scratch\n");

        return $repo;
    }

    /**
     * Recursively copies a fixture tree, pinning the mode of every file to
     * 0644 AFTER the copy so umask cannot leak a mode change into the
     * golden's diffs.
     */
    private static function copyTree(string $source, string $destination): void
    {
        mkdir($destination, 0777, true);

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source . '/' . $entry;
            $to = $destination . '/' . $entry;

            if (is_dir($from)) {
                self::copyTree($from, $to);
            } else {
                $contents = file_get_contents($from);
                self::writeFixtureFile($to, $contents === false ? '' : $contents);
            }
        }
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
     * Normalizes the two host-property lines render() reads from the runtime,
     * so the committed golden is byte-stable across machines.
     *
     * EnvironmentBlock's own docblock calls php_uname() and PHP_VERSION
     * "read AT RENDER TIME" — constants of the HOST, not behaviour of the
     * prompt builder — and EnvironmentBlockTest interpolates them dynamically
     * for the same reason. A golden that pinned the generator's kernel would
     * red on every other machine. Platform is deliberately NOT normalized:
     * P2.S1 made it injectable, {@see goldenContext()} pins it to 'linux', and
     * an un-normalized Platform line is what keeps the injectable seam honest
     * in the golden.
     */
    private static function pinHostLines(string $block): string
    {
        $block = preg_replace('/^OS version: .*$/m', 'OS version: <host>', $block);
        $block = preg_replace('/^PHP version: .*$/m', 'PHP version: <host>', $block);

        return $block;
    }
}
