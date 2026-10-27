<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\PromptSuggestionMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Host\TurnSettleWork;
use SugarCraft\Code\Memory\AutoMemoryConsolidator;
use SugarCraft\Code\Memory\CompactionJournal;
use SugarCraft\Code\Memory\DreamPass;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Workspace\AutoCommitter;

use function React\Promise\resolve;

/**
 * Pins for the settle-time decision table (plan_comparison C-CTX-2.b): one
 * call to {@see TurnSettleWork::plan()} under a carried-on flag, a live-goal
 * flag, a memory-store presence, a spend-cap flag and the auto-commit
 * setting answers with exactly the gates {@see
 * \SugarCraft\Code\Chat\Handlers\HandlesTurnCompletion} applies inline
 * today — until step C-CTX-2.e folds the Chat side onto this table, these
 * pins are what prove the two copies cannot drift silently. The plan is
 * dormant: no host calls it yet, and a flip that makes it wrong would still
 * have to break one of these pins first.
 */
final class TurnSettleWorkTest extends TestCase
{
    use CheckpointedRepoTrait;

    private string $scratch;

    private int $homeCounter = 0;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
        $real = realpath(sys_get_temp_dir());
        self::assertIsString($real);
        $this->scratch = $real . '/cc_tsw_' . bin2hex(random_bytes(6));
        self::assertIsBool(mkdir($this->scratch, 0700, true));
        // Both schedulers throttle per project directory, not per session,
        // and the checkpoint trait's HOME sandbox already isolates the
        // throttle state files; clear any ambient env opt-outs so the
        // gates answer on the flags alone. The dream pass shares the
        // auto-memory opt-out, so one env name covers both schedulers.
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        // One captured checkpoint for the session: every expected
        // auto-commit call in these pins rides this snapshot and the
        // turn's own writes land after it, exactly like a settled turn.
        // (The trait asserts the capture itself; index 0 is a valid first
        // checkpoint, so no magnitude claim belongs here.)
        $this->turnCheckpoint([['role' => 'user', 'content' => 'ship it']], 'ship it');
    }

    protected function tearDown(): void
    {
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        $this->tearDownCheckpointedRepo();
        self::rmrf($this->scratch);
    }

    public function testACarriedOnTurnPlansNoWorkAtAll(): void
    {
        $work = $this->settleWith(['carriedOn' => true]);
        self::assertNull($work->suggestion);
        self::assertNull($work->consolidation);
        self::assertNull($work->autoCommit);
        self::assertNull($work->dream);
    }

    public function testAGoalLiveTurnSchedulesNoSuggestionButKeepsTheRest(): void
    {
        $work = $this->settleWith(['goalActive' => true]);
        self::assertNull($work->suggestion);
        self::assertNotNull($work->consolidation);
        self::assertNotNull($work->autoCommit);
        self::assertNotNull($work->dream);
    }

    public function testNoMemoryStoreSchedulesNoConsolidationOrDream(): void
    {
        $work = $this->settleWith(['memoryStore' => null]);
        self::assertNotNull($work->suggestion);
        self::assertNull($work->consolidation);
        self::assertNotNull($work->autoCommit);
        self::assertNull($work->dream);
    }

    public function testACapReachedTurnSchedulesNoSuggestion(): void
    {
        $work = $this->settleWith(['capReached' => true]);
        self::assertNull($work->suggestion);
        self::assertNotNull($work->autoCommit);
    }

    public function testAnAutoCommitSettingOtherThanTurnSchedulesNoCommit(): void
    {
        foreach ([[], ['autoCommit' => AutoCommitter::MODE_EDIT]] as $config) {
            $work = $this->settleWith(['userConfig' => $config]);
            self::assertNull($work->autoCommit);
            self::assertNotNull($work->suggestion);
        }
    }

    public function testTheFullTablePlansFourCallsAndTheSuggestionResolvesAPromptSuggestion(): void
    {
        $work = $this->settleWith([]);
        self::assertNotNull($work->suggestion);
        self::assertNotNull($work->consolidation);
        self::assertNotNull($work->autoCommit);
        self::assertNotNull($work->dream);

        // Executing only the suggestion call keeps the pin in-process:
        // it rides the synchronous fake title backend and resolves the
        // host message the Chat path would fold back into the model.
        $settled = null;
        self::assertNotNull($work->suggestion);
        ($work->suggestion)()->then(static function (mixed $msg) use (&$settled): void {
            $settled = $msg;
        });
        self::assertInstanceOf(PromptSuggestionMsg::class, $settled);
    }

    /**
     * The baseline table answers every gate OPEN — each pin flips exactly
     * one discriminator and asserts the gate that flag owns.
     *
     * @param array<string, mixed> $changes
     */
    private function settleWith(array $changes): TurnSettleWork
    {
        $read = static fn (string $key, mixed $default): mixed
            => array_key_exists($key, $changes) ? $changes[$key] : $default;

        $memoryStore = $read('memoryStore', $this->freshMemoryStore());
        $engine = $read('engineBackend', $this->dreamEngine());

        return TurnSettleWork::plan(
            carriedOn: (bool) $read('carriedOn', false),
            goalActive: (bool) $read('goalActive', false),
            memoryStore: $memoryStore,
            engineBackend: $engine,
            summaryBackend: $read('summaryBackend', $this->turnTitleBackend()),
            titleBackend: $read('titleBackend', $this->turnTitleBackend()),
            history: $read('history', $this->settledHistory()),
            capReached: (bool) $read('capReached', false),
            sessionId: (string) $read('sessionId', 'undo-session'),
            generation: (int) $read('generation', 1),
            projectRoot: (string) $read('projectRoot', $this->repo),
            userConfig: (array) $read('userConfig', [AutoCommitter::SETTINGS_KEY => AutoCommitter::MODE_TURN]),
            sessionStore: $read('sessionStore', $this->store),
        );
    }

    /**
     * A memory store in a directory no earlier plan call has throttled
     * against: the consolidator and the dream pass claim their five-minute
     * and two-hour windows synchronously at plan time, so every pin that
     * expects a live call needs a home of its own.
     */
    private function freshMemoryStore(): MemoryStore
    {
        ++$this->homeCounter;
        $dir = $this->scratch . '/h' . $this->homeCounter;
        self::assertIsBool(mkdir($dir, 0700, true));
        $store = new MemoryStore($dir);
        // The dream pass fires only with compaction records to summarise.
        self::assertTrue(CompactionJournal::forStore($store)->append('c1', ['summary' => 'ran out of context'], ['manual']));
        return $store;
    }

    private function dreamEngine(): EngineBackend
    {
        ++$this->homeCounter;
        $dir = $this->scratch . '/engine' . $this->homeCounter;
        self::assertIsBool(mkdir($dir, 0700, true));
        $provider = new ScriptedProvider([new CompleteResponse(content: '{"operations":[]}')]);
        return EngineBackend::new($provider, 'm')->withRoot($dir);
    }

    /**
     * An assistant-terminated conversation past the consolidator's input
     * floor, so the settle shape these pins mirror is a turn that actually
     * ended with a reply.
     *
     * @return list<Message>
     */
    private function settledHistory(): array
    {
        // Six repeats each: the excerpt carries 'User: '/'Assistant: '
        // prefixes and must clear the consolidator's 400-byte input floor.
        $user = str_repeat('Please keep this in mind for later sessions. ', 6);
        $reply = str_repeat('I will remember the decision and its reason. ', 6);
        return [Message::user($user), Message::assistant($reply)];
    }

    /**
     * A synchronous title/summary backend: completeAsync resolves the same
     * reply immediately, so a settled plan that accepts the call needs no
     * loop for the pins that execute it. Named to sit clear of every other
     * suite's fake-builder helper under the duplication drift guard.
     */
    private function turnTitleBackend(): Backend
    {
        return new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('suggested next prompt');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return resolve(Message::assistant('suggested next prompt'));
            }
        };
    }
}
