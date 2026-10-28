<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\App\BackgroundTickMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Background\BackgroundSession;
use SugarCraft\Code\Background\BackgroundSessionStatus;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleSpawner;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;

/**
 * C-AGT-5.c: the heartbeat kind's silence contract at the pump.
 *
 * A settled fire tagged {@see Schedule::HEARTBEAT_TAG} whose answer is
 * exactly {@see Schedule::HEARTBEAT_OK} found nothing: it keeps the ordinary
 * status notice but queues NO announcement turn and rings nothing. Any other
 * settled answer under that tag wants a human: the result is announced like
 * every other background answer AND rings the `notify` setting — through the
 * same terminalNotification() channel the turn-end bell uses, gated the same
 * way (no second bell while a turn is mid-flight). Task-kind rows are
 * untouched: the gate is the tag, not the words.
 *
 * The pair's spawn-side vocabulary (the appended instruction, the tag) is
 * pinned here too, because this pump reads exactly what those produce — a
 * drift on either side is THIS contract breaking, not a detail moving.
 *
 * Sessions are seeded into a real supervisor and driven through the real
 * update()/BackgroundTickMsg entry, the shape
 * BackgroundResultAnnouncedTest established for this pump.
 */
final class HeartbeatScheduleTest extends TestCase
{
    private string|false $suggestions = false;

    protected function setUp(): void
    {
        // One ring per settle at most, so the raw-byte assertions stay exact.
        $this->suggestions = getenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS');
        putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS=1');
    }

    protected function tearDown(): void
    {
        $this->suggestions === false
            ? putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS')
            : putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS=' . $this->suggestions);
    }

    // =====================================================================
    // The spawn-side vocabulary the pump reads
    // =====================================================================

    public function testAHeartbeatFireCarriesTheOkInstructionAndTheTag(): void
    {
        $row = (new Schedule(name: 'watch', everySeconds: 60, prompt: 'check the build'))->withKind(Schedule::KIND_HEARTBEAT);

        $this->assertSame(
            'check the build When nothing needs attention, reply exactly HEARTBEAT_OK.',
            ScheduleSpawner::taskFor($row),
            'the instruction rides the SPAWN, spelled verbatim',
        );
        $this->assertSame(['schedule', 'schedule:watch', Schedule::HEARTBEAT_TAG], ScheduleSpawner::tagsFor($row));

        // A task row keeps both halves exactly as they were (and the stored
        // row's own prompt never grows the suffix, either kind).
        $plain = new Schedule(name: 'build', everySeconds: 60, prompt: 'run the tests');
        $this->assertSame('run the tests', ScheduleSpawner::taskFor($plain));
        $this->assertSame(['schedule', 'schedule:build'], ScheduleSpawner::tagsFor($plain));
        $this->assertSame('check the build', $row->prompt, 'the suffix is never written into the row');
    }

    public function testTheTagIsWhatMakesASessionAHeartbeat(): void
    {
        $this->assertTrue(ScheduleSpawner::isHeartbeat($this->marked('h1', BackgroundSessionStatus::Completed, Schedule::HEARTBEAT_OK)));
        $this->assertFalse(ScheduleSpawner::isHeartbeat($this->marked('h2', BackgroundSessionStatus::Completed, Schedule::HEARTBEAT_OK, heartbeat: false)));
        $this->assertFalse(ScheduleSpawner::isHeartbeat($this->marked('h3', BackgroundSessionStatus::Running, '', heartbeat: false)), 'untagged is unambiguous');
    }

    // =====================================================================
    // Silence: the OK answer adds nothing
    // =====================================================================

    public function testTheOkAnswerQueuesNoTurnAndRingsNothing(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = $this->primedHeartbeat($supervisor, Schedule::HEARTBEAT_OK . "\n");

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertSame([], $next->queuedPrompts(), 'silence is not a turn');
        $this->assertFalse($next->inFlight, 'and it starts no turn');
        $this->assertSame([], $this->ringBytes($cmd), 'silence rings nothing, in any notify mode');
        $this->assertNull($cmd, 'no Cmd at all leaves this settle');

        // The status transition still reads — silence is about the ANSWER.
        $contents = array_map(static fn (Message $m): string => $m->content, $next->history);
        $this->assertContains("Background session h1 ('schedule:watch') is now completed.", $contents);

        // And no user-role announcement row was appended.
        foreach ($next->history as $row) {
            $this->assertFalse(
                $row->role === Role::User && str_starts_with($row->content, '[Background session'),
                'the heartbeat answer stays out of the transcript: ' . $row->content,
            );
        }
    }

    // =====================================================================
    // The alert: anything else announces AND rings
    // =====================================================================

    public function testAnAnswerWithContentAnnouncesAndRingsTheOscNine(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = $this->primedHeartbeat($supervisor, "the nightly failed at step 3\n");

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertSame([], $next->queuedPrompts(), 'an idle chat dispatches at once');
        $this->assertTrue($next->inFlight, 'the announcement starts its turn');
        $last = $next->history[array_key_last($next->history)];
        $this->assertStringStartsWith("[Background session h1 ('schedule:watch') completed]", $last->content);
        $this->assertStringContainsString('the nightly failed at step 3', $last->content);

        $this->assertSame(
            ["\x1b]9;" . $this->alertLine('schedule:watch') . "\x07"],
            $this->ringBytes($cmd),
            'the alert rings the configured OSC 9 beside the dispatched turn',
        );
    }

    public function testAHeartbeatThatNeverAnsweredIsAnAlertToo(): void
    {
        // Failed / Stopped / TimedOut settle WITHOUT an OK, and must not be
        // misread as "nothing to report": a check that died is exactly what
        // the human needs the bell for.
        foreach ([BackgroundSessionStatus::Failed, BackgroundSessionStatus::TimedOut, BackgroundSessionStatus::Stopped] as $dead) {
            $supervisor = new BackgroundSupervisor();
            $chat = $this->primedHeartbeat($supervisor, Schedule::HEARTBEAT_OK, settleAs: $dead);

            [$next, $cmd] = $chat->update(new BackgroundTickMsg());

            $this->assertTrue($next->inFlight, $dead->value . ' must dispatch an announcement');
            $this->assertSame(
                ["\x1b]9;" . $this->alertLine('schedule:watch') . "\x07"],
                $this->ringBytes($cmd),
                $dead->value . ' rings even holding an OK-shaped buffer',
            );
        }
    }

    public function testTheAlertRingsOnlyBellWhenNoTurnIsInFlight(): void
    {
        $supervisor = new BackgroundSupervisor();
        $running = $this->marked('h1', BackgroundSessionStatus::Running, heartbeat: true);
        $supervisor->addSession($running);
        $chat = $this->midTurnChat($supervisor);
        [$primed] = $chat->update(new BackgroundTickMsg());

        $supervisor->addSession($this->marked('h1', BackgroundSessionStatus::Completed, 'disk at 97%', heartbeat: true));

        [$next, $cmd] = $primed->update(new BackgroundTickMsg());

        $this->assertCount(1, $next->queuedPrompts(), 'mid-flight, the announcement queues like every other result');
        $this->assertStringContainsString('disk at 97%', (string) $next->queuedPrompts()[0]);
        $this->assertStringContainsString(
            Lang::t('chat.background.after_turn.one'),
            (string) $next->history[array_key_last($next->history)]->content,
            'the after-turn note reads, exactly as it does for task rows',
        );
        $this->assertSame([], $this->ringBytes($cmd), 'the running turn rings nothing extra — its own end-notify speaks');
    }

    public function testBellModeRingsThePlainBellForAnAlert(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = $this->primedHeartbeat($supervisor, 'attention wanted', notify: 'bell');

        [, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertSame(["\x07"], $this->ringBytes($cmd));
    }

    public function testOffModeAnnouncesWithoutRinging(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = $this->primedHeartbeat($supervisor, 'attention wanted', notify: 'off');

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertTrue($next->inFlight, 'off still announces — the transcript is not the bell');
        $this->assertSame([], $this->ringBytes($cmd), 'off rings nothing');
    }

    // =====================================================================
    // The gate is the tag, not the words
    // =====================================================================

    public function testATaskRowAnsweringTheOkWordStillAnnouncesAsAlways(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = $this->primedTask($supervisor, Schedule::HEARTBEAT_OK);

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertTrue($next->inFlight, 'a task that happens to answer the word behaves like a task');
        $last = $next->history[array_key_last($next->history)];
        $this->assertStringStartsWith("[Background session t1 (", $last->content);
        $this->assertSame([], $this->ringBytes($cmd), 'and the bell stays a heartbeat privilege');
    }

    // =====================================================================
    // Harness
    // =====================================================================

    private function heartbeatAgent(): Agent
    {
        return new Agent(
            name: 'hb-agent',
            description: 'Heartbeat agent',
            prompt: '',
            model: 'test-model',
            provider: 'test',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    /**
     * A schedule fire as the spawner would have written it: the session name
     * from {@see ScheduleSpawner::sessionName()}, the ordinary tag pair, plus
     * the heartbeat mark when the row carried that kind.
     */
    private function marked(
        string $id,
        BackgroundSessionStatus $status,
        string $output = '',
        bool $heartbeat = true,
    ): BackgroundSession {
        $tags = ['schedule', 'schedule:watch'];
        if ($heartbeat) {
            $tags[] = Schedule::HEARTBEAT_TAG;
        }

        return (new BackgroundSession(
            id: $id,
            name: 'schedule:watch',
            agent: $this->heartbeatAgent(),
            task: 'check the build',
            workingDirectory: '/tmp',
            tags: $tags,
        ))->withOutput($output)->withStatus($status);
    }

    /** A chat that has already seen $id run, at $notify (osc9 default — the ring test wants the channel open). */
    private function primedHeartbeat(
        BackgroundSupervisor $supervisor,
        string $answer,
        string $notify = 'osc9',
        ?BackgroundSessionStatus $settleAs = null,
    ): Chat {
        $supervisor->addSession($this->marked('h1', BackgroundSessionStatus::Running, ''));
        [$chat] = (new Chat(
            backend: new EchoBackend(),
            backgroundSupervisor: $supervisor,
            workspace: WorkspaceContext::new(root: sys_get_temp_dir(), userConfig: ['notify' => $notify]),
        ))->update(new BackgroundTickMsg());
        $supervisor->addSession($this->marked('h1', $settleAs ?? BackgroundSessionStatus::Completed, $answer));

        return $chat;
    }

    /** The same prime for an untagged (task-kind) fire, under the name the pump dispatches. */
    private function primedTask(BackgroundSupervisor $supervisor, string $answer): Chat
    {
        $supervisor->addSession($this->marked('t1', BackgroundSessionStatus::Running, '', heartbeat: false));
        [$chat] = (new Chat(
            backend: new EchoBackend(),
            backgroundSupervisor: $supervisor,
            workspace: WorkspaceContext::new(root: sys_get_temp_dir(), userConfig: ['notify' => 'osc9']),
        ))->update(new BackgroundTickMsg());
        $supervisor->addSession($this->marked('t1', BackgroundSessionStatus::Completed, $answer, heartbeat: false));

        return $chat;
    }

    /** A chat mid-turn, with $id already announced as running by a prior tick. */
    private function midTurnChat(BackgroundSupervisor $supervisor): Chat
    {
        return new Chat(
            history: [Message::user('go')],
            inFlight: true,
            backend: new EchoBackend(),
            backgroundSupervisor: $supervisor,
            workspace: WorkspaceContext::new(root: sys_get_temp_dir(), userConfig: ['notify' => 'osc9']),
        );
    }

    /** The exact bell text, derived rather than re-spelled so the pin cannot rot with the wording. */
    private function alertLine(string $name): string
    {
        return 'candy-code: ' . Lang::t('chat.schedule.heartbeat.alert', ['name' => $name]);
    }

    /**
     * Every RawMsg's bytes the settle's Cmd produces, batches opened — the
     * read shape TurnEndNotificationTest established, named for what it
     * collects here.
     *
     * @return list<string>
     */
    private function ringBytes(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }
        $msg = $cmd();
        if ($msg instanceof BatchMsg) {
            $out = [];
            foreach ($msg->cmds as $inner) {
                $out = [...$out, ...$this->ringBytes($inner)];
            }

            return $out;
        }

        return $msg instanceof RawMsg ? [$msg->bytes] : [];
    }
}
