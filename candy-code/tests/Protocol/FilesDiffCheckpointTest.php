<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Host\Commands\Checkpoints;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\ScriptedTurnBackend;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * C-TUI-2.g: `files.diff` gains a checkpoint base.
 *
 * The web ChangesPanel's base selector needs exactly three promises from the
 * wire: a checkpoint diff resolves like `/diff n` resolves it on the terminal
 * (1 is the most recent captured base, counted newest-first through the same
 * `isCaptured` filter), asking past the oldest base settles on it instead of
 * refusing, and every refusal is a NAMED kind — a missing session, a bare
 * checkpoint with no store to read, or a session with no captured bases must
 * never silently fall back to a HEAD diff the user did not ask for. The HEAD
 * answer carries the base count so the selector can render without a second
 * round-trip.
 *
 * Harness shape is the {@see UsageMethodsTest} inline-construction precedent
 * on the {@see CheckpointedRepoTrait} fixture: a real git repo, a real store
 * whose checkpoints carry real workspace snapshots, and the diff taken over a
 * real {@see Dispatcher} whose root IS that repo — the same path
 * CheckpointDiff reads on the terminal side.
 */
final class FilesDiffCheckpointTest extends TestCase
{
    use CheckpointedRepoTrait;

    private SessionHub $diffHub;

    private ServerContext $diffContext;

    private Dispatcher $diffDispatcher;

    /** Every line the served context's log sink received — ISS-057's channel. */
    private array $diffLogLines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckpointedRepo();
        $this->serveCheckpointRepo($this->store);
    }

    protected function tearDown(): void
    {
        $this->diffDispatcher->stop('test over');
        $this->diffHub->closeAll();
        $this->tearDownCheckpointedRepo();

        parent::tearDown();
    }

    public function testTheHeadAnswerCountsTheCheckpointBasesForTheSelector(): void
    {
        $this->checkpointAfterEdit("one\n", 'first prompt');
        $this->checkpointAfterEdit("two\n", 'second prompt');
        \file_put_contents($this->repo . '/file.txt', "three\n");

        $answer = $this->diffClient()->call('files.diff', ['sessionId' => 'undo-session']);

        self::assertSame('HEAD', $answer['ref'], 'the plain answer stays a HEAD diff');
        self::assertSame(2, $answer['checkpoints'], 'the answer names how many bases the selector can offer');
        self::assertSame(0, $answer['omittedLines'], 'the git path never omits lines through a checkpoint bound');
        self::assertStringContainsString('-base', $answer['diff'], 'the HEAD diff still shows the worktree against HEAD');
    }

    public function testACheckpointResolvesTheSameBaseTheTerminalCounts(): void
    {
        $older = $this->checkpointAfterEdit("one\n", 'first prompt');
        $newer = $this->checkpointAfterEdit("two\n", 'second prompt');
        \file_put_contents($this->repo . '/file.txt', "three\n");

        $client = $this->diffClient();
        $nearest = $client->call('files.diff', ['sessionId' => 'undo-session', 'checkpoint' => 1]);
        self::assertSame('checkpoint:' . $newer, $nearest['ref'], 'base 1 is the most recent captured checkpoint');
        self::assertStringContainsString('-two', $nearest['diff'], 'the newest base shows what changed since that turn');
        self::assertStringNotContainsString('-one', $nearest['diff']);

        $second = $client->call('files.diff', ['sessionId' => 'undo-session', 'checkpoint' => 2]);
        self::assertSame('checkpoint:' . $older, $second['ref'], 'base 2 walks one further back, newest-first');
        self::assertStringContainsString('-one', $second['diff']);
        self::assertSame(2, $second['checkpoints']);
        self::assertFalse($second['truncated'], 'a two-line patch rides well inside the bound');
        self::assertSame(0, $second['omittedLines']);
    }

    public function testAskingPastTheOldestBaseSettlesOnTheOldest(): void
    {
        $older = $this->checkpointAfterEdit("one\n", 'first prompt');
        $this->checkpointAfterEdit("two\n", 'second prompt');
        \file_put_contents($this->repo . '/file.txt', "three\n");

        $answer = $this->diffClient()->call('files.diff', ['sessionId' => 'undo-session', 'checkpoint' => 99]);

        self::assertSame('checkpoint:' . $older, $answer['ref'], 'like DiffReviewSource::load(), the request clamps, it does not refuse');
        self::assertSame(2, $answer['checkpoints'], 'the answer reports the real base count, not the requested index');
    }

    public function testACheckpointWithoutASessionIsRefusedNotSilentlyHeaded(): void
    {
        $response = $this->diffClient()->request('files.diff', ['checkpoint' => 1]);

        self::assertSame('checkpoint_without_session', $response['error']['data']['kind'] ?? null);
        self::assertArrayNotHasKey('result', $response, 'a bare checkpoint must not answer with a HEAD diff');
    }

    public function testASessionWithoutCapturedBasesRefusesByItsOwnName(): void
    {
        $this->store->createSession('bare-session', 'echo', 'echo');

        $response = $this->diffClient()->request('files.diff', ['sessionId' => 'bare-session', 'checkpoint' => 1]);

        self::assertSame('no_checkpoints', $response['error']['data']['kind'] ?? null, 'no snapshots is its own refusal, distinct from every other');
    }

    public function testRefAndCheckpointRefuseToBeBothSupplied(): void
    {
        $this->checkpointAfterEdit("one\n", 'first prompt');

        $response = $this->diffClient()->request('files.diff', [
            'sessionId' => 'undo-session',
            'ref' => 'HEAD~1',
            'checkpoint' => 1,
        ]);

        self::assertSame('ref_with_checkpoint', $response['error']['data']['kind'] ?? null);
    }

    /**
     * MINOR-2: `no_store` is its own named refusal, pinned — without it a
     * store-less server would answer with a TypeError-rendered internal
     * error (guard removal reddens exactly this test).
     */
    public function testARequestAgainstAStorelessServerRefusesNoStoreByItsOwnName(): void
    {
        $workspace = WorkspaceContext::new(
            root: $this->repo,
            sessionStore: null,
            backend: new ScriptedTurnBackend(),
            backendFactory: static fn (string $provider): Backend => EngineBackend::new(new ScriptedProvider([]), 'stale-' . $provider),
        );
        $config = ServerConfig::new($this->tmp);
        $hub = SessionHub::new($workspace, $config->maxOpenSessions);
        $dispatcher = Dispatcher::new(ServerContext::new($hub, $config->withRoot($this->repo), 'test', Loop::get()));
        try {
            $client = WireClient::open($dispatcher, 'storeless-client');
            $client->hello();

            $response = $client->request('files.diff', ['sessionId' => 'undo-session', 'checkpoint' => 1]);
        } finally {
            $dispatcher->stop('test over');
            $hub->closeAll();
        }

        self::assertSame('no_store', $response['error']['data']['kind'] ?? null, 'a bare `checkpoint` against a server with no store refuses by name, not by crash');
        self::assertArrayNotHasKey('result', $response);
    }

    /**
     * MINOR-2 + MINOR-1: a checkpoint whose repository is gone refuses under
     * the `checkpoint_diff_failed` kind, the refusal carries a FIXED literal
     * (the reason can name server absolute paths, and the kind already
     * carries the machine meaning), and that reason reaches the server's log
     * sink instead of staying silent (the ISS-057 law the bg.spawn refusal
     * set).
     */
    public function testADiffThatCannotBeTakenRefusesByNameAndKeepsServerPathsInTheLog(): void
    {
        $this->checkpointAfterEdit("one\n", 'first prompt');

        // Corrupt the captured shape after capture: it still passes the
        // isCaptured gate (status, ref prefix, hex sha untouched), but its
        // repository no longer exists — the state an old session meets when
        // the checkout that snapshotted it is gone.
        $position = Checkpoints::fileCheckpointPositions($this->store, 'undo-session', 1000)[0];
        $workspace = $position['workspace'];
        $vanished = $this->tmp . '/gone/.git';
        $workspace['gitDir'] = $vanished;
        self::assertTrue($this->store->attachWorkspaceRef('undo-session', (int) $position['index'], $workspace));

        $response = $this->diffClient()->request('files.diff', ['sessionId' => 'undo-session', 'checkpoint' => 1]);

        self::assertSame('checkpoint_diff_failed', $response['error']['data']['kind'] ?? null);
        self::assertSame('the checkpoint diff is unavailable', $response['error']['message'] ?? null, 'a literal sentence, never the reason concatenated onto it');
        self::assertStringNotContainsString($this->tmp, (string) json_encode($response), 'no server absolute path rides the wire');
        $logged = implode("\n", $this->diffLogLines);
        self::assertStringContainsString('files.diff checkpoint diff failed', $logged, 'the refusal that hides a cause owes it to the server log');
        self::assertStringContainsString($vanished, $logged, 'the log line carries the path the wire must not');
    }

    public function testTheMethodIsStillAReadOnTheRoster(): void
    {
        $spec = Dispatcher::methods()->get('files.diff');

        self::assertNotNull($spec, 'files.diff is on the roster');
        self::assertSame(Scope::Read, $spec->scope, 'a checkpoint diff opens no write path');
        self::assertFalse($spec->sideEffects);
    }

    /**
     * Edit the work tree to $content, then save the checkpoint a turn sending
     * the next prompt would save — capturing the files AS EDITED, the way a
     * pre-turn checkpoint snapshots the state that turn started from.
     */
    private function checkpointAfterEdit(string $content, string $prompt): int
    {
        \file_put_contents($this->repo . '/file.txt', $content);

        return $this->turnCheckpoint([['role' => 'user', 'content' => 'work so far']], $prompt);
    }

    private function serveCheckpointRepo(EnhancedSessionStore $store): void
    {
        $workspace = WorkspaceContext::new(
            root: $this->repo,
            sessionStore: $store,
            backend: new ScriptedTurnBackend(),
            backendFactory: static fn (string $provider): Backend => EngineBackend::new(new ScriptedProvider([]), 'stale-' . $provider),
        );

        $config = ServerConfig::new($this->tmp);
        $this->diffHub = SessionHub::new($workspace, $config->maxOpenSessions);
        $this->diffContext = ServerContext::new(
            $this->diffHub,
            $config->withRoot($this->repo),
            'test',
            Loop::get(),
            log: function (string $line): void {
                $this->diffLogLines[] = $line;
            },
        );
        $this->diffDispatcher = Dispatcher::new($this->diffContext);
    }

    private function diffClient(): WireClient
    {
        $client = WireClient::open($this->diffDispatcher, 'diff-client');
        $client->hello();

        return $client;
    }
}
