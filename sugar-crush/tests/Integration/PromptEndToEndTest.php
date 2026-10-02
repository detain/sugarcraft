<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\PromptGuidance;

/**
 * P11.S4 — the only end-to-end proof that the production-assembled BLOCK arm
 * of the system prompt reaches the provider wire from a real keystroke turn.
 *
 * WHAT THIS IS NOT: another record-then-assert test. The provider-level block
 * tests (e.g. `tests/Providers/SystemPromptTransmissionMatrixTest.php`) build
 * `new CompleteRequest(...)` BY HAND with synthetic blocks, which proves the
 * transmitters honour the shape but says nothing about whether production ever
 * FILLS it. And the keystroke test next door,
 * {@see SystemPromptWiringTest::testARealChatKeystrokeTurnDeliversBothHalves()},
 * crosses the same fork with a provider that echoes only the FLAT string — so
 * until this class existed, no test had ever asserted `systemBlocks` on a
 * request that crossed `EngineBackend::completeAsync()` end to end.
 *
 * WHY THE ECHO ENVELOPE AND NOT A CAPTURE (measured at base 8e822c6ed):
 * `completeAsync()` runs the turn inside a `pcntl_fork()` child whenever the
 * extension is present (this box: `function_exists('pcntl_fork')` is true), and
 * a provider that merely records requests records them in the child, whose
 * memory dies with the child. PROBE MEASUREMENT: an anonymous capturing
 * provider driven through one full `Chat::update(Enter)` turn returned an empty
 * `requests` array in the parent after the turn completed with its canned
 * answer — zero observations of the wire. The provider here instead serialises
 * the real request facts (hasBlocks flag, flat prompt, base64 block list) into
 * the response CONTENT, because the returned Message is the only channel that
 * crosses the boundary back to the parent (length-prefixed `serialize()`
 * frames; base64 keeps the block structure byte-exact inside JSON). On a box
 * without pcntl, `completeAsyncBlocking()` runs the same chain in-parent and
 * the envelope still round-trips — the chosen shape observes the wire either way.
 *
 * THE PLAN'S PREMISE, STATED PLAINLY (§P11.S4 "Done when"): this test would
 * have FAILED on the pre-Phase-11 tree, and it fails for a structural reason,
 * not an incidental one. The block arm itself only arrived with P10.S1:
 * `Runtime::run()` gained the two-arm fold
 * `[$systemPrompt, $systemBlocks] = self::assembleSections(...)` at that step,
 * and before it `CompleteRequest::$systemBlocks` was always null — assertion
 * (a) below is the one that goes red first. Every LAYER the needle set (d)
 * checks pre-dates Phase 11: env-last is P3.S1, maxims are P5.S5, the rules
 * fences are P6.S2, the skill-body splice and listing exclusion are P7.S3, the
 * tool-guidance seam is P9.S1, the block arm is P10.S1. So the layers are old
 * news; what is new is that they are asserted to reach the wire as STRUCTURE —
 * ordered blocks with the fold identity intact — out of a keystroke, which is
 * exactly the guarantee P10.S1 was for.
 *
 * Layer count of record: the plan text says "seven layers"; the section list at
 * this base carries eleven slots (base, maxims, tool-guidance-if-non-empty,
 * repo-map, user-tier rules, instruction docs, project-tier rules, memory,
 * enabled-skill bodies, skill listing, env). This sandbox makes every slot
 * non-empty except the project-tier rules one, and `assembleSections()` folds
 * empty renders out of BOTH arms — so the assertions here are presence-,
 * identity- and order-based, never cardinality-based, and hold whichever way a
 * run's cwd happens to flip that one tier.
 */
final class PromptEndToEndTest extends TestCase
{
    use HomeSandboxTrait;

    private const SKILL_NAME = 'e2e-block-skill';

    private const USER_RULE_MARKER = 'E2E USER RULE MARKER';

    private const INSTRUCTIONS_MARKER = 'E2E INSTRUCTIONS MARKER';

    private const MEMORY_MARKER = 'E2E MEMORY MARKER';

    /** Base heredoc's first sentence — the bytes that must open block 0. */
    private const BASE_OPENING = 'You are SugarCrush, an AI coding assistant working inside a terminal.';

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_e2e_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        // Both HOME spellings via the shared trait: the user-tier rules read
        // HomeDirectory::owned(), and a leak of the developer's own
        // ~/.sugar-crush would both fake a needle and slow the walk.
        $this->useHomeSandbox($this->tempDir . '/empty-home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->wipeBlockArmTree($this->tempDir);

        parent::tearDown();
    }

    /**
     * One real keystroke turn -> assertions (a)-(d) on the captured wire payload.
     *
     * NEEDLE -> PRE-PHASE-11 FAILURE MAP (brief §4; each of these was red on
     * the tree the plan started from):
     *  (a) hasBlocks — P10.S1 is when `systemBlocks` stopped being permanently
     *      null; before it this assert fails outright and (b)-(d) cannot even
     *      be expressed (there is no block list to index or implode).
     *  (b) implode('', blocks) === systemPrompt — the assembleSections()
     *      one-fold identity, P10.S1; meaningless pre-fold.
     *  (c) first block opens the base heredoc + last block ends "</env>" —
     *      pre-P3.S1 <env> was NOT last, and with no block arm there was no
     *      "first block"/"last block" at all.
     *  (d) maxims ('## Maxims', P5.S5), tool-guidance fragments (P9.S1 seam),
     *      fenced repo-map, <user-rules> + seeded marker (P6.S2),
     *      <project-instructions> + seeded marker (instruction-docs layer),
     *      <project-memory> + seeded marker, exact skill splice bytes (P7.S3),
     *      skill listing header, env liveness via the model name — every layer
     *      needle below names a phase that post-dates the plan's Phase-1 tree.
     *  ordering — listing block strictly before the last (env) block: the
     *      P3.S1 invariant re-pinned on the block arm with layers live.
     *  exclusion — '- [project] e2e-block-skill:' absent: the P7.S3 double-presentation
     *      polarity, asserted on fork-surviving bytes for the first time.
     */
    public function testARealKeystrokeTurnDeliversEveryLayerOnTheBlockArm(): void
    {
        $this->seedBlockArmSandbox();

        $provider = $this->blockArmEchoProvider();
        $chat = new Chat(backend: $this->blockArmBackend($provider));

        $resolved = $this->runBlockArmKeystrokeTurn($chat);
        $envelope = $this->decodeBlockArmEnvelope($resolved->message->content);

        // (a) the block arm exists at all — the first red pre-P10.S1.
        $this->assertTrue(
            $envelope['hasBlocks'],
            'CompleteRequest::$systemBlocks must reach the wire non-null from a real keystroke turn (P10.S1)',
        );
        $blocks = $envelope['blocks'];
        $this->assertNotEmpty($blocks, 'a non-null block arm must carry at least the base and env blocks');

        // (b) the one-fold invariant, byte-exact.
        $this->assertSame(
            $envelope['prompt'],
            implode('', $blocks),
            'implode("", $systemBlocks) must equal $systemPrompt byte-for-byte — the assembleSections() fold identity (P10.S1)',
        );

        // (c) block structure, both ends: base opens, env closes.
        $this->assertStringStartsWith(
            self::BASE_OPENING,
            $blocks[0],
            'the FIRST block must open with the base-prompt heredoc bytes — no layer may precede it',
        );
        $this->assertStringEndsWith(
            "\n</env>",
            $blocks[array_key_last($blocks)],
            'the LAST block must close the env fence — the P3.S1 env-last invariant on the block arm',
        );

        // (d) every layer the sandbox can light, present across the blocks.
        // The skill-body needle is the shipped `systemPromptContribution()`
        // itself, not a hand-copied string: the loader trims the SKILL.md
        // body's trailing newline, and the neighbour class only sees a
        // trailing "\n" because it greps the FLAT prompt where the next
        // block's separator supplies one. Per-block containment must read
        // the same bytes Runtime splices.
        $spliced = Skill::fromFile($this->makeBlockArmSkillRegistry()->get(self::SKILL_NAME)->sourcePath);
        $needles = [
            'maxims (P5.S5)' => '## Maxims',
            'repo-map fence' => '<repo-map>',
            'user-rules fence (P6.S2)' => '<user-rules>',
            'user-rules body' => self::USER_RULE_MARKER,
            'project-instructions fence' => '<project-instructions>',
            'project-instructions body' => self::INSTRUCTIONS_MARKER,
            'project-memory fence' => '<project-memory>',
            'project-memory body' => self::MEMORY_MARKER,
            'skill body splice (P7.S3)' => $spliced->systemPromptContribution(),
            'skill listing header' => 'Available skills (invoke via Skill tool):',
            'env is the live app' => 'Model: e2e-block-echo',
        ];
        foreach ($needles as $label => $needle) {
            $this->assertNotNull(
                $this->firstBlockHolding($blocks, $needle),
                'layer needle missing from the block arm across the fork: ' . $label,
            );
        }

        // Tool-guidance (P9 seam) — derived from the shipped tools, never a
        // hand-copied fragment, so a guidance edit moves the test with it.
        $guidedFragments = $this->promptGuidedFragments();
        $this->assertNotEmpty(
            $guidedFragments,
            'the mirrored production tool list must include at least one PromptGuidance tool or this pin is vacuous',
        );
        foreach ($guidedFragments as $fragment) {
            $this->assertNotNull(
                $this->firstBlockHolding($blocks, $fragment),
                'a wired tool guidance fragment failed to reach the block arm verbatim',
            );
        }

        // Ordering: the listing must sit BEFORE the env block — env last is
        // P3.S1, and this pins it with the skill layers actually non-empty.
        $listingBlock = $this->firstBlockHolding($blocks, 'Available skills (invoke via Skill tool):');
        $this->assertLessThan(
            array_key_last($blocks),
            $listingBlock,
            'the skill listing layer must precede <env> in block order (P3.S1)',
        );

        // Exclusion polarity (P7.S3): the enabled body must not double-list.
        $this->assertSame(
            0,
            substr_count($envelope['prompt'], '- [project] ' . self::SKILL_NAME . ':'),
            'an enabled skill body must not also appear as a level-1 listing line, even across the fork',
        );
    }

    /**
     * Seed one fixture state per layer this class asserts: a user-tier rules
     * pack (HOME sandbox), a root AGENTS.md for the instruction-docs layer, a
     * project SKILL.md, and a project-scoped memory entry.
     *
     * WHY IT IS ITS OWN METHOD: the backend and the test both need this state
     * to exist before they can resolve skills and read memory, and mirroring
     * `SystemPromptWiringTest`'s per-test seeding would have duplicated the
     * four writes across every future test added to this class.
     */
    private function seedBlockArmSandbox(): void
    {
        mkdir($this->homeDir() . '/.sugar-crush/rules', 0755, true);
        file_put_contents(
            $this->homeDir() . '/.sugar-crush/rules/e2e-rule.md',
            self::USER_RULE_MARKER . "\n",
        );

        file_put_contents($this->tempDir . '/AGENTS.md', self::INSTRUCTIONS_MARKER);

        $skillDir = $this->tempDir . '/.sugar-crush/skills/' . self::SKILL_NAME;
        mkdir($skillDir, 0755, true);
        file_put_contents(
            $skillDir . '/SKILL.md',
            "---\ndescription: Block-arm e2e skill.\nuser-invocable: true\ndisable-model-invocation: false\n---\n# "
            . self::SKILL_NAME . "\n\nBody.\n",
        );

        mkdir($this->tempDir . '/memory', 0755, true);
        $this->blockArmMemoryStore()->add(self::MEMORY_MARKER, MemoryScope::Project);
    }

    /**
     * The sandbox HOME this class owns, as one spelling so neither the rules
     * seed nor the teardown cleanup guesses it twice.
     */
    private function homeDir(): string
    {
        return $this->tempDir . '/empty-home';
    }

    /**
     * The store behind the <project-memory> layer — path is fixture-owned so
     * the entry can never leak into a developer's real memory tree.
     */
    private function blockArmMemoryStore(): MemoryStore
    {
        return new MemoryStore($this->tempDir . '/memory');
    }

    /**
     * Answers with a JSON envelope describing the real request it was handed.
     *
     * WHY base64: block bytes are arbitrary text and envelope JSON must stay
     * trivially decodable on the parent side; base64 is the smallest encoding
     * that keeps `implode('', blocks) === prompt` checkable byte-exactly after
     * the round trip. WHY the flat prompt too: assertion (b) is an equality
     * BETWEEN the two arms, so the envelope has to carry both.
     */
    private function blockArmEchoProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function name(): string
            {
                return 'e2e-block-echo';
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
                return new CompleteResponse(content: json_encode([
                    'hasBlocks' => $request->systemBlocks !== null,
                    'prompt' => (string) $request->systemPrompt,
                    'blocks' => array_map(
                        static fn (string $block): string => base64_encode($block),
                        $request->systemBlocks ?? [],
                    ),
                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
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
     * The backend exactly as {@see SystemPromptWiringTest}'s own `backend()`
     * mirrors `Bootstrap::backend()` — shared instruction loader threaded into
     * both engine and tools — plus the registry/skills/memory the block-arm
     * needles need, which the prompt-wiring file sets per-test through the
     * same public setters.
     *
     * WHY NOT REUSE THAT HELPER: the ceiling of this step is two files, and its
     * `backend()` is private there; duplicating the three-line shape is the
     * sanctioned mirror (the drift guard keys on identical helper NAMES, and
     * this one is deliberately unique).
     */
    private function blockArmBackend(ProviderInterface $provider): EngineBackend
    {
        $loader = Bootstrap::instructionLoader($this->tempDir);
        $registry = $this->makeBlockArmSkillRegistry();
        $manifest = $registry->get(self::SKILL_NAME);
        $this->assertInstanceOf(Skill::class, $manifest, 'the seeded project skill must resolve');

        return (new EngineBackend($provider, $provider->name()))
            ->withTools(Bootstrap::tools($this->tempDir, $loader))
            ->withInstructionLoader($loader)
            ->withSkillRegistry($registry)
            ->withSkills([Skill::fromFile($manifest->sourcePath)])
            ->withMemoryStore($this->blockArmMemoryStore());
    }

    /**
     * Discover the seeded project SKILL.md the way `Bootstrap::skillRegistry()`
     * does (that method is private; the SkillManager pair it uses is
     * constructed here — the same mirroring the neighbour class documents).
     */
    private function makeBlockArmSkillRegistry(): SkillRegistry
    {
        $registry = new SkillRegistry();
        (new SkillManager(new SkillLoader(), $registry))->loadAll($this->tempDir);

        return $registry;
    }

    /**
     * Drive the keystroke turn with the suite's ONE-TIMER law: no timer is
     * armed unless the promise has not already resolved, and the single safety
     * timer is cancelled immediately after the loop returns — the same shape
     * {@see SystemPromptWiringTest::testARealChatKeystrokeTurnDeliversBothHalves()}
     * pins, so nothing armed survives this test on the shared loop.
     */
    private function runBlockArmKeystrokeTurn(Chat $chat): AssistantMsg
    {
        $withInput = new \ReflectionMethod($chat, 'withInputBuf');
        $withInput->setAccessible(true);
        $chat = $withInput->invoke($chat, 'what is in your system prompt?');

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd, 'Enter must schedule the submit Cmd');

        $asyncCmd = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\AsyncCmd::class, $asyncCmd);

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertInstanceOf(
            AssistantMsg::class,
            $resolved,
            'the completion did not finish within the test timeout',
        );

        return $resolved;
    }

    /**
     * Parse the envelope back into trusted parts or fail loudly, so every
     * assertion downstream reads real types (parse-don't-validate at the
     * parent edge of the fork channel).
     *
     * @return array{hasBlocks: bool, prompt: string, blocks: list<string>}
     */
    private function decodeBlockArmEnvelope(string $answer): array
    {
        $decoded = json_decode($answer, true);
        $this->assertIsArray(
            $decoded,
            'the assistant content did not round-trip as the JSON envelope — the fork channel or the provider is broken',
        );
        foreach (['hasBlocks', 'prompt', 'blocks'] as $key) {
            $this->assertArrayHasKey($key, $decoded, 'the envelope lost a required key: ' . $key);
        }
        $this->assertIsBool($decoded['hasBlocks']);
        $this->assertIsString($decoded['prompt']);
        $this->assertIsArray($decoded['blocks']);

        $blocks = [];
        foreach ($decoded['blocks'] as $block) {
            $this->assertIsString($block);
            $raw = base64_decode($block, true);
            $this->assertNotFalse($raw, 'a block did not survive the base64 leg of the envelope');
            $blocks[] = $raw;
        }

        return ['hasBlocks' => $decoded['hasBlocks'], 'prompt' => $decoded['prompt'], 'blocks' => $blocks];
    }

    /**
     * The index of the first block containing $needle, or null when no block
     * carries it — null is the red signal, and the caller's message names the
     * layer, so a failure points straight at the phase that owns it.
     *
     * @param list<string> $blocks
     */
    private function firstBlockHolding(array $blocks, string $needle): ?int
    {
        foreach ($blocks as $index => $block) {
            if (str_contains($block, $needle)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The verbatim guidance fragments of every PromptGuidance tool the
     * production Bootstrap wires at this root — derived from the shipped code
     * so the (d)-set never hand-copies a fragment that later drifts.
     *
     * @return list<string>
     */
    private function promptGuidedFragments(): array
    {
        $loader = Bootstrap::instructionLoader($this->tempDir);

        $fragments = [];
        foreach (Bootstrap::tools($this->tempDir, $loader) as $tool) {
            if (!$tool instanceof PromptGuidance) {
                continue;
            }
            $fragment = $tool->promptGuidance();
            if ($fragment === '') {
                continue;
            }
            $fragments[] = $fragment;
        }

        return $fragments;
    }

    /**
     * Recursive temp-tree cleanup — named uniquely and kept parameter-tainted
     * to sys_get_temp_dir() so the tree-walk census never mistakes it for a
     * package-root walk (the neighbour file's identically-scoped `removeDirectory`
     * is the precedent for that resolution).
     */
    private function wipeBlockArmTree(string $dir): void
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
                $this->wipeBlockArmTree($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
