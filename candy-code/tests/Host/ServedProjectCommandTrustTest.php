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
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
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
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
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

    // ── harness ────────────────────────────────────────────────────────

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
