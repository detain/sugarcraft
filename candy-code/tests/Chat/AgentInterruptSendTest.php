<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Lang;
use SugarCraft\Code\App\AgentControlMsg;
use SugarCraft\Code\App\AgentMessageSentMsg;
use SugarCraft\Code\Agents\Live\AgentInbox;
use SugarCraft\Code\Agents\Live\MessageMode;
use SugarCraft\Code\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Message;
use SugarCraft\Code\ToolCall;

/**
 * Roadmap P-AGENT.b gap 3: `Ctrl+X i` delivers the draft as a
 * MessageMode::Interrupt instead of the plain steer — the run skips the
 * calls its current step never started. The wire fact is the mailbox
 * line's `mode`; the dispatch route itself (AgentControlMsg::INTERRUPT
 * is a send verb, not a control verb) is proven by these calls reaching
 * submitToAgents at all.
 */
final class AgentInterruptSendTest extends TestCase
{
    private string $session;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->session = 'interrupt-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach ($this->logs as $log) {
            @unlink($log);
            @rmdir(\dirname($log));
        }
    }

    public function testTheInterruptVerbWritesAnInterruptLineToTheMailbox(): void
    {
        $chat = $this->chat('finish carefully before reporting');

        [, $cmd] = $chat->update(new AgentControlMsg(AgentControlMsg::INTERRUPT, ['run-2']));
        $this->assertNotNull($cmd, 'the verb routes to the send path, never to controlAgents');

        $inbox = AgentInbox::forSession($this->session);
        $this->assertNotNull($inbox);
        $mail = $inbox->drain('run-2');
        $this->assertCount(1, $mail);
        $this->assertSame('finish carefully before reporting', $mail[0]->text);
        $this->assertSame(MessageMode::Interrupt, $mail[0]->mode, 'the run reads it as the interrupt, skipping its unstarted calls');
        $this->assertTrue($mail[0]->isFromUser());
        $this->assertSame([], $inbox->drain('run-1'), 'and only the named run is interrupted');
    }

    public function testTheQueuedRowCarriesTheInterruptWordInItsDetail(): void
    {
        $chat = $this->chat('stop digging there');

        [, $cmd] = $chat->update(new AgentControlMsg(AgentControlMsg::INTERRUPT, ['run-2']));
        $this->assertNotNull($cmd);
        $answer = $cmd();
        $this->assertInstanceOf(AgentMessageSentMsg::class, $answer);
        $this->assertSame(AgentMessageSentMsg::QUEUED, $answer->status);
        $this->assertSame("\u{26A1} interrupt", $answer->detail, 'the view renders the detail as the row mark (P-AGENT.b)');
        $this->assertSame("\u{29D7} queued", $answer->badge(), 'the status itself stays queued: only the row wording differs');
    }

    public function testTheMessageVerbStillSteersUnchanged(): void
    {
        $chat = $this->chat('also check the cookie');

        [, $cmd] = $chat->update(new AgentControlMsg(AgentControlMsg::MESSAGE, ['run-2']));
        $this->assertNotNull($cmd);
        $answer = $cmd();
        $this->assertInstanceOf(AgentMessageSentMsg::class, $answer);
        $this->assertNull($answer->detail, 'a plain send carries no interrupt word');

        $inbox = AgentInbox::forSession($this->session);
        $this->assertNotNull($inbox);
        $mail = $inbox->drain('run-2');
        $this->assertCount(1, $mail);
        $this->assertSame(MessageMode::Steer, $mail[0]->mode, 'Enter keeps steering at the step boundary');
    }

    public function testAFinishedRunTakesTheInterruptAsAFollowUpNotAMailboxLine(): void
    {
        $chat = $this->chat('one more thing');
        $chat->agentLive()->apply(new SubAgentActivity(
            SubAgentActivity::OP_FINISHED,
            'run-2',
            'explore',
            'a task',
            2,
            '',
            parentCallId: 'call_1',
        ));

        [, $cmd] = $chat->update(new AgentControlMsg(AgentControlMsg::INTERRUPT, ['run-2']));
        $this->assertNotNull($cmd);
        $answer = $cmd();
        $this->assertInstanceOf(AgentMessageSentMsg::class, $answer);

        // The finished run has no mailbox to wake and no resume id to follow
        // up under, so the attempt surfaces the follow-up's own refusal —
        // which is exactly the proof that the interrupt took the follow-up
        // route instead of writing a line nobody will ever drain.
        $this->assertSame(AgentMessageSentMsg::FAILED, $answer->status);
        // Literal, not Lang::t: the catalogue is not bootstrapped at Chat
        // level; the src-side call inside Chat resolves for real.
        $this->assertSame('this run cannot be continued (it kept no resume id)', $answer->detail);

        $inbox = AgentInbox::forSession($this->session);
        $this->assertNotNull($inbox);
        $this->assertSame([], $inbox->drain('run-2'), 'nothing was parked in the dead run\'s mailbox');
    }

    private function chat(string $draft): Chat
    {
        $chat = (new Chat(
            history: [Message::user('audit everything'), Message::toolRunning(new ToolCall('Task', [], 'call_1'))],
            backend: new EchoBackend(),
            inFlight: true,
            generation: 1,
            inputBuf: $draft,
        ))->withCurrentSessionId($this->session);

        foreach (['run-1', 'run-2'] as $id) {
            $log = SubAgentTranscriptLog::forRun($this->session, $id);
            $this->logs[] = $log->path();
            $chat->agentLive()->apply(new SubAgentActivity(
                SubAgentActivity::OP_STARTED,
                $id,
                'explore',
                'a task',
                1,
                '',
                parentCallId: 'call_1',
                transcriptLog: $log->path(),
                parentSessionId: $this->session,
            ));
        }

        return $chat;
    }
}
