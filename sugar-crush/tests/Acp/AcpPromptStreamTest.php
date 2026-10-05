<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Acp;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Acp\AcpUpdateMapper;
use SugarCraft\Crush\Acp\StopReasonMap;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Tests\Acp\Support\AcpHarness;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap 5.9-1: a `session/prompt` runs a turn on the session's host and
 * streams it back as `session/update` notifications — the reply's chunks,
 * each tool call and its outcome — and is answered with its stop reason once
 * the session is idle, after everything the turn said.
 */
final class AcpPromptStreamTest extends TestCase
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

    public function testATurnStreamsItsReplyAndToolCallsThenAnswersTheprompt(): void
    {
        $id = $this->prompt([['type' => 'text', 'text' => 'read the readme']]);
        self::assertSame(['read the readme'], $this->acp->backend->sent);
        self::assertNull($this->acp->response($id), 'a running turn leaves the prompt open');

        $this->acp->backend->token('Let me ');
        $this->acp->backend->token('look.');
        $this->acp->backend->emit(new ToolStarted('c1', 'Read', ['file_path' => 'README.md', 'offset' => 3]));
        $this->acp->server->tick();
        $this->acp->backend->emit(new ToolFinished('c1', 'Read', new EngineToolResult('c1', "# Title\n")));
        $this->acp->server->tick();
        $this->acp->backend->settle(Message::assistant('Let me look. It is a title.'));
        $this->acp->server->tick();

        $updates = $this->acp->updates();
        self::assertSame('Let me look. It is a title.', $this->acp->chunks(), 'the settled reply adds only what the stream had not sent');

        $call = self::first($updates, AcpUpdateMapper::TOOL_CALL);
        self::assertSame('c1', $call['toolCallId']);
        self::assertSame('read', $call['kind']);
        self::assertSame(AcpUpdateMapper::STATUS_IN_PROGRESS, $call['status']);
        self::assertSame([['path' => $this->acp->root . '/README.md', 'line' => 3]], $call['locations']);
        self::assertSame(['file_path' => 'README.md', 'offset' => 3], $call['rawInput']);

        $done = self::first($updates, AcpUpdateMapper::TOOL_CALL_UPDATE);
        self::assertSame(AcpUpdateMapper::STATUS_COMPLETED, $done['status']);
        self::assertSame([['type' => 'content', 'content' => ['type' => 'text', 'text' => "# Title\n"]]], $done['content']);

        self::assertSame(['stopReason' => StopReasonMap::END_TURN], $this->acp->response($id)['result']);
        $last = $this->acp->messages()[\count($this->acp->messages()) - 1];
        self::assertSame($id, $last['id'] ?? null, 'the prompt is answered after every update the turn produced');
    }

    public function testAReplyThatNeverStreamedIsSentWholeWhenItSettles(): void
    {
        $id = $this->prompt([['type' => 'text', 'text' => 'hi']]);
        $this->acp->backend->settle(Message::assistant('hello there')->withReasoning('greet them'));
        $this->acp->server->tick();

        self::assertSame('hello there', $this->acp->chunks());
        self::assertSame('greet them', $this->acp->chunks(AcpUpdateMapper::AGENT_THOUGHT_CHUNK));
        self::assertSame(StopReasonMap::END_TURN, $this->acp->response($id)['result']['stopReason']);
    }

    public function testALengthStopIsMaxTokens(): void
    {
        $id = $this->prompt([['type' => 'text', 'text' => 'write a novel']]);
        $this->acp->backend->settle(Message::assistant('Once upon')->withLengthStopped(true));
        $this->acp->server->tick();

        self::assertSame(StopReasonMap::MAX_TOKENS, $this->acp->response($id)['result']['stopReason']);
    }

    public function testASecondPromptWhileOneRunsIsBusy(): void
    {
        $this->prompt([['type' => 'text', 'text' => 'first']]);
        $second = $this->prompt([['type' => 'text', 'text' => 'second']]);

        self::assertSame(ErrorCode::Busy->value, $this->acp->response($second)['error']['code']);
        self::assertSame(['first'], $this->acp->backend->sent, 'the busy prompt was neither sent nor queued');
    }

    public function testAPromptForAnUnknownSessionOrWithoutTextIsRefused(): void
    {
        $unknown = $this->acp->request('session/prompt', ['sessionId' => 'nope', 'prompt' => [['type' => 'text', 'text' => 'x']]]);
        $empty = $this->prompt([['type' => 'image', 'mimeType' => 'image/png', 'data' => '']]);

        self::assertSame(ErrorCode::NotFound->value, $this->acp->response($unknown)['error']['code']);
        self::assertSame(ErrorCode::InvalidParams->value, $this->acp->response($empty)['error']['code']);
        self::assertSame([], $this->acp->backend->sent);
    }

    public function testLinkedAndEmbeddedFilesBecomeAMentionAndAFileBlock(): void
    {
        file_put_contents($this->acp->root . '/a.php', '<?php // on disk');
        $this->prompt([
            ['type' => 'text', 'text' => 'compare '],
            ['type' => 'resource_link', 'uri' => 'file://' . $this->acp->root . '/a.php', 'name' => 'a.php'],
            ['type' => 'text', 'text' => ' with the buffer'],
            ['type' => 'resource', 'resource' => ['uri' => 'file://' . $this->acp->root . '/b.txt', 'text' => 'unsaved text', 'mimeType' => 'text/plain']],
        ]);

        $sent = $this->acp->backend->sent[0] ?? '';
        self::assertStringStartsWith('compare @' . $this->acp->root . '/a.php with the buffer', $sent);
        self::assertStringContainsString("<file path=\"{$this->acp->root}/b.txt\">\nunsaved text\n</file>", $sent);
    }

    public function testATodoUpdateBecomesAPlan(): void
    {
        $plan = AcpUpdateMapper::plan([
            ['content' => 'one', 'status' => 'completed'],
            ['content' => 'two', 'status' => 'in_progress'],
            ['content' => 'three', 'status' => 'pending'],
            ['content' => 'four', 'status' => 'cancelled'],
            'garbage',
        ]);

        self::assertSame(AcpUpdateMapper::PLAN, $plan['sessionUpdate']);
        self::assertSame(
            [['one', 'completed'], ['two', 'in_progress'], ['three', 'pending'], ['four', 'completed']],
            array_map(static fn (array $entry): array => [$entry['content'], $entry['status']], $plan['entries']),
        );
        self::assertSame([], AcpUpdateMapper::new()->fromEvent(SessionEvent::new(SessionEvent::TURN_STEP, ['step' => 1])), 'the step tick is nothing an editor shows');
    }

    public function testStopReasonsMapToTheProtocols(): void
    {
        self::assertSame(StopReasonMap::END_TURN, StopReasonMap::toAcp(SessionEvent::STOP_END_TURN));
        self::assertSame(StopReasonMap::MAX_TOKENS, StopReasonMap::toAcp(SessionEvent::STOP_LENGTH));
        self::assertSame(StopReasonMap::MAX_TURN_REQUESTS, StopReasonMap::toAcp(SessionEvent::STOP_MAX_STEPS));
        self::assertSame(StopReasonMap::MAX_TURN_REQUESTS, StopReasonMap::toAcp(SessionEvent::STOP_SPEND_CAP));
        self::assertSame(StopReasonMap::CANCELLED, StopReasonMap::toAcp(SessionEvent::STOP_CANCELLED));
        self::assertSame(StopReasonMap::END_TURN, StopReasonMap::toAcp(null));
        self::assertTrue(StopReasonMap::isError(SessionEvent::STOP_ERROR));
        self::assertFalse(StopReasonMap::isError(SessionEvent::STOP_END_TURN));
    }

    public function testToolKindsAndTitles(): void
    {
        self::assertSame('edit', AcpUpdateMapper::kind('Edit'));
        self::assertSame('execute', AcpUpdateMapper::kind('Bash'));
        self::assertSame('search', AcpUpdateMapper::kind('Grep'));
        self::assertSame('fetch', AcpUpdateMapper::kind('WebFetch'));
        self::assertSame('think', AcpUpdateMapper::kind('Task'));
        self::assertSame('other', AcpUpdateMapper::kind('mcp__github__create_issue'));
        self::assertSame('List the tree', AcpUpdateMapper::title('Bash', ['command' => 'ls', 'description' => 'List the tree']));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function prompt(array $blocks): int|string
    {
        return $this->acp->request('session/prompt', ['sessionId' => $this->sessionId, 'prompt' => $blocks]);
    }

    /**
     * @param list<array<string, mixed>> $updates
     * @return array<string, mixed>
     */
    private static function first(array $updates, string $kind): array
    {
        foreach ($updates as $update) {
            if ($update['sessionUpdate'] === $kind) {
                return $update;
            }
        }
        self::fail('no ' . $kind . ' update was sent');
    }
}
