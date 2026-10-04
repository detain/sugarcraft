<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\AgentControlMsg;
use SugarCraft\Crush\AgentMessageSentMsg;
use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Agents\Live\AgentMessage;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolCall;

/**
 * Roadmap P-D2: with an Agent View open, the input box is that agent's
 * composer — `Enter` puts the draft in the run's mailbox (signed as the
 * user's), idle or mid-turn, and never reaches the main model.
 */
final class AgentComposerTest extends TestCase
{
    private const COLS = 100;
    private const ROWS = 26;

    private string $session;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        Renderer::setAgentView(null);
        $this->session = 'composer-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        Renderer::setAgentView(null);
        foreach ($this->logs as $log) {
            @unlink($log);
            @rmdir(\dirname($log));
        }
    }

    public function testEnterSendsTheDraftToTheRunsMailboxNotTheMainModel(): void
    {
        $app = $this->typed($this->app(), 'also check the remember-me cookie')->openAgentView('run-2');

        [$sent, $cmd] = $app->update(new KeyMsg(KeyType::Enter));

        $this->assertSame('', $sent->chat?->inputBuf, 'the draft was taken');
        $this->assertTrue($sent->chat?->inFlight, 'the main turn runs on');
        $this->assertSame($app->chat?->history, $sent->chat?->history, 'and the main transcript is untouched: nothing was steered in');

        $inbox = AgentInbox::forSession($this->session);
        $this->assertNotNull($inbox);
        $mail = $inbox->drain('run-2');
        $this->assertCount(1, $mail);
        $this->assertSame('also check the remember-me cookie', $mail[0]->text);
        $this->assertTrue($mail[0]->isFromUser(), 'it carries the user\'s authority — the signature verified');
        $this->assertSame([], $inbox->drain('run-1'), 'and only that run got it');

        $this->assertInstanceOf(\Closure::class, $cmd);
        $answer = $cmd();
        $this->assertInstanceOf(AgentMessageSentMsg::class, $answer);
        $this->assertSame(AgentMessageSentMsg::QUEUED, $answer->status);
        $this->assertSame($mail[0]->msgId, $answer->msgId);
    }

    public function testTheViewShowsTheMessageQueuedUntilTheRunLogsItDelivered(): void
    {
        $app = $this->typed($this->app(), 'also check the cookie')->openAgentView('run-2');
        [$sent, $cmd] = $app->update(new KeyMsg(KeyType::Enter));
        $answer = $cmd();
        $this->assertInstanceOf(AgentMessageSentMsg::class, $answer);
        [$recorded] = $sent->update($answer);

        $frame = self::plain($recorded);
        $this->assertStringContainsString('you → explore · also check the cookie', $frame);
        $this->assertStringContainsString('⧗ queued', $frame);

        // The run reads it at its next step boundary and logs it.
        SubAgentTranscriptLog::at($this->logs[1])->append(SubAgentTranscriptLog::T_INBOX, [
            'msgId' => $answer->msgId,
            'from' => AgentMessage::FROM_USER,
            'mode' => 'steer',
            'text' => 'also check the cookie',
            'step' => 3,
        ]);
        [$ticked] = $recorded->update(new \SugarCraft\Crush\OpenAgentViewMsg('run-2'));
        $frame = self::plain($ticked);
        $this->assertStringNotContainsString('⧗ queued', $frame, 'the queued row gives way to the run\'s own');
        $this->assertStringContainsString('also check the cookie', $frame, 'which shows where it was read');
    }

    public function testAnEmptyBoxNamesTheAgentItSendsTo(): void
    {
        $open = $this->app()->openAgentView('run-2');
        $this->assertStringContainsString('message explore…', self::plain($open));

        $this->assertStringNotContainsString('message explore…', self::plain($this->app()), 'the main view\'s box is the main chat\'s');
    }

    public function testEveryPaintedLineFitsTheTerminal(): void
    {
        $app = $this->app()->openAgentView('run-2');
        [$narrow] = $app->update(new WindowSizeMsg(24, self::ROWS));
        $view = $narrow->view();
        foreach (explode("\n", Ansi::strip(\is_string($view) ? $view : $view->body)) as $line) {
            $this->assertLessThanOrEqual(24, Width::of(self::stripZones($line)));
        }
    }

    public function testACommandStillRunsAsACommand(): void
    {
        $app = $this->typed($this->app(), '/keys')->openAgentView('run-2');

        $this->assertNull($app->dispatchKey(new KeyMsg(KeyType::Enter)), 'the composer leaves a /command to the chat');
    }

    public function testAnEmptyDraftSendsNothing(): void
    {
        $app = $this->app()->openAgentView('run-2');

        $this->assertNull($app->dispatchKey(new KeyMsg(KeyType::Enter)));
    }

    public function testARunThisSessionDoesNotKnowKeepsTheDraft(): void
    {
        $app = $this->typed($this->app(), 'hello?')->openAgentView('no-such-run');

        [$kept, $cmd] = $app->update(new KeyMsg(KeyType::Enter));

        $this->assertSame('hello?', $kept->chat?->inputBuf, 'nothing was sent, so the draft stays for another try');
        $answer = $cmd();
        $this->assertInstanceOf(AgentMessageSentMsg::class, $answer);
        $this->assertSame(AgentMessageSentMsg::FAILED, $answer->status);
        $this->assertStringContainsString('✗', $answer->badge());
    }

    public function testWithoutASessionThereIsNoMailboxToWriteTo(): void
    {
        $chat = $this->app()->chat;
        $this->assertNotNull($chat);
        $sessionless = new Chat(history: $chat->history, backend: new EchoBackend(), inFlight: true, generation: 1, inputBuf: 'hi');
        $sessionless->agentLive()->apply(self::started('run-2', null));

        [, $cmd] = $sessionless->update(new AgentControlMsg(AgentControlMsg::MESSAGE, ['run-2']));
        $answer = $cmd();
        $this->assertInstanceOf(AgentMessageSentMsg::class, $answer);
        $this->assertSame(AgentMessageSentMsg::FAILED, $answer->status);
        $this->assertSame('this session keeps no agent mailboxes', $answer->detail);
    }

    private function app(): App
    {
        $chat = (new Chat(
            history: [Message::user('audit everything'), Message::toolRunning(new ToolCall('Task', [], 'call_1'))],
            backend: new EchoBackend(),
            inFlight: true,
            generation: 1,
        ))->withCurrentSessionId($this->session)->withSize(self::COLS, self::ROWS);

        foreach (['run-1', 'run-2'] as $id) {
            $log = SubAgentTranscriptLog::forRun($this->session, $id);
            $log->user('look at the session layer');
            $this->logs[] = $log->path();
            $chat->agentLive()->apply(self::started($id, $log->path()));
        }

        [$app] = App::new($this->createMock(ProviderInterface::class), 'm')
            ->withChat($chat)
            ->update(new WindowSizeMsg(self::COLS, self::ROWS));

        return $app;
    }

    private function typed(App $app, string $draft): App
    {
        $chat = $app->chat;
        $this->assertNotNull($chat);
        foreach (mb_str_split($draft) as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }
        $this->assertInstanceOf(Chat::class, $chat);

        return $app->withChat($chat);
    }

    private static function started(string $id, ?string $log): SubAgentActivity
    {
        return new SubAgentActivity(
            SubAgentActivity::OP_STARTED,
            $id,
            'explore',
            'a task',
            1,
            '',
            parentCallId: 'call_1',
            transcriptLog: $log,
        );
    }

    private static function plain(App $app): string
    {
        $view = $app->view();

        return self::stripZones(Ansi::strip(\is_string($view) ? $view : $view->body));
    }

    private static function stripZones(string $text): string
    {
        return (string) preg_replace('/\x{E000}[^\x{E001}]*\x{E001}|[\x{E000}-\x{F8FF}]/u', '', $text);
    }
}
