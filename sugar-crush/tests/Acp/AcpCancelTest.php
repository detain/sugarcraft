<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Acp;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Acp\AcpPermissionBridge;
use SugarCraft\Crush\Acp\StopReasonMap;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Tests\Acp\Support\AcpHarness;

/**
 * Roadmap 5.9-2: `session/cancel` stops the turn AT ONCE — through the
 * session host's hard cancel, not at the next step boundary — and the prompt
 * is answered `cancelled` straight away, its running tool rows healed and its
 * open questions settled.
 */
final class AcpCancelTest extends TestCase
{
    private AcpHarness $acp;

    private string $sessionId;

    protected function setUp(): void
    {
        $this->acp = new AcpHarness();
        $this->sessionId = $this->acp->open();
    }

    protected function tearDown(): void
    {
        $this->acp->dispose();
    }

    public function testCancelStopsTheTurnAndAnswersThePromptCancelled(): void
    {
        $id = $this->prompt('run the tests');
        $this->acp->backend->emit(new ToolStarted('c1', 'Bash', ['command' => 'phpunit']));
        $this->acp->server->tick();

        $this->acp->notify('session/cancel', ['sessionId' => $this->sessionId]);

        self::assertTrue($this->acp->backend->cancellation()?->isCancelled(), 'the turn\'s child was told to stop now');
        self::assertSame(['stopReason' => StopReasonMap::CANCELLED], $this->acp->response($id)['result'], 'answered at once, no tick needed');
        $host = $this->acp->server->hub()?->get($this->sessionId);
        self::assertNotNull($host);
        self::assertFalse($host->isBusy());
        foreach ($host->history() as $row) {
            self::assertNull($row->pendingToolCallId, 'no running placeholder is left behind');
        }

        $this->acp->server->tick();
        $answers = array_filter($this->acp->messages(), static fn (array $m): bool => ($m['id'] ?? null) === $id);
        self::assertCount(1, $answers, 'the prompt is answered once');
    }

    public function testCancelSettlesAnOpenQuestionAndALateAnswerFindsNothing(): void
    {
        $this->prompt('delete it');
        $this->acp->backend->ask('c1', 'Bash', ['command' => 'rm x']);
        $this->acp->server->tick();
        $request = $this->acp->requests(AcpPermissionBridge::METHOD)[0];

        $this->acp->notify('session/cancel', ['sessionId' => $this->sessionId]);
        self::assertCount(1, $this->acp->backend->settled);
        self::assertTrue($this->acp->backend->settled[0]->cancelled, 'the question went with its turn');

        $this->acp->respond($request['id'], ['outcome' => ['outcome' => 'cancelled']]);
        self::assertCount(1, $this->acp->backend->settled, 'the editor\'s cancelled answer settles nothing twice');
    }

    public function testTheSessionTakesTheNextPromptAfterACancel(): void
    {
        $this->prompt('first');
        $this->acp->notify('session/cancel', ['sessionId' => $this->sessionId]);

        $second = $this->prompt('second');
        $this->acp->backend->settle(Message::assistant('done'));
        $this->acp->server->tick();

        self::assertSame(['first', 'second'], $this->acp->backend->sent);
        self::assertSame(StopReasonMap::END_TURN, $this->acp->response($second)['result']['stopReason']);
    }

    public function testACancelWithNothingRunningOrForAnUnknownSessionIsHarmless(): void
    {
        $this->acp->notify('session/cancel', ['sessionId' => $this->sessionId]);
        $this->acp->notify('session/cancel', ['sessionId' => 'nope']);
        $this->acp->notify('session/whatever', []);

        self::assertCount(2, $this->acp->messages(), 'a notification is never answered: only initialize and session/new were');
    }

    private function prompt(string $text): int|string
    {
        return $this->acp->request('session/prompt', ['sessionId' => $this->sessionId, 'prompt' => [['type' => 'text', 'text' => $text]]]);
    }
}
