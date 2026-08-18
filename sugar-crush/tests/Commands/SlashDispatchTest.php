<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * `Chat::submit()`'s slash-command dispatch (crush_code.md Phase 4 items 1, 2
 * and 7): which draft reaches which handler, and — the part no test used to
 * cover — whether every command the "/" popup ADVERTISES reaches one at all.
 *
 * Everything here is driven as a real submitted draft through
 * `Chat::update(new KeyMsg(KeyType::Enter))`, never by calling a private
 * handler: the thing under test is the routing, and a test that calls the
 * handler directly cannot see a routing bug.
 */
final class SlashDispatchTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-slash-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    private function chat(string $draft = '', int $cols = 100): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
        ))->withSize($cols, 30);
    }

    /** Submit $draft as it stands and hand back the resulting Chat. */
    private function submit(string $draft, ?Chat $chat = null): Chat
    {
        [$next] = ($chat ?? $this->chat($draft))->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    /** The messages $draft appended to the two-message fixture history. */
    private function added(Chat $next): array
    {
        return array_slice($next->history, 2);
    }

    // ── the inventory: a visible row with no handler is a defect ──────────

    /**
     * Every `slashVisible` row in {@see CommandRegistry} must reach a dispatch
     * handler — asserted by BEHAVIOUR, not by reading a table.
     *
     * `$inFlight` is the signal, and it is the whole design of this test. A
     * draft `Chat::submit()` finds no command in falls through to
     * `Message::user($text)` plus a backend completion, which sets
     * `inFlight = true`; every one of the handlers leaves it false, including
     * the ones that return a Cmd of their own (`/exit`, `/share`,
     * `/websearch`) and the one that returns no message at all (`/clear`).
     * Measured across all of them, before and after the Phase 4 refactor.
     *
     * So this is not an inventory of what somebody already wrote down: ADD a
     * row to `CommandRegistry::all()` with no arm in `Chat::dispatchCommand()`
     * and this test reds, because submitting it sends it to the model. That is
     * the failure mode the registry's own docblock warned about in prose and
     * nothing checked — `/model` sat `slashVisible: false` for exactly that
     * reason, and `SettingsPane`'s footer advertised it anyway.
     *
     * {@see testADraftThatIsNotACommandDoesGoToTheModel()} is the paired
     * negative control: without it, an `$inFlight` that could never be true
     * would make every assertion below vacuous.
     */
    public function testEverySlashVisibleRegistryRowHasALiveDispatchHandler(): void
    {
        $rows = CommandRegistry::slashCommands();
        $this->assertNotSame([], $rows, 'fixture: there are slash-visible rows to walk');

        foreach ($rows as $spec) {
            $next = $this->submit('/' . $spec->name);

            $this->assertFalse(
                $next->inFlight,
                "/{$spec->name} is advertised in the \"/\" popup but no dispatch arm claims it, so submitting "
                . 'it sends the command text to the MODEL as a prompt. Add an arm to Chat::dispatchCommand() '
                . 'or take the row out of the popup with slashVisible: false',
            );
        }
    }

    /**
     * The negative control for the inventory above, and the reason its signal
     * discriminates: a draft that is NOT a command goes to the model, and that
     * is what `inFlight = true` looks like.
     *
     * Includes the two rows deliberately kept OUT of the popup
     * (`slashVisible: false`), because "palette-only" has to mean something —
     * if `/new` had quietly grown a handler, the registry flag would be lying
     * in the other direction.
     */
    public function testADraftThatIsNotACommandDoesGoToTheModel(): void
    {
        foreach (['hello there', '/definitely-not-a-command', '/new', '/docs'] as $draft) {
            $next = $this->submit($draft);

            $this->assertTrue($next->inFlight, "{$draft} must be sent to the model");
            $this->assertSame(
                [$draft],
                array_map(static fn(Message $m): string => $m->content, $this->added($next)),
                "{$draft} must land in history as the prompt it is",
            );
        }
    }

    // ── item 1: /model ───────────────────────────────────────────────────

    /**
     * The OTHER direction of the inventory, and the direction nothing closed.
     *
     * {@see testEverySlashVisibleRegistryRowHasALiveDispatchHandler()} closes
     * registry -> arm: an advertised row with no handler reds. Nothing closed
     * arm -> registry, and the hole was not theoretical - an arm added to
     * `Chat::dispatchCommand()` with no registry row ships a command that
     * dispatches and takes arguments but appears in no "/" popup, no Ctrl+P
     * palette and no `/help` listing. Measured: adding
     * `'zzzsecret' => $this->handleClearCommand(),` to the match left the full
     * suite green.
     *
     * The arm names come out of `dispatchCommand()`'s own source via
     * {@see dispatchArmNames()}, NOT from a list written down here, because a
     * hand-written list of arms is the same blindness one level up: it would be
     * exactly as silent about the 23rd arm as the suite was about the 22nd.
     */
    public function testEveryDispatchArmIsAdvertisedOrDeliberatelyUnadvertised(): void
    {
        // Unadvertised ON PURPOSE, one reason per line, because adding a name
        // here is a decision to ship a command no surface names. All three are
        // second spellings of a row that IS advertised, kept because the prefix
        // chain this dispatch replaced reached them:
        //
        // - `quit`: the second spelling of `/exit`. One arm serves both
        //   ('exit', 'quit' => Cmd::quit()), and `/exit` is the registry row.
        // - `agent`: the singular of the `agents` row. The old chain tested
        //   str_starts_with($text, '/agent'), so both spellings worked - see
        //   testBothAgentSpellingsStillDispatch() for what bare `/agent`
        //   actually does.
        // - `background`: the long form of the `bg` row, same story.
        $unadvertisedAliases = ['quit', 'agent', 'background'];

        $arms = self::dispatchArmNames();
        $advertised = array_map(static fn(CommandSpec $spec): string => $spec->name, CommandRegistry::slashCommands());

        // Without these guards a broken extractor makes every assertion below
        // vacuous: an empty $arms passes the loop trivially. Every advertised
        // row currently HAS a match arm, so the extractor has to find all of
        // them - and if a row is ever dispatched by something other than a match
        // arm (the `mcp auth` prefix branch is dispatched ahead of the parse, for
        // instance) this instrument cannot see it, which is a fact that belongs
        // in a failure message rather than in a silent gap.
        foreach ($advertised as $name) {
            $this->assertContains(
                $name,
                $arms,
                "fixture: dispatchArmNames() must find the /{$name} arm. If /{$name} is dispatched by something "
                . 'other than a match arm, this test cannot see it and needs saying so here',
            );
        }

        foreach ($arms as $name) {
            if (in_array($name, $unadvertisedAliases, true)) {
                continue;
            }

            $this->assertContains(
                $name,
                $advertised,
                "Chat::dispatchCommand() acts on /{$name}, but CommandRegistry::slashCommands() does not advertise "
                . 'it - so it is in no "/" popup, no Ctrl+P palette and no /help listing. Either add a row to '
                . "CommandRegistry::all() or add '{$name}' to this test's \$unadvertisedAliases with the reason",
            );
        }

        // The allowlist stays honest in the other direction too: an alias whose
        // arm was deleted must not linger here looking like live coverage.
        foreach ($unadvertisedAliases as $alias) {
            $this->assertContains(
                $alias,
                $arms,
                "/{$alias} is allowlisted as a deliberately unadvertised alias but has no dispatch arm any more",
            );
        }
    }

    /**
     * Every name `Chat::dispatchCommand()` will act on, read off the method's
     * own source.
     *
     * Reflection + `token_get_all()` rather than a literal list, for the reason
     * {@see testEveryDispatchArmIsAdvertisedOrDeliberatelyUnadvertised()}
     * gives. Only arm keys at the TOP level of a `match` are collected: the walk
     * enters at `T_MATCH`, tracks curly depth so a nested match's arms belong to
     * the nested match, and tracks paren/bracket depth so a string inside an arm
     * VALUE (`$this->handleX(['a' => 1])`) cannot be mistaken for an arm key.
     * Comments are dropped first, so a name quoted in prose is not an arm
     * either.
     *
     * @return list<string>
     */
    private static function dispatchArmNames(): array
    {
        $method = new \ReflectionMethod(Chat::class, 'dispatchCommand');
        $file = (string) $method->getFileName();
        $source = implode('', array_slice(
            (array) file($file),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $tokens = [];
        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], true)) {
                continue;
            }
            $tokens[] = $token;
        }

        $names = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!(is_array($tokens[$i]) && $tokens[$i][0] === T_MATCH)) {
                continue;
            }

            while ($i < $count && $tokens[$i] !== '{') {
                $i++;
            }
            $i++;

            $curly = 1;
            $round = 0;
            $square = 0;
            $pending = [];
            for (; $i < $count && $curly > 0; $i++) {
                $token = $tokens[$i];
                if ($token === '{') {
                    $curly++;
                    continue;
                }
                if ($token === '}') {
                    $curly--;
                    continue;
                }
                if ($curly !== 1) {
                    continue;
                }
                if ($token === '(') {
                    $round++;
                    continue;
                }
                if ($token === ')') {
                    $round--;
                    continue;
                }
                if ($token === '[') {
                    $square++;
                    continue;
                }
                if ($token === ']') {
                    $square--;
                    continue;
                }
                if ($round !== 0 || $square !== 0) {
                    continue;
                }
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $pending[] = substr($token[1], 1, -1);
                    continue;
                }
                if ($token === ',') {
                    continue;
                }
                if (is_array($token) && $token[0] === T_DOUBLE_ARROW) {
                    foreach ($pending as $name) {
                        $names[$name] = true;
                    }
                    $pending = [];
                    continue;
                }

                // Anything else ends the key list without consuming it - a
                // `default` arm, a variable, an operator.
                $pending = [];
            }
            $i--;
        }

        return array_keys($names);
    }

    /**
     * Bare `/model` opens the provider picker in EXACTLY the state Ctrl+P →
     * "Switch model" opens it in. Asserted against the palette route rather
     * than against a literal triple, because "the same picker" is the claim.
     */
    public function testBareSlashModelOpensTheSameProviderPickerCtrlPDoes(): void
    {
        $viaPalette = self::pickFromPalette($this->chat(), 'Switch model');

        $viaCommand = $this->submit('/model');

        $this->assertNotNull($viaCommand->palette());
        $this->assertSame('providers', $viaCommand->palette()->mode);
        $this->assertEquals(
            $viaPalette->palette(),
            $viaCommand->palette(),
            'the command and the palette action must land on the same picker state',
        );
        $this->assertSame(
            $viaPalette->paletteMatches(),
            $viaCommand->paletteMatches(),
            'and therefore on the same provider list',
        );
        $this->assertSame('', $viaCommand->inputBuf, 'the command clears the draft');
        $this->assertSame([], $this->added($viaCommand), 'and says nothing in the transcript');
    }

    /**
     * `/model <provider>` switches the live backend without going through the
     * picker — the same {@see \SugarCraft\Crush\Cli\Bootstrap::backendFor()}
     * call the picker's own Enter makes.
     *
     * The backend object is compared by identity through reflection because
     * that is the thing that has to change; the transcript line is the part
     * the user sees, and both are asserted so a handler that only TALKS about
     * switching fails.
     */
    public function testSlashModelWithAProviderSwitchesTheBackend(): void
    {
        $before = $this->chat('/model custom');
        $after = $this->submit('/model custom', $before);

        $this->assertNotSame(
            self::backendOf($before),
            self::backendOf($after),
            'the live backend must actually be replaced',
        );
        $this->assertSame(
            ["Switched to provider 'custom'."],
            array_map(static fn(Message $m): string => $m->content, $this->added($after)),
        );
        $this->assertFalse($after->inFlight, 'and nothing goes to the model');
        $this->assertSame('', $after->inputBuf);
    }

    /**
     * An unknown provider name must FAIL VISIBLY: a line in the transcript
     * naming what went wrong, no uncaught exception out of `update()`, and the
     * backend left alone.
     *
     * All three matter separately. `ProviderFactory::defaultConfig()` throws
     * `InvalidArgumentException` for a name it does not know, so the two ways
     * to get this wrong are letting that escape (which kills the TUI) and
     * swallowing it (which leaves the user believing they switched). The
     * assertion on the OLD backend still being in place is what catches the
     * second one, since a silent no-op also leaves `inFlight` false.
     */
    public function testSlashModelWithAnUnknownProviderFailsIntoTheTranscript(): void
    {
        $before = $this->chat('/model no-such-provider');
        $after = $this->submit('/model no-such-provider', $before);

        $added = $this->added($after);
        $this->assertCount(1, $added, 'exactly one line, in the transcript');
        $this->assertSame(Role::Assistant, $added[0]->role);
        $this->assertStringContainsString('no-such-provider', $added[0]->content, 'naming what was asked for');
        $this->assertStringContainsString(
            'Unknown provider type',
            $added[0]->content,
            'and naming why it failed, rather than a bare "could not"',
        );
        $this->assertSame(
            self::backendOf($before),
            self::backendOf($after),
            'the session keeps the backend it had — a failed switch must not silently leave a half-switch',
        );
        $this->assertFalse($after->inFlight);
        $this->assertNull($after->palette(), 'and it does not fall back to opening the picker');
    }

    /**
     * A provider name is one token, so a sentence gets a usage line instead of
     * a guess at which word was the name.
     */
    public function testSlashModelWithSeveralWordsAnswersWithUsage(): void
    {
        $after = $this->submit('/model please use sglang');

        $added = $this->added($after);
        $this->assertCount(1, $added);
        $this->assertStringContainsString('Usage: /model', $added[0]->content);
        $this->assertStringContainsString('sglang', $added[0]->content, 'and lists the names that exist');
        $this->assertFalse($after->inFlight);
    }

    // ── item 2: /help lists the commands ─────────────────────────────────

    /**
     * The `/help` listing is laid out against the CURRENT terminal width and
     * then frozen into history, and {@see \SugarCraft\Crush\Renderer::render()}
     * paints one logical line per physical row - so a row wider than the frame
     * lands on top of the row below it. This repo has shipped and fixed that
     * exact frame-corruption bug once already.
     *
     * All of `Chat::HELP_CHROME_COLS`, `Chat::HELP_NAME_COLS` and every
     * `self::clip()` call in `handleHelpCommand()` were unpinned until this
     * test: replacing the budget with `$budget = 100000` left the FULL suite
     * green while the rendered frame went 77 columns wide at both 40 and 60
     * columns, measured with `Width::string()`.
     *
     * Expected width is DERIVED, twice over, so no measured number is written
     * down here to go stale:
     *
     * - the listing's natural width is measured at a 400-column terminal, where
     *   nothing clips it (73 columns today - the `/fork <prompt>` row - but the
     *   test never says so);
     * - the budget restates `handleHelpCommand()`'s own `max(20, cols - chrome)`
     *   with `chrome` read off the constant by reflection. Restating it is the
     *   point: a change to the chrome arithmetic should have to be made twice,
     *   once in the layout and once in what the layout promises.
     */
    public function testTheHelpListingIsClippedToTheWidthItWasLaidOutFor(): void
    {
        $chrome = (int) (new \ReflectionClassConstant(Chat::class, 'HELP_CHROME_COLS'))->getValue();

        $natural = $this->widestHelpLine(400);
        $this->assertGreaterThan(
            $chrome,
            $natural,
            'fixture: the listing has real width to lose',
        );

        foreach ([20, 30, 40, 60, 80, 100, 120] as $cols) {
            $budget = max(20, $cols - $chrome);

            $this->assertSame(
                min($natural, $budget),
                $this->widestHelpLine($cols),
                "at {$cols} columns every row of the /help listing must fit the {$budget}-column budget it was "
                . 'laid out against, or the transcript renderer paints it over the row below',
            );
        }

        // Non-vacuity: the clip has to actually bite at the narrow widths,
        // otherwise min($natural, $budget) is just $natural seven times.
        $this->assertLessThan($natural, $this->widestHelpLine(40), 'the clip must really be cutting rows at 40 columns');
    }

    /**
     * The listing is GROUPED by category rather than walked in declared order,
     * and this is the property that comment claims: the registry interleaves
     * its categories, so a "heading whenever the category changes" walk prints
     * some headings more than once.
     *
     * Both counts are derived from the registry here rather than written into a
     * comment, because the comment that used to carry them said "three separate
     * runs" printing a heading "five times" - two different wrong numbers, and
     * mutually impossible.
     */
    public function testTheHelpListingPrintsOneHeadingPerCategoryNotOnePerRun(): void
    {
        $categories = [];
        $runs = 0;
        $previous = null;
        foreach (CommandRegistry::slashCommands() as $spec) {
            if ($spec->category !== $previous) {
                $runs++;
                $previous = $spec->category;
            }
            $categories[$spec->category] = true;
        }

        $this->assertGreaterThan(
            count($categories),
            $runs,
            'fixture: the registry must still interleave categories, or grouping buys nothing',
        );

        // At 100 columns the budget is far wider than any category name, so a
        // heading row is the category name and nothing else.
        $lines = explode("\n", $this->added($this->submit('/help', $this->chat('/help')))[0]->content);

        $headings = 0;
        foreach (array_keys($categories) as $category) {
            $seen = count(array_filter($lines, static fn(string $line): bool => $line === $category));
            $this->assertSame(1, $seen, "the {$category} heading must be printed exactly once");
            $headings += $seen;
        }

        $this->assertSame(count($categories), $headings, 'one heading per category');
        $this->assertLessThan($runs, $headings, 'and fewer than the declared-order walk would print');
    }

    /**
     * The other half of `handleHelpCommand()`'s width machinery, which the
     * budget test above cannot see: `Chat::HELP_NAME_COLS`, the column the
     * descriptions start at, and the spill branch for a head too wide for it.
     *
     * Both are asserted as PROPERTIES rather than against the constant's
     * current value, because 24 is a layout choice and a different number is
     * not a defect - what would be a defect is descriptions that no longer line
     * up, or a column so wide the descriptions have nowhere to go. That second
     * one is the trade the constant exists to make: the widest
     * `  /name <hint>` column in the registry is `/websearch`'s at 71 columns
     * (measured with `Width::string()`; its popup head `/name <hint>` is 69 and
     * its hint alone is 58 - three domains, three numbers), and a description
     * column sized to THAT would exceed the 70-column budget an 80-column
     * terminal gets.
     */
    public function testTheHelpListingAlignsItsDescriptionsAndLeavesRoomForThem(): void
    {
        $nameCols = (int) (new \ReflectionClassConstant(Chat::class, 'HELP_NAME_COLS'))->getValue();
        $chrome = (int) (new \ReflectionClassConstant(Chat::class, 'HELP_CHROME_COLS'))->getValue();

        // The widest column the registry would ask for, if the column were
        // sized to the rows instead of being a constant.
        $widestColumn = 0;
        foreach (CommandRegistry::slashCommands() as $spec) {
            $widestColumn = max($widestColumn, Width::string(self::helpHead($spec)));
        }

        $this->assertLessThan(
            max(20, 80 - $chrome),
            $nameCols,
            'the description column has to leave the descriptions room inside an 80-column budget',
        );
        $this->assertLessThan(
            $widestColumn,
            $nameCols,
            'and it is a constant precisely BECAUSE the widest row would not leave them any',
        );

        // 400 columns so nothing is clipped and the layout is the only thing on
        // display.
        $listing = explode("\n", $this->added($this->submit('/help', $this->chat('/help', 400)))[0]->content);

        $columns = [];
        $spilled = 0;
        foreach (CommandRegistry::slashCommands() as $spec) {
            $head = self::helpHead($spec);

            $line = null;
            foreach ($listing as $index => $candidate) {
                if ($candidate === $head) {
                    // The spill branch: the head has the row to itself and the
                    // description is on the next line, indented to the column.
                    $spilled++;
                    $line = $listing[$index + 1] ?? '';
                    break;
                }
                if (str_starts_with($candidate, $head . ' ')) {
                    $line = $candidate;
                    break;
                }
            }

            $this->assertNotNull($line, "/{$spec->name} must have a row in the listing");
            $at = mb_strpos($line, $spec->description);
            $this->assertIsInt($at, "/{$spec->name}'s description must be on the row this test found");
            $columns[$spec->name] = Width::string(mb_substr($line, 0, $at));
        }

        $this->assertGreaterThan(0, $spilled, 'fixture: at least one head is too wide for the column, or the spill branch is untested');
        $this->assertSame(
            array_fill_keys(array_keys($columns), $nameCols),
            $columns,
            'every description starts at the same column, spilled rows included',
        );
    }

    /** The `  /name <hint>` column of $spec, exactly as the listing lays it out. */
    private static function helpHead(CommandSpec $spec): string
    {
        return '  /' . $spec->name . ($spec->argumentHint !== null ? ' ' . $spec->argumentHint : '');
    }

    /** The widest line of the `/help` listing as laid out at $cols columns. */
    private function widestHelpLine(int $cols): int
    {
        $listing = $this->added($this->submit('/help', $this->chat('/help', $cols)))[0]->content;

        $widest = 0;
        foreach (explode("\n", $listing) as $line) {
            $widest = max($widest, Width::string($line));
        }

        return $widest;
    }

    // ── item 2: /clear ───────────────────────────────────────────────────

    /**
     * `/clear` empties the transcript and KEEPS the session — the whole point
     * of it being a separate command from the palette's New session action.
     *
     * Every clause of `handleClearCommand()`'s docblock is asserted here,
     * including the ones that say "not touched": a docblock listing what a
     * command leaves alone is only worth reading if something checks it. The one
     * clause that needs its own fixture - the in-flight turn - is
     * {@see testSlashClearIsUnreachableWhileATurnIsInFlight()}.
     *
     * THREE of those clauses had no assertion anywhere in the repo when this
     * test first shipped, and the fixture is why: deleting
     * `'streamingText' => ''`, `'scrollOffset' => 0` and `'expanded' => []`
     * from `handleClearCommand()` left the full suite green, because the fixture
     * arrived with all three already at their post-clear values. Asserting a
     * reset against a default is not asserting a reset, so this fixture is
     * scrolled back, has a tool body expanded and carries half a streamed reply
     * BEFORE `/clear` runs.
     */
    public function testSlashClearEmptiesTheTranscriptAndKeepsTheSession(): void
    {
        $store = new EnhancedSessionStore($this->sandbox . '/sessions.sqlite');
        $store->createSession('sess-1', 'echo', 'echo-model', name: 'a named session');
        $store->saveCheckpoint('sess-1', ['messages' => [], 'inputBuf' => '']);

        $chat = (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: '/clear',
            backend: new EchoBackend(),
            expanded: ['tool-call-1' => true],
            streamingText: 'half a repl',
        ))->withSize(100, 30)
            ->withScrollOffset(3)
            ->withSessionStore($store)
            ->withCurrentSessionId('sess-1');

        // Every one of these is a value the command promises to reset, so it has
        // to be non-default going in or the assertion after it restates a
        // default instead of pinning a reset.
        $this->assertSame('half a repl', $chat->streamingText(), 'fixture: a partial reply is on screen');
        $this->assertSame(3, $chat->scrollOffset(), 'fixture: the transcript is scrolled back');
        $this->assertSame(['tool-call-1' => true], $chat->expanded(), 'fixture: a tool body is expanded');
        $this->assertGreaterThan(0, $chat->contextTokens(), 'fixture: the context counter reads non-zero');

        $checkpointsBefore = $store->listCheckpoints('sess-1');
        $this->assertNotSame([], $checkpointsBefore, 'fixture: there is a checkpoint to leave alone');

        $after = $this->submit('/clear', $chat);

        $this->assertSame([], $after->history, 'the transcript is empty');
        $this->assertSame('', $after->inputBuf);
        $this->assertFalse($after->inFlight, 'and nothing was sent to the model');
        $this->assertSame(0, $after->scrollOffset(), 'the scroll offset indexed the transcript that just went');
        $this->assertSame([], $after->expanded(), 'and so did the expanded tool bodies');
        $this->assertSame(
            '',
            $after->streamingText(),
            'a half-streamed reply left behind would repaint above an empty transcript',
        );
        // The counters are DERIVED - estimateTokenCount() runs over $history on
        // every render, there is no stored counter - so clearing history is what
        // makes them read zero. Asserted anyway: it is the clause's whole claim,
        // and it is what would break if a cached counter ever landed.
        $this->assertSame(0, $after->contextTokens(), 'the context counter reads zero because history is what it counts');

        // KEPT — the distinction from /new.
        $this->assertSame('sess-1', $after->currentSessionId(), 'the session id must survive');
        $this->assertSame(
            $checkpointsBefore,
            $store->listCheckpoints('sess-1'),
            'checkpoints are untouched, so /rewind still reaches the cleared turns',
        );
        $session = $store->getSession('sess-1');
        $this->assertNotNull($session, 'the session row on disk is untouched');
        $this->assertSame('a named session', $session['name'] ?? null, 'name included');
        $this->assertCount(1, $store->listSessions(), 'and no second session was minted');
    }

    /**
     * The "IN-FLIGHT TURN: not cancelled" clause of `handleClearCommand()`'s
     * docblock, which is a claim about `update()` rather than about the handler:
     * Enter is swallowed while a turn is in flight (the `if ($this->inFlight)`
     * guard precedes the Enter arm), so `/clear` cannot fire mid-turn at all and
     * has no cancellation to perform. Escape is the cancel.
     */
    public function testSlashClearIsUnreachableWhileATurnIsInFlight(): void
    {
        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: '/clear',
            backend: new EchoBackend(),
            inFlight: true,
        );

        [$after] = $chat->update(new KeyMsg(KeyType::Enter));

        $this->assertSame($chat->history, $after->history, 'the transcript survives, because Enter never reached dispatch');
        $this->assertSame('/clear', $after->inputBuf, 'and the draft is still there for when the turn ends');
        $this->assertTrue($after->inFlight, 'the turn is still running');
    }

    /**
     * The contrast, in one test, because the two commands are defined against
     * each other: `/new` (the palette's New session action) MINTS a session id
     * and keeps the transcript; `/clear` keeps the id and drops the
     * transcript. A change that made either behave like the other reds here.
     */
    public function testSlashClearIsTheOppositeTradeToANewSession(): void
    {
        $store = new EnhancedSessionStore($this->sandbox . '/sessions.sqlite');
        $store->createSession('sess-1', 'echo', 'echo-model');
        $base = $this->chat()->withSessionStore($store)->withCurrentSessionId('sess-1');

        $cleared = $this->submit(
            '/clear',
            $this->chat('/clear')->withSessionStore($store)->withCurrentSessionId('sess-1'),
        );

        // The palette route, which is the only way to reach New session.
        $fresh = self::pickFromPalette($base, 'New session');

        $this->assertSame([], $cleared->history);
        $this->assertSame('sess-1', $cleared->currentSessionId());

        $this->assertNotSame([], $fresh->history, '/new keeps the conversation on screen');
        $this->assertNotSame('sess-1', $fresh->currentSessionId(), 'and mints a new id');
    }

    // ── item 7: the refactor must not have widened dispatch ──────────────

    /**
     * `CommandParser::parse()` lowercases the name it reports, and the chain it
     * replaced compared raw bytes. Routing through the parser without a guard
     * would silently make every command case-insensitive — a behaviour change
     * dressed as a refactor, and one `KeyHelpTest`'s draft corpus happens to
     * pin for `/KEYS` alone. Pinned here for every command at once.
     */
    public function testTheRefactorDidNotMakeCommandsCaseInsensitive(): void
    {
        foreach (CommandRegistry::slashCommands() as $spec) {
            $upper = '/' . strtoupper($spec->name);
            if ($upper === '/' . $spec->name) {
                continue; // a name with no letters to shout
            }

            $next = $this->submit($upper);
            $this->assertTrue($next->inFlight, "{$upper} must go to the model, exactly as it did before");
        }
    }

    /**
     * The four argument-less commands stayed argument-less. Their old arms
     * compared the WHOLE trimmed buffer (`$text === '/exit'`), so a
     * name-keyed dispatch had to keep an exactness guard or `/exit now` and
     * `/keys foo` would have quietly become commands.
     *
     * `/exit:` is in the list on purpose: `CommandParser` treats `:` as a
     * name/argument separator, so it parses to the name `exit` with no
     * arguments — the one input where "no arguments" and "the text is exactly
     * the name" disagree.
     */
    public function testTheArgumentLessCommandsStillTakeNoArguments(): void
    {
        foreach (['/exit now', '/quit please', '/keys foo', '/help me name this variable', '/clear all', '/exit:'] as $draft) {
            $next = $this->submit($draft);

            $this->assertTrue($next->inFlight, "{$draft} must still be a prompt, not a command");
            $this->assertNull($next->keyHelp(), "{$draft} must not open the keybinding reference");
        }
    }

    /**
     * Arguments still reach their handler, and the handler still sees the RAW
     * draft rather than a re-split copy — `/theme dark` has to change the
     * theme, not merely be recognised.
     */
    public function testArgumentsStillReachTheirHandler(): void
    {
        $next = $this->submit('/theme dracula');

        $this->assertSame('dracula', $next->theme()->name);
        $this->assertFalse($next->inFlight);
    }

    /**
     * The bare `mcp auth …` spelling has no leading slash, so `parse()` returns
     * null for it — it needs its own branch ahead of the parse, and it had one
     * before the refactor. A user with that muscle memory (and the palette's
     * ToggleMcp action, which dispatches this exact string) must keep working.
     */
    public function testTheSlashlessMcpAuthSpellingStillDispatches(): void
    {
        $next = $this->submit('mcp auth list');

        $this->assertFalse($next->inFlight, 'mcp auth list must not go to the model');

        // Asserted present before it is asserted about: a `?? ''` here turns a
        // missing reply into "'' does not contain 'MCP'", which names neither
        // the real operand nor the real failure.
        $added = $this->added($next);
        $this->assertArrayHasKey(1, $added, 'mcp auth list appends the user echo and then the handler reply');
        $this->assertStringContainsString('MCP', $added[1]->content);
    }

    /**
     * `/agent` and `/agents` both reach `handleAgentsCommand()`. The alias is
     * invisible in the registry - the row is `agents` - so a tidy-up would take
     * the `'agent', ` arm out without noticing it was reachable, which is why
     * there is a test at all.
     *
     * The version of this test that shipped with the Phase 4 refactor had NO
     * power over that arm, and the reason is worth writing down because it is a
     * trap the next test here can fall into as well: bare `/agent` + Enter never
     * reaches dispatch. `Chat::slashMenuShouldIntercept()` returns true unless
     * the typed text is an EXACT match for a registry name, and `agent` is not
     * one (`agents` is), so Enter COMPLETES the popup instead of submitting.
     * Driven as real keystrokes at 100x30 over EchoBackend, `/agent` + Enter
     * leaves inputBuf '/agents ' with the transcript untouched - so `inFlight`
     * is false afterwards for a reason that has nothing to do with routing, and
     * the old `assertFalse($this->submit('/agent')->inFlight)` still passed with
     * the arm deleted.
     *
     * Both halves are pinned below: the popup completion, because that IS what
     * bare `/agent` does, and the arm itself - exercised the way the real callers
     * do it (`ChatTest`, `Cli\AgentManagerWiringTest`), with an argument, whose
     * space stops the popup from showing.
     */
    public function testBothAgentSpellingsStillDispatch(): void
    {
        // Bare: popup-completed, NOT dispatched.
        $completed = $this->submit('/agent');
        $this->assertSame('/agents ', $completed->inputBuf, 'bare /agent completes the popup rather than dispatching');
        $this->assertCount(2, $completed->history, 'so the transcript is untouched - the fixture history, unchanged');
        $this->assertFalse($completed->inFlight);

        // With an argument: past the popup and into the arm. Both spellings are
        // one handler, so the REPLY has to be identical (the user echo is not -
        // it echoes what was typed).
        $singular = $this->submit('/agent list');
        $plural = $this->submit('/agents list');

        $this->assertFalse($singular->inFlight, '/agent list must be answered locally, not sent to the model');
        $this->assertFalse($plural->inFlight);

        $singularAdded = $this->added($singular);
        $pluralAdded = $this->added($plural);
        $this->assertArrayHasKey(1, $singularAdded, '/agent list must append a reply of its own');
        $this->assertArrayHasKey(1, $pluralAdded);
        $this->assertStringContainsString(
            '/agents',
            $singularAdded[1]->content,
            'and the reply must come from handleAgentsCommand(), which names /agents in it',
        );
        $this->assertSame(
            $pluralAdded[1]->content,
            $singularAdded[1]->content,
            '/agent <args> and /agents <args> are the same arm, so they answer identically',
        );
    }

    /**
     * Open the Ctrl+P palette on $chat, walk to the row labelled $label and
     * press Enter — the only route to a palette-only action, driven as
     * keystrokes so the comparison against a slash command is like for like.
     */
    private static function pickFromPalette(Chat $chat, string $label): Chat
    {
        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $target = array_search($label, $open->paletteMatches(), true);
        \PHPUnit\Framework\Assert::assertIsInt($target, "fixture: the root palette lists {$label}");

        for ($i = 0; $i < $target; $i++) {
            [$open] = $open->update(new KeyMsg(KeyType::Down, ''));
        }
        [$picked] = $open->update(new KeyMsg(KeyType::Enter, ''));

        return $picked;
    }

    /** @return object the private Backend behind $chat */
    private static function backendOf(Chat $chat): object
    {
        $property = new \ReflectionProperty(Chat::class, 'backend');

        return $property->getValue($chat);
    }
}
