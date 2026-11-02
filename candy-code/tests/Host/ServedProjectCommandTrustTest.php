<?php

/**
 * C-SEC-4.a: served sessions honour `trustedProjectCommands` (GAP-CMD-02).
 *
 * The TUI passes the launch's trust answer into
 * {@see \SugarCraft\Code\Commands\CommandLoader::loadAll()} (the
 * `projectCommandsTrusted:` seam in Chat's constructor), so a trusted
 * project's built-in override wins there. The two served callers of the same
 * method — the {@see SessionHost} constructor's customCommands load and
 * `command.list` through CommandMethods (C-MCP-2.b: the host's command map
 * itself) — must give the SAME answer, or a clone the operator granted runs
 * its override in the terminal but not over serve or the web, and a
 * `command.list` row disagrees with what `command.exec` would admit.
 *
 * Mirrors the TUI precedence pins (testAProjectFileOverridesTheBuiltInOfTheSameName
 * / testAProjectFileCannotShadowABuiltInOnAnUntrustedRoot in
 * tests/Commands/CustomCommandDispatchTest.php): trusted override wins,
 * untrusted shadow is refused.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use React\EventLoop\Loop;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\McpPromptCommands;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpPromptProvider;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Skills\SkillSource;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use PHPUnit\Framework\TestCase;

final class ServedProjectCommandTrustTest extends TestCase
{
    use HomeSandboxTrait;

    private const TEMPLATE = 'Summarise this conversation your own way.';

    private string $sandbox = '';

    private string $project = '';

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/crush-served-trust-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project . '/.candy-code/commands', 0o700, true);
        // The shadow that needs the grant, and a plain project row it never does.
        \file_put_contents($this->project . '/.candy-code/commands/compact.md', self::TEMPLATE);
        \file_put_contents($this->project . '/.candy-code/commands/notes.md', 'Note the diff.');
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    // ── the served host (SessionHost constructor seam) ─────────────────

    /**
     * A trusted project's `/compact` override runs in a served host exactly
     * as it runs in the TUI: the file is a command row, the override is
     * announced, nothing is refused, and submitting `/compact` sends the
     * template. Fails before the fix: the constructor dropped the trust
     * argument, so loadAll() refused the row it was told to honour.
     */
    public function testATrustedProjectOverrideRunsInTheServedHost(): void
    {
        $loader = new CommandLoader();
        $host = $this->host($loader, trusted: true);

        self::assertSame(['compact'], \array_keys($loader->overrides()), 'the ctor load reported the override');
        self::assertSame([], $loader->refusedCommands(), 'a granted root is never refused');

        $ticket = $host->submit('/compact');

        self::assertSame(TurnTicket::STARTED, $ticket->admitted, 'the file prompt runs as a turn, like Chat does');
        $sent = \array_values(\array_filter(
            \array_slice($host->history(), 2),
            static fn (Message $m): bool => $m->content === self::TEMPLATE,
        ));
        self::assertCount(1, $sent, 'the template reached the transcript, not the built-in');

        $this->drain($host);
        $host->release();
    }

    /**
     * The same file on an ungranted root stays refused in the served host —
     * the direction to be wrong in, and the negative control that keeps the
     * fix above honest: the built-in must answer, the template must not.
     */
    public function testAnUntrustedProjectOverrideIsRefusedInTheServedHost(): void
    {
        $loader = new CommandLoader();
        $host = $this->host($loader, trusted: false);

        $refusals = $loader->refusedCommands();
        $paths = \implode("\n", \array_keys($refusals));
        self::assertStringContainsString('compact.md', $paths);
        self::assertStringContainsString('trustedProjectCommands', (string) \array_values($refusals)[0]);
        self::assertSame([], $loader->overrides(), 'a refused shadow is never announced as an override');

        $host->submit('/compact');
        $this->drain($host);

        foreach (\array_slice($host->history(), 2) as $appended) {
            self::assertStringNotContainsString('your own way', $appended->content, 'the file prompt was never sent');
        }

        $host->release();
    }

    // ── command.list (the host command-map seam) ───────────────────────

    /**
     * On a trusted root the wire listing says FILE for the overridden name —
     * the same row `command.exec` will admit. Fails before the C-SEC-4.a
     * fix: the listing called loadAll() without the grant, so it kept saying
     * BUILTIN for a command the server actually ran from the file.
     */
    public function testCommandListShowsTheTrustedProjectOverride(): void
    {
        $rows = $this->listed(trusted: true);

        self::assertSame('file', $rows['compact']['source'] ?? null, 'the trusted override is listed as the file');
        self::assertSame('file', $rows['notes']['source'] ?? null, 'an unclaimed project row lists as a file either way');
    }

    /** The negative control: on an ungranted root the listing keeps the built-in row. */
    public function testCommandListKeepsTheBuiltInRowForAnUntrustedShadow(): void
    {
        $rows = $this->listed(trusted: false);

        self::assertSame('builtin', $rows['compact']['source'] ?? null, 'the refused shadow is not listed as a file');
        self::assertSame('file', $rows['notes']['source'] ?? null);
    }

    // ── C-SEC-3.b on the serve path: the user-tier opt-in lists ────────

    /**
     * r9: a bundle the operator listed under `trustedBundleCommands` must
     * carry its opt-in through the SERVED chain too — the TUI threads the
     * same two user-tier lists into every posture call, and the serve path
     * building them positionally-empty denied all a bundle its owner had
     * explicitly trusted. The fork itself is the discriminator for the
     * mustFork leg: an unthreaded list keeps the row sync (mustFork false)
     * and the ticket answers STARTED, while the threaded list parks the
     * expansion off-thread exactly as a native shell row does.
     */
    public function testAServedOptedInUserTierBundleForksAndRunsItsShellForm(): void
    {
        $spec = CommandSpec::new('kit:log', 'd', 'Custom', template: 'On !`printf %s twig` now.', tier: 'user', source: SkillSource::Bundle, originName: 'kit');
        $host = $this->bundleHost($spec, ['kit'], []);

        $ticket = $host->submit('/kit:log');

        self::assertSame(TurnTicket::PENDING, $ticket->admitted, 'the opted-in bundle forks for the shell it may now run — the same answer the TUI gives');

        $this->drain($host);

        $sent = $this->servedContents($host);
        self::assertStringContainsString('On twig now.', $sent, 'the opted-in shell form actually ran in the forked expansion');
        self::assertStringNotContainsString('came from a plugin bundle', $sent, 'the opt-in opened the posture — the bundle sentence has no business here');

        $host->release();
    }

    /**
     * rev-r4 C-1 on the serve path: a checkout can author its own bundle
     * manifest, so the opt-in counts a bundle row only when the row itself
     * carries the user tier. A project-tier squat of the very name the
     * operator listed must stay a refused posture over serve, shell never
     * run — the trust the list carries belongs to the user's own install,
     * not to whatever spells the same name from a clone.
     */
    public function testAServedProjectTierBundleSquattingAListedNameStaysRefused(): void
    {
        $forged = CommandSpec::new('kit:log', 'd', 'Custom', template: 'On !`printf %s twig` now.', tier: 'project', source: SkillSource::Bundle, originName: 'kit');
        $host = $this->bundleHost($forged, ['kit'], []);

        $ticket = $host->submit('/kit:log');

        self::assertSame(TurnTicket::STARTED, $ticket->admitted, 'a refused squat expands inline — its shell form runs nowhere, so it has no business off-thread');
        $this->drain($host);

        $sent = $this->servedContents($host);
        self::assertStringContainsString('came from a plugin bundle', $sent, 'the squatted name opens nothing on serve — the posture refusal rides the expansion');
        self::assertStringNotContainsString('On twig now.', $sent, 'the squatted shell ran — the tier test narrowed nothing');

        $host->release();
    }

    /**
     * Default-off, the shipped answer is untouched: a user-tier bundle row
     * nobody listed expands its refusal inline over serve, exactly as it did
     * before the lists existed. This is the byte-identity arm — the fix may
     * only ever unlock what the operator named.
     */
    public function testAServedBundleWithoutTheOptInStaysRefusedInline(): void
    {
        $spec = CommandSpec::new('kit:log', 'd', 'Custom', template: 'On !`printf %s twig` now.', tier: 'user', source: SkillSource::Bundle, originName: 'kit');
        $host = $this->bundleHost($spec, [], []);

        $ticket = $host->submit('/kit:log');

        self::assertSame(TurnTicket::STARTED, $ticket->admitted, 'no opt-in, no fork — today\'s shape stands');
        $this->drain($host);

        $sent = $this->servedContents($host);
        self::assertStringContainsString('came from a plugin bundle', $sent);
        self::assertStringNotContainsString('On twig now.', $sent, 'an unlisted bundle must run no shell over serve');

        $host->release();
    }

    /**
     * The MCP leg, end to end through the forked expansion: the fetched
     * body's shell form runs over serve ONLY when the operator listed the
     * server key in `mcpPromptShell`, and the lists reach the child that
     * does the expanding (the parked fork's directive), not just the
     * parent's fork decision. The empty-list twin is the default-off arm.
     */
    public function testAServedOptedInMcpPromptRunsItsFetchedShellFormInTheChild(): void
    {
        $host = $this->mcpHost(['srv']);

        $ticket = $host->submit('/mcp__srv__p');

        self::assertSame(TurnTicket::PENDING, $ticket->admitted, 'the fetch always parks the turn, opt-in or not');
        $this->drain($host);

        $sent = $this->servedContents($host);
        self::assertStringContainsString('head ran-here tail', $sent, 'the listed server\'s fetched shell form ran — the opt-in rode into the forked expansion');
        self::assertStringNotContainsString('is an MCP prompt fetched from a server', $sent, 'the opt-in opened the posture — no refusal sentence here');

        $host->release();
    }

    /** Default-off MCP arm: the same fetched body, the same fork, the refusal. */
    public function testAServedMcpPromptWithoutTheOptInStaysRefused(): void
    {
        $host = $this->mcpHost([]);

        $host->submit('/mcp__srv__p');
        $this->drain($host);

        $sent = $this->servedContents($host);
        self::assertStringContainsString('is an MCP prompt fetched from a server', $sent, 'the child\'s directive kept today\'s posture answer with the lists empty');
        self::assertStringNotContainsString('ran-here', $sent, 'the stranger\'s shell ran — the child expanded without the opt-in check');

        $host->release();
    }

    /**
     * r9f (rev-r9 MAJOR-1): the synchronous directive() arm is
     * exec-indiscriminable on this box — an opted-in shell row forks at
     * customCommandMustFork whenever canFork() is true, so the sync path is
     * the no-pcntl-platform shape. Its threading is therefore pinned
     * STRUCTURALLY: the shipped call site must hand commandDirective both
     * opt-in lists by name. Stripping either arg — which no exec pin here
     * can see — silently regresses serve on pcntl-less platforms; this leg
     * is what reddens that refactor.
     */
    public function testTheSynchronousDirectiveCallSiteCarriesBothOptInLists(): void
    {
        $file = (new \ReflectionClass(SessionHost::class))->getFileName();
        self::assertIsString($file);
        $source = (string) file_get_contents($file);
        $from = strpos($source, 'private function directive(CommandSpec $spec)');
        self::assertIsInt($from, 'the synchronous expansion takes its directive from directive()');

        $body = substr($source, $from, (int) (strpos($source, 'private function turns(', $from) ?: $from + 600) - $from);
        self::assertStringContainsString('commandDirective(', $body, 'the slice must cover the call site itself');
        self::assertStringContainsString(
            'trustedBundleCommands: $this->workspace->trustedBundleCommands',
            $body,
            'the sync directive forwards the bundle opt-in — the fork pins cannot reach this arm on a forking box',
        );
        self::assertStringContainsString(
            'mcpPromptShell: $this->workspace->mcpPromptShell',
            $body,
            'and the MCP server-key opt-in with it',
        );
    }

    // ── harness ────────────────────────────────────────────────────────

    /**
     * A served host carrying one hand-planted command row — the loader stays
     * out of it, so the only variables are the row's tier/stamp and the two
     * workspace opt-in lists.
     *
     * @param list<string> $trustedBundleCommands
     * @param list<string> $mcpPromptShell
     */
    private function bundleHost(CommandSpec $spec, array $trustedBundleCommands, array $mcpPromptShell): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(
                root: $this->project,
                sessionStore: $this->store,
                backend: new EchoBackend(),
                trustedBundleCommands: $trustedBundleCommands,
                mcpPromptShell: $mcpPromptShell,
            ),
            history: [Message::user('hello'), Message::assistant('hi')],
            customCommands: ['kit:log' => $spec],
        );
    }

    /**
     * A served host whose command map is the real launch-frozen MCP row for
     * a server whose fetched body carries a shell form; the ONLY difference
     * between the two arms is the workspace's `mcpPromptShell` list.
     *
     * @param list<string> $mcpPromptShell
     */
    private function mcpHost(array $mcpPromptShell): SessionHost
    {
        $server = new class implements McpServer, McpPromptProvider {
            public function start(): void {}
            public function stop(): void {}

            /** @return list<array<mixed>> */
            public function listTools(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            /** @return list<array<mixed>> */
            public function listPrompts(): array
            {
                return [['name' => 'p', 'description' => 'd']];
            }

            /** @return array<mixed> */
            public function prompt(string $name, array $arguments = []): array
            {
                return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'head !`printf ran-%s here` tail']]]];
            }
        };

        $client = new McpClient($this->sandbox . '/mcp-' . \bin2hex(\random_bytes(4)) . '.json', unrestricted: true);
        (new \ReflectionProperty(McpClient::class, 'servers'))->setValue($client, ['srv' => $server]);
        $provider = static fn (): McpClient => $client;

        $placeholder = McpPromptCommands::specs($provider)['mcp__srv__p'] ?? null;
        self::assertInstanceOf(CommandSpec::class, $placeholder);

        return SessionHost::new(
            's',
            WorkspaceContext::new(
                root: $this->project,
                sessionStore: $this->store,
                backend: new EchoBackend(),
                mcpPromptShell: $mcpPromptShell,
            ),
            history: [Message::user('hello'), Message::assistant('hi')],
            customCommands: ['mcp__srv__p' => $placeholder],
            mcpProvider: $provider,
        );
    }

    /** Everything the turn appended past the seeded pair, as one haystack. */
    private function servedContents(SessionHost $host): string
    {
        return \implode("\n", \array_map(
            static fn (Message $m): string => $m->content,
            \array_slice($host->history(), 2),
        ));
    }

    /**
     * A host whose command files come from the workspace loader — the shape
     * SessionHub::open() builds and the `customCommands: []` shortcuts of
     * sibling tests deliberately skip.
     */
    private function host(CommandLoader $loader, bool $trusted): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(
                root: $this->project,
                sessionStore: $this->store,
                backend: new EchoBackend(),
                commandLoader: $loader,
                projectCommandsTrusted: $trusted,
            ),
            history: [Message::user('hello'), Message::assistant('hi')],
        );
    }

    /**
     * `command.list` over the real dispatcher for a workspace built the way
     * Cli\Serve builds one — loader and grant included — and answer rows
     * keyed by command name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function listed(bool $trusted): array
    {
        $workspace = WorkspaceContext::new(
            root: $this->project,
            sessionStore: $this->store,
            commandLoader: new CommandLoader(),
            projectCommandsTrusted: $trusted,
        );
        $hub = SessionHub::new($workspace);
        $context = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->project), 'test', Loop::get());
        $dispatcher = Dispatcher::new($context);
        $client = WireClient::open($dispatcher, 'trust-client');
        $client->hello();

        $items = (array) $client->call('command.list', ['sessionId' => 's'])['items'];

        $dispatcher->stop('test over');
        $hub->closeAll();
        $settle = Loop::addTimer(0.001, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($settle);

        return \array_column($items, null, 'name');
    }

    /** Run the shared loop until the host lets go of the session — bounded. */
    private function drain(SessionHost $host): void
    {
        if (!$host->isBusy()) {
            return;
        }

        $loop = Loop::get();
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
