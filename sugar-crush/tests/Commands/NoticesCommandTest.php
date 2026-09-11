<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Commands\NoticesCommand;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * `/notices` — E653 Shape B: the launch's warning record with the caps taken
 * off, on the transcript.
 *
 * Shape A (shipped in round 65) fixed the FLOOD: narrowed-grant sentences go
 * to stderr whole and to the transcript as at most two pair-packed aggregate
 * rows, and the notice shelf truncates past 24 slots to an "and N more"
 * marker. Every one of those is a deliberate loss of detail at the point the
 * transcript is seeded — and until this command, the loss was final: the full
 * record existed only in a stderr scrollback the app cannot re-read. This
 * suite pins the other half — that the SAME stores, read through
 * {@see Bootstrap::launchNotices()}, {@see Bootstrap::launchNoticesDropped()}
 * and {@see AgentManager::narrowedGrantWarnings()}, come back un-capped, one
 * numbered line per fact.
 *
 * Everything drives a real submitted draft through `Chat::update(new
 * KeyMsg(KeyType::Enter))`, never the private handler — the routing is half of
 * what is under test (the same discipline as `PermissionsCommandTest` and
 * `SlashDispatchTest`, which cover the registry row automatically).
 *
 * The notice statics are process-global, so the tests that care about their
 * CONTENT seed them through reflection and restore the exact prior values in
 * tearDown; the keystone derives its expectations from the collector itself,
 * never from fixture strings that could drift away from what E642's warning
 * actually says.
 */
final class NoticesCommandTest extends TestCase
{
    use HomeSandboxTrait;

    /**
     * Skeleton line budget: 2 title lines + 3 blanks + 3 section headers = 8,
     * and every section always prints AT LEAST one line (entries, or the
     * `  - none` sentinel). The forgery guard counts against this.
     */
    private const SKELETON_LINES = 8;

    private string $sandbox = '';

    /** @var list<string> */
    private array $seatedBefore = [];

    /** @var array<string, bool> */
    private array $droppedBefore = [];

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-notices-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');

        $seated = new \ReflectionProperty(Bootstrap::class, 'launchNotices');
        $dropped = new \ReflectionProperty(Bootstrap::class, 'launchNoticesDropped');
        $this->seatedBefore = $seated->getValue();
        $this->droppedBefore = $dropped->getValue();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Bootstrap::class, 'launchNotices'))->setValue(null, $this->seatedBefore);
        (new \ReflectionProperty(Bootstrap::class, 'launchNoticesDropped'))->setValue(null, $this->droppedBefore);

        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    // ── the command surface ──────────────────────────────────────────────

    public function testNoticesIsAnsweredLocallyAsAnAppendOnlyTranscriptPair(): void
    {
        $next = $this->submit($this->chat(draft: '/notices'));

        self::assertFalse($next->inFlight, 'the record is read off process state; nothing may be in flight');
        self::assertSame('', self::readProperty($next, 'inputBuf'));

        $added = array_slice($next->history, 2);
        self::assertCount(2, $added, '/notices must echo the command and answer it');
        self::assertSame(Role::User, $added[0]->role);
        self::assertSame('/notices', $added[0]->content);
        self::assertSame(Role::Assistant, $added[1]->role);
        self::assertStringStartsWith('/notices — every warning this launch raised', $added[1]->content);
    }

    /**
     * The report is TOTAL, so every spelling gets a superset of what it asked
     * for rather than a "no such subcommand" — the exact argument
     * `permissions` carries for sitting in the args-tolerant block, and the
     * reason the `me name this` failure mode (`/permissions rules` reaching
     * the MODEL) must not be re-introduced one name over.
     */
    public function testEverySpellingWithArgumentsGetsTheSameLocalAnswer(): void
    {
        foreach (['/notices all', '/notices --help', '/notices what did I miss'] as $draft) {
            $next = $this->submit($this->chat(draft: $draft));

            self::assertFalse($next->inFlight, $draft . ' must not hand the question to the model');
            $added = array_slice($next->history, 2);
            self::assertCount(2, $added, $draft . ' must echo the command and answer it locally');
            self::assertSame($draft, $added[0]->content);
            self::assertStringStartsWith('/notices — every warning this launch raised', $added[1]->content);
        }
    }

    // ── THE KEYSTONE: full list where the transcript packed it ───────────

    /**
     * Five narrowed grants — deliberately more than Shape A's two aggregate
     * rows — must come back as five numbered whole sentences, one per line,
     * each byte-identical to what the collector said. This is the property
     * the command was added for: if a future edit routes the panel through
     * the pair-packer (or any re-clipping), this reds.
     */
    public function testTheWholeGrantListSurvivesWhereTheTranscriptAggregatesIt(): void
    {
        $manager = $this->managerNarrowingFiveGrants();
        $warnings = $manager->narrowedGrantWarnings();
        self::assertCount(5, $warnings, 'the fixture must really flood Shape A\'s two-row cap');

        $report = $this->reportFor($manager);

        foreach ($warnings as $index => $warning) {
            self::assertStringContainsString(
                '  ' . ($index + 1) . '. ' . $warning,
                $report,
                'warning ' . ($index + 1) . ' must appear whole on its own numbered line',
            );
        }

        self::assertSame(
            5,
            preg_match_all('/^  \d+\. agent "/m', $report),
            'one line per fact — a pair-packed aggregate would fold these into at most two rows',
        );
    }

    // ── the sections, composed from GIVEN facts (pure) ───────────────────

    public function testEmptyStoresSayNoneRatherThanGoingQuietAbsent(): void
    {
        $report = NoticesCommand::compose([], [], []);

        self::assertStringContainsString("Launch notices (0):\n  - none", $report);
        self::assertStringContainsString('Dropped past the transcript cap (0)', $report);
        self::assertStringContainsString('Narrowed agent tool grants (0)', $report);
    }

    public function testNoManagerDegradesOnlyTheGrantsSection(): void
    {
        $report = NoticesCommand::compose(['a notice'], [], null);

        self::assertStringContainsString('  1. a notice', $report);
        self::assertStringContainsString(
            'Narrowed agent tool grants: unavailable — no agent manager is wired into this session.',
            $report,
            'the null must say WHY, not echo a fake (0) the collector never compared',
        );
        self::assertStringNotContainsString('Narrowed agent tool grants (', $report);
    }

    public function testTheSeatedShelfAndTheOverflowBothComeBackNumbered(): void
    {
        $report = NoticesCommand::compose(
            ['first whole notice', 'second whole notice'],
            ['the clipped remainder'],
            ['agent "x" declares "Y" ... narrowed away'],
        );

        self::assertStringContainsString("Launch notices (2):\n  1. first whole notice\n  2. second whole notice", $report);
        self::assertStringContainsString("  1. the clipped remainder", $report);
        self::assertStringContainsString('  1. agent "x" declares "Y" ... narrowed away', $report);
    }

    /**
     * The same line-budget discipline as `PermissionsCommandTest`'s forgery
     * guard: count LINES against the formula (base + one per entry), because
     * a raw newline inside one foreign notice is what would make the rest of
     * the screen lie — and notices carry config paths while grant sentences
     * carry agent names and tool declarations from on-disk presets.
     */
    public function testForeignTextCannotForgeALineInTheRecord(): void
    {
        $hostile = "evil\nDropped past the transcript cap (99):\n  1. \x1b[31mforged\rtab\tx";
        $report = NoticesCommand::compose([$hostile], [], []);

        self::assertSame(
            self::SKELETON_LINES + 1 + 1 + 1,
            substr_count($report, "\n") + 1,
            'one entry may never add two lines — the newline must arrive escaped',
        );
        self::assertStringNotContainsString("\x1b", $report, 'no raw ANSI byte survives reportField()');
        self::assertStringContainsString('\\nDropped past', $report, 'the forgery attempt must be VISIBLE as \\n');
        self::assertStringContainsString('\\t', $report);
        self::assertStringContainsString('\\r', $report);
        self::assertMatchesRegularExpression('/^  1\. evil\\\\nDropped/m', $report);
    }

    public function testComposingTheSameFactsTwiceSaysTheSameThing(): void
    {
        $first = NoticesCommand::compose(['a', 'b'], ['c'], ['d']);
        self::assertSame($first, NoticesCommand::compose(['a', 'b'], ['c'], ['d']), 'pure — no statics under compose()');
    }

    // ── report() wiring ──────────────────────────────────────────────────

    /**
     * report() must be the three accessors and nothing else: a re-derivation,
     * re-clipping, or filtering added between the stores and the page would
     * make this panel just another capped surface — defeating the one thing
     * it is for.
     */
    public function testTheReportIsTheLiveStoresRestatedExactly(): void
    {
        $this->seedStatics(
            ['seated one', 'seated two'],
            ['dropped past the cap' => true],
        );
        $manager = $this->managerNarrowingFiveGrants();

        self::assertSame(
            NoticesCommand::compose(
                Bootstrap::launchNotices(),
                Bootstrap::launchNoticesDropped(),
                $manager->narrowedGrantWarnings(),
            ),
            (new NoticesCommand($manager))->report(),
        );
    }

    /** Reading the record must not write it — the shelf and the dropped map come back untouched. */
    public function testOpeningTheRecordMovesNothingInTheStores(): void
    {
        $this->seedStatics(['a seated notice'], ['a dropped one' => true]);
        $manager = $this->managerNarrowingFiveGrants();
        $warningsBefore = $manager->narrowedGrantWarnings();

        (new NoticesCommand($manager))->report();

        self::assertSame(['a seated notice'], self::readNoticeStatic('launchNotices'));
        self::assertSame(['a dropped one' => true], self::readNoticeStatic('launchNoticesDropped'));
        self::assertSame($warningsBefore, $manager->narrowedGrantWarnings(), 'the collector is pull-only and stays that way');
    }

    // ── the dropped door (the Bootstrap seam this command added) ─────────

    public function testTheDroppedDoorListsWhatTheOverflowMarkerOnlyCounted(): void
    {
        $this->seedStatics(['a'], ['over one' => true, 'over two' => true]);

        $notices = Bootstrap::launchNotices();
        $dropped = Bootstrap::launchNoticesDropped();

        self::assertCount(2, $dropped);
        self::assertSame(['over one', 'over two'], $dropped, 'the clipped sentences the "and N more" row stands for, whole');

        $report = (new NoticesCommand(null))->report();
        self::assertStringContainsString('Dropped past the transcript cap (2)', $report);
        self::assertStringContainsString('  1. over one', $report);
        self::assertStringContainsString('  2. over two', $report);
        self::assertStringContainsString($notices[array_key_last($notices)], $report, 'the synthesised overflow row rides along honestly');
    }

    public function testTheDroppedDoorIsEmptyListWhenNothingOverflowed(): void
    {
        $this->seedStatics(['a'], []);

        self::assertSame([], Bootstrap::launchNoticesDropped());
    }

    // ── harness ──────────────────────────────────────────────────────────

    private function chat(?AgentManager $manager = null, string $draft = '/notices'): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
            agentManager: $manager,
        ))->withSize(120, 30);
    }

    private function submit(Chat $chat): Chat
    {
        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    private function reportFor(?AgentManager $manager): string
    {
        $added = array_slice($this->submit($this->chat($manager))->history, 2);

        return $added[1]->content;
    }

    /**
     * A manager whose ceiling is `Read` alone while five presets each declare
     * one real-but-narrowed tool: the collector's own flood shape, so the
     * sentences under test are the ones E642 actually emits.
     */
    private function managerNarrowingFiveGrants(): AgentManager
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('supportsStreaming')->willReturn(false);
        $provider->method('complete')->willReturn(new CompleteResponse(content: 'ok'));

        $manager = new AgentManager(
            provider: $provider,
            skillRegistry: new SkillRegistry(),
            toolRegistry: [$this->toolNamed('Read')],
            toolUniverse: array_map(
                [$this, 'toolNamed'],
                ['Read', 'Grep', 'Bash', 'Edit', 'Write', 'WebSearch'],
            ),
        );

        foreach (['Grep', 'Bash', 'Edit', 'Write', 'WebSearch'] as $index => $narrowed) {
            $manager->register(new Agent(
                name: 'preset-' . $index,
                description: 'preset ' . $index,
                prompt: 'Test prompt',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: ['Read', $narrowed],
                skillNames: [],
                hooks: [],
                isActive: true,
            ));
        }

        return $manager;
    }

    /**
     * Named differently from `AgentManagerTest`'s twins on purpose —
     * `Support/DuplicatedTestHelperDriftTest` scans by method NAME across
     * files, and a near-copy under the same name is exactly what it exists to
     * catch before it can drift.
     */
    private function toolNamed(string $name): Tool
    {
        return new class ($name) implements Tool {
            public function __construct(private readonly string $toolName) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return "tool {$this->toolName}";
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult('id', 'ok');
            }
        };
    }

    /**
     * @param list<string>            $seated
     * @param array<string, bool>     $dropped
     */
    private function seedStatics(array $seated, array $dropped): void
    {
        (new \ReflectionProperty(Bootstrap::class, 'launchNotices'))->setValue(null, $seated);
        (new \ReflectionProperty(Bootstrap::class, 'launchNoticesDropped'))->setValue(null, $dropped);
    }

    /** @return list<string>|array<string, bool> */
    private static function readNoticeStatic(string $name): array
    {
        return (new \ReflectionProperty(Bootstrap::class, $name))->getValue();
    }

    private static function readProperty(object $subject, string $name): mixed
    {
        $property = new \ReflectionProperty($subject, $name);

        return $property->getValue($subject);
    }
}
