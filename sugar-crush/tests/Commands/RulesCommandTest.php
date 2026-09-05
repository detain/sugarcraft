<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Rule;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * `/rules` — listing, toggling, and the two boundaries the command must not cross.
 *
 * Everything is driven as a real submitted draft through
 * `Chat::update(new KeyMsg(KeyType::Enter))`, the same discipline
 * `SlashDispatchTest` uses: the thing under test here is what the operator sees
 * and what the shared {@see RulesState} ends up holding after the dispatcher has
 * had its way, and neither is observable by calling `RulesCommand::execute()` on a
 * hand-built instance. (That routing is covered unedited by
 * {@see \SugarCraft\Crush\Tests\Commands\SlashDispatchTest}, which submits every
 * visible registry row — so this file is about behaviour, not reachability.)
 *
 * THE TWO BOUNDARIES, both pinned because both are easy to cross by accident while
 * "finishing" the feature:
 *   - a toggle is SESSION-ONLY: `config.json` is byte-identical afterwards and the
 *     persistence callback never fires;
 *   - a toggle reaches the USER tier only: a project file is the repository's voice
 *     and is not unlistable-into-nothing by a name typed at this prompt.
 *
 * Both polarities of every state change are asserted (off AND back on), because a
 * one-direction test passes against an implementation that can only switch a pack
 * off.
 */
final class RulesCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $home = '';

    private string $root = '';

    /** Packs written for the current test, keyed by directory label. */
    private string $rulesDir = '';

    private string $packsDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir() . '/sugarcrush_rulescmd_' . uniqid('', true);
        $this->home = $this->sandbox . '/home';
        $this->root = $this->sandbox . '/repo';
        $this->rulesDir = $this->home . '/.sugar-crush/rules';
        $this->packsDir = $this->home . '/.sugar-crush/rulebooks';
        mkdir($this->rulesDir, 0o700, true);
        mkdir($this->packsDir, 0o700, true);
        mkdir($this->root, 0o755, true);
        $this->useHomeSandbox($this->home, create: false);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->sandbox));

        parent::tearDown();
    }

    // -- the listing ----------------------------------------------------------

    public function testTheBareListingNamesEveryPackInBothUserDirectories(): void
    {
        $this->writePackFile($this->rulesDir . '/standing.md', "STANDING\n");
        $this->writePackFile($this->packsDir . '/terse.md', "BE TERSE\n");

        $text = $this->reply($this->submit('/rules'));

        self::assertStringContainsString('standing', $text);
        self::assertStringContainsString('terse', $text);
        // The directory column is the only fact that tells these two apart, and
        // the only clue to where the file to edit actually is.
        self::assertStringContainsString('rules', $text);
        self::assertStringContainsString('rulebooks', $text);
    }

    /**
     * A pack needs no frontmatter to be a pack, but `name:` must not become a
     * second, conflicting identity in the listing: the toggle handle is the
     * filename stem, so that is what the listing prints.
     */
    public function testTheListingPrintsTheToggleHandleRatherThanTheDisplayTitle(): void
    {
        file_put_contents($this->packsDir . '/terse.md', "---\nname: Terse Mode\n---\nBE TERSE\n");

        $text = $this->reply($this->submit('/rules'));

        self::assertStringContainsString('terse', $text, 'the handle is what /rules <name> accepts');
        self::assertStringNotContainsString('Terse Mode', $text, 'the title is not a second spelling of the name');
    }

    public function testTheListingShowsWhichWayEachPackIsSet(): void
    {
        $this->writePackFile($this->packsDir . '/live.md', "LIVE\n");
        file_put_contents($this->packsDir . '/shy.md', "---\nenabled: false\n---\nSHY\n");

        $text = $this->reply($this->submit('/rules'));

        self::assertStringContainsString('off (frontmatter)', $text, 'a self-disabled pack says so rather than looking broken');
        self::assertStringContainsString('on', $text);
    }

    /**
     * A pack the session switched off must still be LISTED — the listing is built
     * from the loader's per-directory methods rather than `load()`, and this is the
     * assertion that reddens if someone "simplifies" it to `load()`, which returns
     * enabled rules only and would make a command whose whole job is switching
     * things back on unable to see the ones that need it.
     */
    public function testAPackTheSessionSwitchedOffIsStillListedAndMarkedSessionOff(): void
    {
        $this->writePackFile($this->packsDir . '/terse.md', "BE TERSE\n");
        $state = RulesState::new(['terse']);

        $text = $this->reply($this->submit('/rules', $state));

        self::assertStringContainsString('terse', $text);
        self::assertStringContainsString('off (session)', $text, 'the listing distinguishes the two reasons a pack is off');
    }

    public function testAnEmptyHomeSaysWhereToPutAPackRatherThanShowingABareHeader(): void
    {
        $text = $this->reply($this->submit('/rules'));

        self::assertStringContainsString('No rule packs found', $text);
        self::assertStringContainsString('.sugar-crush/rulebooks/', $text, 'the answer names the directory to create');
    }

    // -- the toggle -----------------------------------------------------------

    /**
     * Both polarities, and the state object checked at each step rather than only
     * the prose: the report and the shared set must agree, since the next prompt is
     * built from the set and not from the sentence.
     */
    public function testTogglingReportsTheDirectionItWentAndMovesTheSharedState(): void
    {
        $this->writePackFile($this->packsDir . '/terse.md', "BE TERSE\n");
        $state = RulesState::new();

        $off = $this->submit('/rules terse', $state);
        self::assertStringContainsString('Pack terse: OFF for this session.', $this->reply($off));
        self::assertStringContainsString('out of the prompt', $this->reply($off));
        self::assertSame(['terse'], $state->disabled(), 'the Chat and the command share one set, by reference');

        $on = $this->submit('/rules terse', $state);
        self::assertStringContainsString('Pack terse: ON for this session.', $this->reply($on));
        self::assertStringContainsString('in the prompt', $this->reply($on));
        self::assertSame([], $state->disabled(), 'and toggling again restores it exactly');
    }

    /**
     * THE COLLISION DISCLOSURE, pinned at the COMMAND level: one name, two packs,
     * and a reply that says both moved.
     *
     * `~/.sugar-crush/rules/focus.md` and `~/.sugar-crush/rulebooks/focus.md` are
     * two packs by design and one toggle turns BOTH off — a decision already pinned
     * where the bytes are decided, at
     * {@see \SugarCraft\Crush\Tests\Context\RuleLoaderTest::testTheSameStemInBothUserDirectoriesStaysTwoPacksToggledByOneName()},
     * which this test leaves untouched. What the loader test cannot see is the
     * SENTENCE, and until now it read `Pack focus: OFF for this session.` in the
     * singular about an action that had just silenced two files. The listing
     * distinguishes them with a `Source` column; the toggle reply has no column to
     * put it in, so the fact goes in the sentence.
     *
     * THREE polarities are asserted — off, back on, and a name no collision touches.
     * The last one is the control that keeps the note from printing always: a
     * disclosure on every toggle would be noise the operator stops reading, which is
     * the same as no disclosure.
     */
    public function testTogglingANameTwoPacksShareDisclosesThatBothMoved(): void
    {
        $this->writePackFile($this->rulesDir . '/focus.md', "RULES-DIR FOCUS\n");
        $this->writePackFile($this->packsDir . '/focus.md', "RULEBOOKS-DIR FOCUS\n");

        $state = RulesState::new();
        $off = $this->submit('/rules focus', $state);

        self::assertSame(['focus'], $state->disabled(), 'one handle, one set entry, both packs out');
        self::assertStringContainsString('Pack focus: OFF for this session.', $this->reply($off));
        self::assertStringContainsString(
            'this name matches 2 packs — both toggled',
            $this->reply($off),
            'the sentence must not describe a two-file action in the singular',
        );

        // The note is about the NAME, not the direction, so the way back says it too.
        $on = $this->submit('/rules focus', $state);
        self::assertStringContainsString('Pack focus: ON for this session.', $this->reply($on));
        self::assertStringContainsString('this name matches 2 packs — both toggled', $this->reply($on));

        // The control: an unshared name reads exactly as it did before this note
        // existed, because `/rules x` with two rows is a different fact from
        // `/rules x` with one.
        $this->writePackFile($this->packsDir . '/terse.md', "SOLO PACK\n");
        $solo = $this->submit('/rules terse', $state);
        self::assertStringContainsString('Pack terse: OFF for this session.', $this->reply($solo));
        self::assertStringNotContainsString(
            'this name matches',
            $this->reply($solo),
            'a name one pack answers to must not acquire a collision note',
        );
    }

    /**
     * A pack disabled in its own frontmatter cannot be enabled by the session bit.
     *
     * The command must say which half of the conjunction is holding it, because
     * `Pack shy: ON for this session.` with nothing else would tell the operator
     * their prompt now carries text it demonstrably does not carry.
     */
    public function testAToggleCannotEnableAPackItsOwnFileDisabled(): void
    {
        file_put_contents($this->packsDir . '/shy.md', "---\nenabled: false\n---\nSHY\n");
        $state = RulesState::new(['shy']);

        $text = $this->reply($this->submit('/rules shy', $state));

        self::assertStringContainsString('enabled: false', $text, 'the message names the frontmatter as the reason');
        self::assertFalse($state->effectiveRule($this->loadedPack('shy'))->enabled, 'and the pack is still out');
    }

    // -- the refusal to persist ----------------------------------------------

    /**
     * THE SESSION-ONLY CONTRACT, as a fact about bytes rather than about intent.
     *
     * Requirement 5's second branch: nothing a `/rules` toggle does may reach disk.
     * `config.json` is the file a toggle would plausibly be written to, so it is
     * seeded with content, put through a real toggle, and compared byte for byte.
     *
     * The comparison is not alone: the same test asserts the toggle actually took
     * effect (`$state` moved and the transcript says OFF). Without that, this would
     * pass against a command that does nothing at all — a green that proves nothing
     * is precisely the kind of test §16.8 warns about.
     *
     * And the callback: `Chat::withOnConfigChange()` is the ONE door through which a
     * chat choice becomes durable, so a counter on it catches a persistence call
     * aimed at some other file, which the byte comparison alone would miss.
     */
    public function testTogglingAPackLeavesTheConfigFileByteIdentical(): void
    {
        $config = $this->home . '/.sugar-crush/config.json';
        $seed = "{\n    \"theme\": \"tokyo-night\",\n    \"model\": \"gpt-4\"\n}\n";
        file_put_contents($config, $seed);
        $before = hash_file('sha256', $config);

        $this->writePackFile($this->packsDir . '/terse.md', "BE TERSE\n");

        $persisted = 0;
        $state = RulesState::new();
        $chat = $this->chat('/rules terse', $state)->withOnConfigChange(
            static function () use (&$persisted): void {
                $persisted++;
            },
        );
        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        self::assertStringContainsString('OFF for this session', $this->reply($next), 'the toggle happened');
        self::assertSame(['terse'], $state->disabled(), 'and reached the shared state');
        self::assertSame($before, hash_file('sha256', $config), 'config.json is byte-for-byte what it was');
        self::assertSame($seed, file_get_contents($config), 'stated as the exact bytes, not only a hash');
        self::assertSame(0, $persisted, 'and onConfigChange was never invoked - the door to disk stayed shut');
    }

    // -- the refusal to reach the repository tiers ----------------------------

    /**
     * A project-tier file named like a pack is not a pack.
     *
     * `/rules` lists and toggles the USER tier only. The name is therefore unknown
     * here, and unknown is an error, not a no-op: an operator who typed `/rules
     * terse` expecting to silence a repository's rules must be told that is not what
     * this command does, rather than believing they did it.
     */
    public function testAProjectRuleIsNotToggleableAndSaysSoAsAnError(): void
    {
        mkdir($this->root . '/.sugar-crush/rules', 0o755, true);
        $this->writePackFile($this->root . '/.sugar-crush/rules/terse.md', "PROJECT BE TERSE\n");

        $state = RulesState::new();
        $next = $this->submit('/rules terse', $state);

        self::assertSame(Role::System, $this->last($next)->role, 'the unknown handle is a system notice');
        self::assertStringContainsString('Unknown rule pack: terse', $this->last($next)->content);
        self::assertSame([], $state->disabled(), 'and nothing was toggled');
    }

    // -- the error paths ------------------------------------------------------

    public function testAnUnknownPackNameIsAnErrorResponseNotASilentNoOp(): void
    {
        $this->writePackFile($this->packsDir . '/terse.md', "BE TERSE\n");
        $state = RulesState::new();

        $next = $this->submit('/rules typo', $state);

        self::assertSame(Role::System, $this->last($next)->role, 'a failure must not be filed as an assistant reply');
        self::assertStringContainsString('Unknown rule pack: typo', $this->last($next)->content);
        self::assertStringContainsString('terse', $this->last($next)->content, 'and the answer names what WAS available');
        self::assertSame([], $state->disabled(), 'a typo must not toggle anything');
        self::assertFalse($next->inFlight, 'the failure is still handled by the command, not sent to the model');
    }

    /**
     * The unknown-name refusal must not carry an escape sequence into the transcript.
     *
     * THE HOLE THIS CLOSES. `TranscriptTable::cell()` neutralises ANSI because
     * {@see \SugarCraft\Core\Util\Width::truncate()} opens with `Ansi::strip($s)` — that
     * is pinned at {@see \SugarCraft\Crush\Tests\Commands\TranscriptTableTest}, and is
     * why the LISTING needs no defence. But these two error lines are composed by hand
     * outside any cell, so the bytes the operator typed are interpolated verbatim and
     * an escape reaches the transcript intact, where the TUI styles its own text and a
     * stray ESC resurfaces as literal bracket-31m prose. This is the same defect class
     * {@see \SugarCraft\Crush\Tests\Commands\NoRawAnsiInTranscriptTest} exists for; that
     * guard reads SOURCE for a literal escape and so cannot see an escape acquired at
     * RUNTIME from an argument — which is exactly why the pin lives here instead.
     *
     * Asserted on the exact neutralised message rather than on the absence of an ESC:
     * a strip so aggressive it deleted the name too would pass the weaker check, and the
     * name is the one part of an error the operator still has to be able to read.
     */
    public function testAnEscapeSequenceInTheTypedNameIsStrippedFromTheUnknownPackError(): void
    {
        $this->writePackFile($this->packsDir . '/terse.md', "BE TERSE\n");
        $state = RulesState::new();

        $text = $this->reply($this->submit("/rules \033[31mghost\033[0m", $state));

        self::assertSame("Unknown rule pack: ghost\n  The only pack here is: terse", $text);
        self::assertStringNotContainsString("\033", $text, 'no raw escape byte may reach the transcript');
        self::assertSame([], $state->disabled(), 'and the stripped name must not match a pack');
    }

    /**
     * The same defence on the other composition site: the AVAILABLE-PACKS list, whose
     * entries are filename stems read off the operator's disk rather than text typed at
     * this prompt. A pack called `"\033[31mred\033[0m.md"` is legal to the filesystem, so
     * the stem arrives here already carrying the escape and the listing inherits it.
     *
     * TWO packs are written so this exercises the `Available packs:` branch — the one
     * with the comma-separated list — rather than the single-pack sentence the test above
     * already pins. The plain pack is the control: stripping the escaped name must not
     * disturb the neighbour that never needed it, so the expected string asserts both
     * spellings at once.
     */
    public function testAnEscapeSequenceInAPackFilenameStemIsStrippedFromThePacksList(): void
    {
        $this->writePackFile($this->rulesDir . "/\033[31mred\033[0m.md", "ESCAPED PACK\n");
        $this->writePackFile($this->packsDir . '/plain.md', "PLAIN PACK\n");
        $state = RulesState::new();

        $text = $this->reply($this->submit('/rules typo', $state));

        // `rules/` is walked before `rulebooks/`, which is the order packs() emits and
        // therefore the order the list prints.
        self::assertSame("Unknown rule pack: typo\n  Available packs: red, plain", $text);
        self::assertStringNotContainsString("\033", $text, 'a stem from disk is not trusted input either');
        self::assertSame([], $state->disabled());
    }

    /**
     * `/rules a b` is one name plus a stray token. Both readings are refused here:
     * the name does not exist, so it is an unknown-pack error, and a name that DOES
     * exist with a trailing token is refused by the arity check rather than quietly
     * toggling the first word.
     */
    public function testATrailingTokenIsRefusedRatherThanHalfApplied(): void
    {
        $this->writePackFile($this->packsDir . '/terse.md', "BE TERSE\n");

        $state = RulesState::new();
        $stray = $this->submit('/rules terse extra', $state);
        self::assertSame(Role::System, $this->last($stray)->role);
        self::assertStringContainsString('one pack name', $this->last($stray)->content);
        self::assertSame([], $state->disabled(), 'nothing was toggled by the rejected form');

        // The empty-string spelling: `/rules ` with whitespace after the name must
        // list, not look up a pack called "".
        $listed = $this->submit('/rules ', RulesState::new());
        self::assertStringContainsString('Rule packs', $this->reply($listed));
    }


    // -- the carry chain: Chat's set reaches the bytes the model gets ---------

    /**
     * The whole point of routing the set through {@see App} rather than reading it
     * off the Chat: the prompt is assembled inside {@see EngineBackend::complete()},
     * one object graph away from the command that toggled it.
     *
     * This drives a REAL `complete()` against a provider that records the
     * {@see CompleteRequest} it was handed, so the assertion is on the system prompt
     * exactly as the model would receive it — not on an accessor someone hoped the
     * carry had populated. Removing the `withRulesState()` stamp from the per-turn
     * copy reddens it with the disabled pack still present in the bytes, which is
     * the failure this step could otherwise ship silently: the command would report
     * OFF, the listing would say off, and every prompt would still carry the pack.
     *
     * The pair is asserted together (one pack on, one off, one turn) so the test
     * cannot pass by filtering everything or nothing.
     */
    public function testTheToggledSetReachesTheSystemPromptThroughAPerTurnCarry(): void
    {
        $this->writePackFile($this->packsDir . '/kept.md', "PACK-STAYING-ON\n");
        $this->writePackFile($this->packsDir . '/dropped.md', "PACK-TOGGLED-OFF\n");

        $state = RulesState::new();
        $state->toggle('dropped');

        $provider = $this->recordingProvider();
        (new EngineBackend($provider, 'stub-rules'))
            ->withRulesState($state)
            ->withRoot($this->root)
            ->complete([Message::user('hello')]);

        self::assertCount(1, $provider->requests, 'the stub answers in one step');
        $prompt = (string) $provider->requests[0]->systemPrompt;

        self::assertStringContainsString('PACK-STAYING-ON', $prompt, 'the pack left alone is in the prompt');
        self::assertStringNotContainsString(
            'PACK-TOGGLED-OFF',
            $prompt,
            'and the toggled pack is NOT — so the set survived the trip from Chat through App to the splice',
        );
    }

    /**
     * A backend with NO set carries null, and a null set must leave the prompt
     * exactly as the loader alone decides — the carry may not turn a toggle-less
     * session into an empty-rules session.
     */
    public function testABackendWithoutARulesStateStillCarriesEveryPackIntoThePrompt(): void
    {
        $this->writePackFile($this->packsDir . '/kept.md', "PACK-STAYING-ON\n");

        $provider = $this->recordingProvider();
        (new EngineBackend($provider, 'stub-rules'))->withRoot($this->root)->complete([Message::user('hello')]);

        self::assertStringContainsString('PACK-STAYING-ON', (string) $provider->requests[0]->systemPrompt);
    }

    /**
     * `App::withRulesState()` must round-trip the SAME object rather than a copy:
     * the whole session-scoped design rests on Chat, the backend and the prompt
     * reading one set, and a mutate() that copied it would silently freeze the
     * toggle at launch.
     */
    public function testTheAppCarriesTheOneSetRatherThanACopyOfIt(): void
    {
        $state = RulesState::new();
        $app = App::new($this->provider(), 'gpt-4')->withRulesState($state);

        self::assertSame($state, $app->rulesState, 'the App holds the caller\'s object by identity');

        $state->toggle('later');
        self::assertSame(['later'], $app->rulesState->disabled(), 'and a toggle after the hand-off is visible through it');

        self::assertNull(App::new($this->provider(), 'gpt-4')->rulesState, 'an App nobody gave one to stays null');
    }

    // -- helpers --------------------------------------------------------------

    private function provider(): ProviderInterface
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');

        return $provider;
    }

    /**
     * A provider that records the request it was handed, so the system prompt can be
     * asserted on as the model receives it.
     *
     * Non-streaming deliberately: `Runtime::run()` only takes `runBatch()`, which
     * hands back one deterministic response per step, when
     * {@see ProviderInterface::supportsStreaming()} is false.
     */
    private function recordingProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            public function name(): string
            {
                return 'stub-rules';
            }

            public function supportsStreaming(): bool
            {
                return false;
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
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->requests[] = $request;

                return new CompleteResponse(content: 'answered');
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

    private function chat(string $draft, ?RulesState $state = null): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
            projectRoot: $this->root,
            rulesState: $state,
        ))->withSize(100, 30);
    }

    /** Submit $draft as a real Enter keystroke and hand back the resulting Chat. */
    private function submit(string $draft, ?RulesState $state = null): Chat
    {
        [$next] = $this->chat($draft, $state)->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    /** The message a command handler appended last (the two seed messages are skipped by index). */
    private function last(Chat $chat): Message
    {
        return $chat->history[count($chat->history) - 1];
    }

    /** The text a successful command produced, as the transcript holds it. */
    private function reply(Chat $chat): string
    {
        return $this->last($chat)->content;
    }

    private function writePackFile(string $path, string $body): void
    {
        file_put_contents($path, $body);
    }

    /**
     * One loaded pack by key, read through the same user-tier entry points the
     * command uses, so a test asserting an EFFECTIVE state is asserting against the
     * same object the prompt would see.
     */
    private function loadedPack(string $key): Rule
    {
        foreach ((new RuleLoader($this->root))->loadUserRulebooks() as $rule) {
            if ($rule->key === $key) {
                return $rule;
            }
        }

        self::fail("no pack named {$key} in the rulebooks directory");
    }
}
