<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Host\Commands\BtwHostCommand;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * C-WEB-2.a: the `command.exec` door admits the same mid-turn built-ins the
 * keyboard door does. `runCommand()` refused every command while a turn held
 * the session except `/workflow pause|status` inside their own run, so the
 * web client's `/btw` over `command.exec` was refused even though the host's
 * own submit path runs it beside the turn (roadmap 5.14b). One decision
 * table, two doors: both now consult {@see TurnController::midTurnRoute()}.
 *
 * The regression that matters is the whole life of the side question over
 * this door: it must answer while the turn runs, hold nothing it does not
 * already hold, and its UI-only rows must survive the running turn's settle
 * and save — the settle appends to the history the command already wrote to.
 */
final class BtwMidTurnTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-btw-midturn-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    public function testTheCommandDoorAnswersSideQuestionWhileTurnRuns(): void
    {
        $turn = self::pending();
        $title = self::titleModel('We decided to ship the door.');
        $host = $this->host(backend: $turn, titleBackend: $title);

        $host->submit('start a turn');
        self::assertTrue($host->isBusy(), 'the turn holds the session');

        $result = $host->runCommand('btw', 'what did we decide?');

        self::assertFalse($result->isRefused(), 'the exec door admits the side question the keyboard door admits');
        self::assertTrue($host->isBusy(), 'answering beside the turn releases nothing the turn holds');

        $rows = $host->history();
        $questionRow = $rows[\count($rows) - 1];
        self::assertSame('/btw what did we decide?', $questionRow->content);
        self::assertTrue($questionRow->uiOnly, 'the side question never rides the running turn');

        // The side call went to the title model, framed as every side call is.
        self::assertSame(BtwHostCommand::PROMPT, $title->sent[0]->content);

        self::drain($host, static fn (SessionHost $h): bool => self::hasRow($h, 'We decided to ship the door.'));

        // The turn finishes AFTER the answer landed; its settle must not
        // swallow the UI-only rows the command appended mid-turn.
        $turn->settle(Message::assistant('here is the turn reply'));
        self::drain($host);

        self::assertTrue(self::hasRow($host, '/btw what did we decide?'), 'the question row survives the settle');
        self::assertTrue(self::hasRow($host, 'We decided to ship the door.'), 'the answer row survives the settle');
        self::assertTrue(self::hasRow($host, 'here is the turn reply'), 'and so does the turn itself');
        $answerRow = self::rowWith($host, 'We decided to ship the door.');
        self::assertTrue($answerRow->uiOnly, 'the answer stays out of the model history as on the keyboard door');

        $saved = array_map(
            static fn (array $m): string => (string) $m['content'],
            $this->store->loadTranscript('s') ?? [],
        );
        self::assertContains('/btw what did we decide?', $saved);
        self::assertContains('here is the turn reply', $saved);
        self::assertTrue(
            self::anyContains($saved, 'We decided to ship the door.'),
            'one save, two doors: nothing is lost by settle or teardown',
        );
    }

    public function testTheCommandDoorStillRefusesOtherBuiltInsWhileTurnRuns(): void
    {
        $turn = self::pending();
        $host = $this->host(backend: $turn);

        $host->submit('start a turn');
        $before = $host->history();

        $result = $host->runCommand('clear');

        self::assertTrue($result->isRefused());
        self::assertStringContainsString('commands do not run while a turn is in flight', (string) $result->error);
        self::assertSame($before, $host->history(), 'the refused command wrote nothing');
        self::assertTrue($host->isBusy(), 'the refusal leaves the turn holding the session');
    }

    /** A backend whose turn never answers until the test says settle(). */
    private static function pending(): object
    {
        return new class () implements Backend {
            private ?Deferred $deferred = null;

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->deferred = new Deferred();

                return $this->deferred->promise();
            }

            public function settle(Message $reply): void
            {
                $deferred = $this->deferred;
                $this->deferred = null;
                $deferred?->resolve($reply);
            }
        };
    }

    /** The title model as a backend: answers at once, records what it was asked. */
    private static function titleModel(string $answer): object
    {
        return new class ($answer) implements Backend {
            /** @var list<Message> the system framing of each side call */
            public array $sent = [];

            public function __construct(private readonly string $answer)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->answer);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->sent[] = $history[0];

                return \React\Promise\resolve(Message::assistant($this->answer));
            }
        };
    }

    private static function hasRow(SessionHost $host, string $needle): bool
    {
        foreach ($host->history() as $row) {
            if (\str_contains($row->content, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $rows
     */
    private static function anyContains(array $rows, string $needle): bool
    {
        foreach ($rows as $row) {
            if (\str_contains($row, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function rowWith(SessionHost $host, string $needle): Message
    {
        foreach ($host->history() as $row) {
            if (\str_contains($row->content, $needle)) {
                return $row;
            }
        }

        self::fail('no row carries ' . $needle);
    }

    /**
     * Run the shared loop until $done says so — bounded — the house shape of
     * the other host tests: the async answer lands on the loop, so a test
     * that only asserts without pumping can never see it.
     *
     * @param (\Closure(SessionHost): bool)|null $done
     */
    private static function drain(SessionHost $host, ?\Closure $done = null): void
    {
        $done ??= static fn (SessionHost $h): bool => !$h->isBusy();
        $loop = Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop, $done): void {
            if ($done($host)) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);

        self::assertTrue($done($host), 'the off-loop work settled');
    }

    private function host(?Backend $backend = null, ?Backend $titleBackend = null): SessionHost
    {
        $root = $this->dir . '/project';
        if (!is_dir($root)) {
            mkdir($root, 0700);
        }

        return SessionHost::new(
            's',
            WorkspaceContext::new(
                root: $root,
                sessionStore: $this->store,
                backend: $backend ?? new Backend\EchoBackend(),
                titleBackend: $titleBackend,
            ),
        );
    }
}
