<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server\Support;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Host\EventLog;
use SugarCraft\Crush\Host\SessionHub;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Host\TurnController;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Protocol\Dispatcher;
use SugarCraft\Crush\Protocol\ServerContext;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * A `sugarcrush.v1` server without a socket: a workspace over a temporary
 * session database and a {@see ScriptedTurnBackend}, the hub over it, and the
 * {@see Dispatcher} a {@see WireClient} talks to. Built the way
 * `Cli\Serve::protocol()` builds the real one, minus the launch's config
 * reading. {@see tearDown()} stops the dispatcher's timers, releases every
 * session lock and removes the directory.
 */
final class ProtocolFixture
{
    public readonly string $dir;

    /** The project root the workspace serves (a directory inside {@see $dir}). */
    public readonly string $root;

    public readonly EnhancedSessionStore $store;

    public readonly ScriptedTurnBackend $backend;

    public readonly SessionHub $hub;

    public readonly ServerContext $context;

    public readonly Dispatcher $dispatcher;

    private function __construct(?ServerConfig $config, int $retain)
    {
        $this->dir = \sys_get_temp_dir() . '/crush-protocol-' . \bin2hex(\random_bytes(6));
        $this->root = $this->dir . '/project';
        \mkdir($this->root, 0o700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->backend = new ScriptedTurnBackend();

        $transcripts = TranscriptStore::new($this->store, null, EventLog::new($this->store, $retain));
        $workspace = WorkspaceContext::new(root: $this->root, sessionStore: $this->store, backend: $this->backend)
            ->withService(TurnRunner::class, TurnRunner::new())
            ->withService(TurnController::class, TurnController::new())
            ->withService(TranscriptStore::class, $transcripts)
            ->withService(EventLog::class, $transcripts->events() ?? EventLog::new($this->store))
            ->withService(SettingsWriter::class, SettingsWriter::new(
                $this->dir . '/config.json',
                function (array $set, array $unset): void {
                    $path = $this->dir . '/config.json';
                    $current = \is_file($path) ? (array) \json_decode((string) \file_get_contents($path), true) : [];
                    $next = SettingsWriter::patched($current, $set, $unset);
                    \file_put_contents($path, (string) \json_encode($next === [] ? new \stdClass() : $next));
                },
            ));

        $config ??= ServerConfig::new($this->dir);
        $this->hub = SessionHub::new($workspace, $config->maxOpenSessions);
        $this->context = ServerContext::new($this->hub, $config->withRoot($this->root), 'test', Loop::get());
        $this->dispatcher = Dispatcher::new($this->context);
    }

    /** @param int $retain events kept per session (EventLog retention) */
    public static function new(?ServerConfig $config = null, int $retain = EventLog::DEFAULT_RETAIN): self
    {
        return new self($config, $retain);
    }

    /** A client that has said hello. */
    public function client(string $id = 'c1'): WireClient
    {
        $client = WireClient::open($this->dispatcher, $id);
        $client->hello();

        return $client;
    }

    /** A new session, opened; returns its id. */
    public function session(WireClient $client, array $params = []): string
    {
        return (string) $client->call('session.create', $params)['id'];
    }

    /**
     * Run the loop for $seconds: future ticks (subscribe catch-up, replay
     * pages) and the dispatcher's host tick get their turn.
     */
    public function run(float $seconds = 0.02): void
    {
        $timer = Loop::addTimer($seconds, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }

    /**
     * Run the loop until $promise settles (or $timeout passes) and return its
     * value or its rejection.
     */
    public function await(PromiseInterface $promise, float $timeout = 5.0): mixed
    {
        $done = false;
        $result = null;
        $promise->then(
            static function (mixed $value) use (&$done, &$result): void {
                $done = true;
                $result = $value;
                Loop::stop();
            },
            static function (\Throwable $error) use (&$done, &$result): void {
                $done = true;
                $result = $error;
                Loop::stop();
            },
        );
        if (!$done) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        return $done ? $result : new \RuntimeException('nothing answered within ' . $timeout . ' s');
    }

    public function tearDown(): void
    {
        $this->dispatcher->stop('test over');
        $this->hub->closeAll();
        // Let the stop's future ticks run, and hand the shared loop back empty.
        $this->run(0.001);
        self::removeTree($this->dir);
    }

    public static function removeTree(string $path): void
    {
        if ($path === '' || (!\file_exists($path) && !\is_link($path))) {
            return;
        }
        if (\is_dir($path) && !\is_link($path)) {
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            @\rmdir($path);

            return;
        }
        @\unlink($path);
    }
}
