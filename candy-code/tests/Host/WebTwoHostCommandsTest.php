<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Commands\InitCommand;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Context\Compaction\HistoryFingerprint;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\GoalHostCommand;
use SugarCraft\Code\Host\Commands\GrindHostCommand;
use SugarCraft\Code\Host\Commands\InitHostCommand;
use SugarCraft\Code\Host\Commands\ModelHostCommand;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\CompactionService;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Providers\RebindsModel;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Usage;

/**
 * WEB-2: compact, model, init, goal and grind gained host bodies, so the
 * served session runs them for real instead of answering -32030.
 *
 * The admission mechanism is pinned from both ends: the five spellings now
 * carry a body (the wire's runsIn marker derives from exactly that field —
 * CommandMethods::runsIn is quoted as source to prove the DERIVATION, not a
 * hand-copied roster), and a body-less built-in still answers client. The
 * behaviours mirror what the TUI's handlers do, through the host's own
 * machinery: /compact folds through CompactionService (typed HOLD refuses,
 * the exact branch Chat lands), /model rebinds the session's backend without
 * touching settings, and init/goal/grind start a real turn by having the
 * host submit the same prompt the TUI types into its buffer.
 */
final class WebTwoHostCommandsTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-webtwo-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    public function testTheFiveWebTwoSpellingsCarryHostBodies(): void
    {
        foreach (['compact', 'model', 'init', 'goal', 'grind'] as $spelling) {
            $command = BuiltInCommands::forSpelling($spelling);
            self::assertNotNull($command, "/{$spelling} is a built-in spelling");
            self::assertNotNull($command->hostCommand, "/{$spelling} must be admitted to the server");
        }
    }

    public function testTheRunsInMarkerDerivesFromTheHostBodyAlone(): void
    {
        // WEB-2 may not change the wire shape; the marker's rule must stay
        // "server iff the spec has a host body", read off the source.
        $source = (string) file_get_contents(__DIR__ . '/../../src/Protocol/Methods/CommandMethods.php');
        self::assertStringContainsString(
            'hostCommand !== null ? self::RUNS_IN_SERVER : self::RUNS_IN_CLIENT',
            $source,
            'the runsIn marker must derive from hostCommand — a body-less spec stays client by construction',
        );

        // ...and the body-less half of that construction, live:
        $theme = BuiltInCommands::forSpelling('theme');
        self::assertNotNull($theme);
        self::assertNull($theme->hostCommand, '/theme is still terminal-only: no body, no server marker');
    }

    public function testAPlainCompactFoldsThroughTheSharedEngine(): void
    {
        $host = $this->host();
        self::setHistory($host, $this->heavyBand());

        $result = $host->runCommand('compact');
        self::assertFalse($result->isClientOnly(), 'WEB-2 admits /compact to the server');

        $rows = $host->history();
        $echoes = array_values(array_filter(
            $rows,
            static fn (Message $m): bool => $m->uiOnly && $m->content === '/compact',
        ));
        $reports = array_values(array_filter(
            $rows,
            static fn (Message $m): bool => str_contains($m->content, 'Context compacted: was'),
        ));
        $boundaries = array_values(array_filter(
            $rows,
            static fn (Message $m): bool => str_contains($m->content, 'summary of the messages above this line'),
        ));
        self::assertCount(1, $echoes, 'the fold carries its own echo row');
        self::assertCount(1, $reports, 'the fold reports through the shared engine sentence');
        self::assertCount(1, $boundaries, 'the boundary marker is what tells the model the top is summarized');
        self::assertMatchesRegularExpression(
            '/was \d+ messages, now \d+ messages \(saved \d+% tokens\)/',
            $reports[0]->content,
            'the engine report counts the wire, the way the terminal route spells it',
        );
    }

    public function testAHeldVerdictRefusesTheFoldAndKeepsTheHistory(): void
    {
        $host = $this->host();
        $history = $this->heavyBand();
        self::setHistory($host, $history);

        $fingerprint = CompactionService::new()->historyFingerprint(
            new \SugarCraft\Code\Context\ContextCompactor(CompactorConfig::new()),
            $history,
        );
        self::setAheadOfNeed($host, [
            'id' => 'held-1',
            'fingerprint' => $fingerprint,
            'summaries' => [],
            'safeguardHold' => 'the state block collapsed: 0 of 3 records',
        ]);

        $host->runCommand('compact');

        $refusals = array_values(array_filter(
            $host->history(),
            static fn (Message $m): bool => str_contains($m->content, 'Compaction refused'),
        ));
        self::assertCount(1, $refusals, 'CTX-4: a HOLD on the typed route refuses, it does not fold');
        self::assertStringContainsString('state block collapsed', $refusals[0]->content);
        self::assertCount(
            count($history) + 2,
            $host->history(),
            'only the echo and refusal rows were appended — nothing was condensed',
        );
        self::assertNull(self::aheadOfNeed($host), 'the refused verdict is consumed');
    }

    public function testCompactSelfStaysOnTheInteractiveClient(): void
    {
        $host = $this->host();
        $before = count($host->history());

        $result = $host->runCommand('compact', '--self');

        self::assertCount($before + 2, $host->history(), 'the refusal pair (echo + answer) changes nothing else');
        $rows = $host->history();
        $row = end($rows);
        self::assertStringContainsString('interactive TUI route', $row->content);
    }

    public function testBareModelListsTheSelectableProviders(): void
    {
        $host = $this->host();

        $host->runCommand('model');

        $contents = array_map(static fn (Message $m): string => $m->content, $host->history());
        $listing = implode("\n", $contents);
        self::assertStringContainsString('Current model: no provider', $listing, 'EchoBackend names no provider');
        self::assertStringContainsString('anthropic', $listing, 'the listing rides availableProviders()');
    }

    public function testModelTooManyArgumentsAnswersUsageWithoutTouchingAnything(): void
    {
        $host = $this->host();
        $before = count($host->history());

        $host->runCommand('model', 'openai gpt extra');

        self::assertCount($before + 2, $host->history());
        $rows = $host->history();
        self::assertStringContainsString('Usage', end($rows)->content);
    }

    public function testModelSwitchRebindsTheSessionBackendOnly(): void
    {
        $engine = EngineBackend::new(new RebindingProbeProvider('old-model'), 'old-model');
        $host = $this->host(backendFactory: static fn (string $provider): \SugarCraft\Code\Backend => $engine);
        $workspace = self::workspace($host);

        $host->runCommand('model', 'rebindable new-model');

        self::assertInstanceOf(EngineBackend::class, self::backend($host));
        self::assertSame('new-model', self::backend($host)->model());
        self::assertInstanceOf(EchoBackend::class, $workspace->backend,
            'the launch workspace is untouched: the switch is session-scoped, settings stay whole');
        $rows = $host->history();
        $row = end($rows);
        // W7a-2: the reply is the native sentence pair. This fixture has no
        // SettingsWriter service, so the switch reports itself as session-only.
        self::assertStringContainsString("Switched to provider 'rebindable', model 'new-model'", $row->content);
    }

    /**
     * W7a-2: the RebindsModel door is gone — the native engine takes a model
     * by label — so the only loud refusal left is the native one: a backend
     * that is not an engine has no model to switch.
     */
    public function testAModelOnANonEngineBackendIsRefusedWithTheNativeVerdict(): void
    {
        $host = $this->host(backendFactory: static fn (string $provider): \SugarCraft\Code\Backend => new EchoBackend());

        $host->runCommand('model', 'echo other-model');

        $flagged = array_values(array_filter($host->history(), static fn (Message $m): bool => $m->error));
        self::assertCount(1, $flagged);
        self::assertStringContainsString('does not take a model choice', $flagged[0]->content);
    }

    /**
     * W7a-1: the serve path's durable ledger row must carry the model that
     * ACTUALLY answered. Before this, the sink read the workspace backend —
     * the launch provider — while turns after `/model` run on the session
     * override, so a served switch mis-stamped every later usage row.
     */
    public function testTheSinkStampsTheSessionBackendAfterAModelSwitch(): void
    {
        $pdo = $this->recordModelForSessionBackend(
            static function (SessionHost $host): void {
                (new \ReflectionProperty(SessionHost::class, 'sessionBackend'))->setValue(
                    $host,
                    EngineBackend::new(new ScriptedProvider([]), 'switched-model'),
                );
            },
        );

        self::assertSame('switched-model', $pdo->query("SELECT model FROM usage_records WHERE session_id = 's'")->fetchColumn(),
            'the fallback reads the session backend, not the launch provider');
    }

    public function testTheSinkPrefersTheThreadedModelOverAnyBackend(): void
    {
        $pdo = $this->recordModelForSessionBackend(threaded: 'judge-9');

        self::assertSame('judge-9', $pdo->query("SELECT model FROM usage_records WHERE session_id = 's'")->fetchColumn());
    }

    /** Without a session override, the plain launch path stamps exactly as before. */
    public function testTheSinkOnAnUnswitchedSessionStampsTheWorkspaceModel(): void
    {
        $pdo = $this->recordModelForSessionBackend();

        self::assertSame('launch-model', $pdo->query("SELECT model FROM usage_records WHERE session_id = 's'")->fetchColumn(),
            'no thread and no override: byte-identical to the pre-W7a answer');
    }

    public function testAnUnknownProviderAnswersWhereTheBackendFactoryRefusesIt(): void
    {
        $host = $this->host(backendFactory: static function (string $provider): \SugarCraft\Code\Backend {
            throw new \RuntimeException("no backend for {$provider}");
        });

        $host->runCommand('model', 'nosuchprovider');

        $flagged = array_values(array_filter($host->history(), static fn (Message $m): bool => $m->error));
        self::assertCount(1, $flagged);
        self::assertStringContainsString('No backend for provider', $flagged[0]->content);
    }

    public function testInitEchoesThenStartsThePromptedTurn(): void
    {
        $host = $this->host();

        $host->runCommand('init');
        $this->drain($host);

        $contents = array_map(static fn (Message $m): string => $m->content, $host->history());
        self::assertContains('/init', $contents, 'the draft echoes first');
        $needle = substr(InitCommand::prompt(''), 0, 24);
        self::assertNotEmpty($needle);
        $carried = array_values(array_filter(
            $contents,
            static fn (string $c): bool => str_contains($c, $needle),
        ));
        self::assertNotEmpty($carried, 'the host submitted the same prompt the TUI types');
        self::assertFalse($host->isBusy());
    }

    public function testGoalSetsMarksAndKicksOffWithoutATitleModelRefusal(): void
    {
        $host = $this->host(title: true);

        $host->runCommand('goal', 'ship the demo');
        $this->drain($host);

        $listing = implode("\n", array_map(static fn (Message $m): string => $m->content, $host->history()));
        self::assertStringContainsString('ship the demo', $listing);
        self::assertStringContainsString('/goal', $listing, 'the set row names the command');
        self::assertFalse($host->isBusy());
    }

    public function testGoalWithoutATitleModelRefusesToStartALoop(): void
    {
        $host = $this->host();

        $host->runCommand('goal', 'ship the demo');

        $listing = implode("\n", array_map(static fn (Message $m): string => $m->content, $host->history()));
        self::assertStringContainsString('needs a title model', $listing);
        self::assertSame([], array_values(array_filter(
            $host->history(),
            static fn (Message $m): bool => str_contains($m->content, \SugarCraft\Code\Goal\GoalState::MARKER_PREFIX),
        )), 'neither a goal marker nor a kickoff turn was written — the loop never armed');
    }

    public function testGoalBareAfterSetAnswersStatusAndClearTakesItBack(): void
    {
        $host = $this->host(title: true);
        $host->runCommand('goal', 'ship the demo');
        $this->drain($host);

        $host->runCommand('goal');
        $listing = implode("\n", array_map(static fn (Message $m): string => $m->content, $host->history()));
        self::assertStringContainsString('round', strtolower($listing));

        $host->runCommand('goal', 'clear');
        $listing = implode("\n", array_map(static fn (Message $m): string => $m->content, $host->history()));
        self::assertStringContainsString('cleared', strtolower($listing));
    }

    public function testGrindRidesTheGoalBodyAtItsOwnRoundCeiling(): void
    {
        $host = $this->host(title: true);

        $host->runCommand('grind', 'fix every red');
        $this->drain($host);

        $listing = implode("\n", array_map(static fn (Message $m): string => $m->content, $host->history()));
        self::assertStringContainsString('50', $listing, 'grind means maxRounds 50, not goal\'s 10');
        self::assertStringContainsString('fix every red', $listing);
    }

    public function testReadOnlyBodiesRefuseTheirMutatingArmsAndKeepTheirListings(): void
    {
        $context = CommandContext::new(history: [], readOnly: true);

        self::assertTrue((new ModelHostCommand())->run($context, '/model openai gpt')->isRefused());
        self::assertTrue((new GoalHostCommand())->run($context, '/goal aim')->isRefused());
        self::assertTrue((new InitHostCommand())->run($context, '/init')->isRefused());
        self::assertTrue((new GrindHostCommand())->run($context, '/grind aim')->isRefused());

        $listing = (new ModelHostCommand())->run($context, '/model');
        self::assertFalse($listing->isRefused(), 'a bare listing writes nothing and stays answerable');
    }

    // ------------------------------------------------------------------

    /** @param (\Closure(string): \SugarCraft\Code\Backend)|null $backendFactory */
    private function host(?\Closure $backendFactory = null, bool $title = false): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(
                sessionStore: $this->store,
                backend: new EchoBackend(),
                backendFactory: $backendFactory,
                titleBackend: $title ? new EchoBackend() : null,
            ),
            history: [],
            customCommands: [],
        );
    }

    /** @return list<Message> one heavy pair past any band plus ten trivial exchanges */
    private function heavyBand(): array
    {
        $rows = [Message::user(str_repeat('a', 4_000)), Message::assistant(str_repeat('b', 4_000))];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = Message::user("q{$i}");
            $rows[] = Message::assistant("r{$i}");
        }

        return $rows;
    }

    private static function setHistory(SessionHost $host, array $history): void
    {
        (new \ReflectionProperty(SessionHost::class, 'history'))->setValue($host, $history);
    }

    private static function aheadOfNeed(SessionHost $host): ?array
    {
        return (new \ReflectionProperty(SessionHost::class, 'aheadOfNeed'))->getValue($host);
    }

    /** @param array{id:string,fingerprint:HistoryFingerprint,summaries:?array<string,string>,safeguardHold:?string} $slot */
    private static function setAheadOfNeed(SessionHost $host, array $slot): void
    {
        (new \ReflectionProperty(SessionHost::class, 'aheadOfNeed'))->setValue($host, $slot);
    }

    private static function workspace(SessionHost $host): WorkspaceContext
    {
        return (new \ReflectionProperty(SessionHost::class, 'workspace'))->getValue($host);
    }

    private static function backend(SessionHost $host): \SugarCraft\Code\Backend
    {
        return (new \ReflectionMethod(SessionHost::class, 'backend'))->invoke($host);
    }

    /**
     * Drive the host's real sink closure once and hand back a PDO on the
     * session db, so each test asserts on the model the durable row carries.
     *
     * @param (\Closure(SessionHost): void)|null $applySessionBackend
     */
    private function recordModelForSessionBackend(?\Closure $applySessionBackend = null, ?string $threaded = null): PDO
    {
        $launch = EngineBackend::new(new ScriptedProvider([]), 'launch-model');
        $host = SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: $launch),
            history: [],
            customCommands: [],
        );
        if ($applySessionBackend !== null) {
            $applySessionBackend($host);
        }

        $sink = (new \ReflectionMethod(SessionHost::class, 'usageRecordSink'))->invoke($host);
        self::assertNotNull($sink, 'a host on the enhanced store carries a sink');
        $sink(Usage::new(totalTokens: 10, costUsd: 0.05), $threaded);

        return new PDO('sqlite:' . $this->dir . '/session.db');
    }

    /** Run the shared loop until the host lets go of the session — bounded. */
    private function drain(SessionHost $host): void
    {
        $loop = \React\EventLoop\Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop): void {
            if (!$host->isBusy()) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);

        self::assertFalse($host->isBusy(), 'the turn settled');
    }
}

/**
 * A provider that answers for any model on request (the double only has to
 * satisfy the switch's type tests — no turn is ever run against it).
 */
final class RebindingProbeProvider implements ProviderInterface, RebindsModel
{
    public function __construct(private string $model = 'old-model')
    {
    }

    public function name(): string
    {
        return 'rebindable';
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

    public function supportsDocuments(): bool
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

    public function costPer1kTokens(string $model, string $direction): ?float
    {
        return null;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        throw new \LogicException('the probe never answers a turn');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        yield from [];
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        throw new \LogicException('the probe never embeds');
    }

    public function withModel(string $model): static
    {
        return new self($model);
    }

    public function model(): string
    {
        return $this->model;
    }
}
