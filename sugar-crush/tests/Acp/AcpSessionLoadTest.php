<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Acp;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Acp\AcpUpdateMapper;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Tests\Acp\Support\AcpHarness;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap 5.9-2: `session/load` reopens a stored session and replays its
 * transcript as the updates a live turn sends; an edit's change reaches the
 * editor as an ACP diff (`{path, oldText, newText}`) live and replayed alike;
 * and the session's permission mode is an ACP session mode.
 */
final class AcpSessionLoadTest extends TestCase
{
    private const DIFF = "--- a/src/a.php\n+++ b/src/a.php\n@@ -1,3 +1,3 @@\n one\n-two\n+TWO\n three\n";

    private AcpHarness $acp;

    protected function setUp(): void
    {
        $this->acp = new AcpHarness();
    }

    protected function tearDown(): void
    {
        $this->acp->dispose();
    }

    public function testLoadReplaysTheTranscriptThenAnswers(): void
    {
        $this->acp->store->createSession('stored', 'p', 'm');
        TranscriptStore::new($this->acp->store)->save('stored', [
            Message::user('fix the typo'),
            Message::assistant('', reasoning: 'it is in a.php')->withToolResults([
                new ToolResult('Edit', 'Edited src/a.php', id: 'c9', diff: self::DIFF, arguments: ['file_path' => 'src/a.php']),
            ]),
            Message::user('a reminder the model reads and the user never saw')->withUserVisible(false),
            Message::assistant('Fixed.'),
        ]);

        $this->acp->request('initialize', ['protocolVersion' => 1]);
        $id = $this->acp->request('session/load', ['sessionId' => 'stored', 'cwd' => $this->acp->root, 'mcpServers' => []]);

        $updates = $this->acp->updates();
        self::assertSame(
            [AcpUpdateMapper::USER_MESSAGE_CHUNK, AcpUpdateMapper::AGENT_THOUGHT_CHUNK, AcpUpdateMapper::TOOL_CALL, AcpUpdateMapper::AGENT_MESSAGE_CHUNK],
            array_column($updates, 'sessionUpdate'),
            'every visible row, in order, and nothing the transcript hides',
        );
        self::assertSame('fix the typo', $updates[0]['content']['text']);
        $call = $updates[2];
        self::assertSame('c9', $call['toolCallId']);
        self::assertSame('edit', $call['kind']);
        self::assertSame(AcpUpdateMapper::STATUS_COMPLETED, $call['status']);
        self::assertSame(
            ['type' => 'diff', 'path' => $this->acp->root . '/src/a.php', 'oldText' => "one\ntwo\nthree", 'newText' => "one\nTWO\nthree"],
            $call['content'][0],
        );

        $answer = $this->acp->response($id);
        self::assertSame(PermissionMode::Default->value, $answer['result']['modes']['currentModeId']);
        $last = $this->acp->messages()[\count($this->acp->messages()) - 1];
        self::assertSame($id, $last['id'] ?? null, 'the load is answered after the replay');
        self::assertSame(['stored'], $this->acp->server->sessionIds());
        self::assertTrue($this->acp->server->hub()?->isOpen('stored'), 'the loaded session holds its lock like any open one');
    }

    public function testInitializeAdvertisesLoadSession(): void
    {
        $id = $this->acp->request('initialize', ['protocolVersion' => 1]);

        self::assertTrue($this->acp->response($id)['result']['agentCapabilities']['loadSession']);
    }

    public function testLoadingAnUnknownOrHeldSessionIsRefused(): void
    {
        $this->acp->request('initialize', ['protocolVersion' => 1]);
        $unknown = $this->acp->request('session/load', ['sessionId' => 'ghost', 'cwd' => $this->acp->root]);
        self::assertSame(ErrorCode::NotFound->value, $this->acp->response($unknown)['error']['code']);

        $this->acp->store->createSession('held', 'p', 'm');
        $lock = $this->acp->store->lockSession('held');
        self::assertNotNull($lock);
        try {
            $held = $this->acp->request('session/load', ['sessionId' => 'held', 'cwd' => $this->acp->root]);
            $error = $this->acp->response($held)['error'] ?? null;
            self::assertSame(ErrorCode::Conflict->value, $error['code']);
            self::assertStringContainsString('only one process may write it', $error['message']);
        } finally {
            $lock->release();
        }
    }

    public function testALiveEditStreamsItsDiff(): void
    {
        $sessionId = $this->acp->open();
        $this->acp->request('session/prompt', ['sessionId' => $sessionId, 'prompt' => [['type' => 'text', 'text' => 'fix it']]]);
        $this->acp->backend->emit(new ToolStarted('c1', 'Write', ['file_path' => 'new.txt']));
        $this->acp->backend->emit(new ToolFinished('c1', 'Write', new EngineToolResult('c1', 'Created new.txt', diff: "--- a/new.txt\n+++ b/new.txt\n@@ -0,0 +1,2 @@\n+hello\n+world\n")));
        $this->acp->server->tick();

        $update = null;
        foreach ($this->acp->updates() as $candidate) {
            if ($candidate['sessionUpdate'] === AcpUpdateMapper::TOOL_CALL_UPDATE) {
                $update = $candidate;
            }
        }
        self::assertNotNull($update);
        self::assertSame(
            [
                ['type' => 'diff', 'path' => $this->acp->root . '/new.txt', 'oldText' => null, 'newText' => "hello\nworld"],
                ['type' => 'content', 'content' => ['type' => 'text', 'text' => 'Created new.txt']],
            ],
            $update['content'],
            'a created file has no old text',
        );
    }

    public function testSessionModesAreThePermissionModes(): void
    {
        $this->acp->request('initialize', ['protocolVersion' => 1]);
        $new = $this->acp->request('session/new', ['cwd' => $this->acp->root]);
        $result = $this->acp->response($new)['result'];
        $sessionId = $result['sessionId'];

        self::assertSame(PermissionMode::Default->value, $result['modes']['currentModeId']);
        self::assertSame(
            array_map(static fn (PermissionMode $m): string => $m->value, PermissionMode::cases()),
            array_column($result['modes']['availableModes'], 'id'),
        );
        self::assertSame(PermissionMode::Plan->description(), $result['modes']['availableModes'][2]['description']);

        $set = $this->acp->request('session/set_mode', ['sessionId' => $sessionId, 'modeId' => 'plan']);
        self::assertSame([], $this->acp->response($set)['result']);
        self::assertSame(PermissionMode::Plan, $this->acp->server->hub()?->get($sessionId)?->permissionMode());

        $bad = $this->acp->request('session/set_mode', ['sessionId' => $sessionId, 'modeId' => 'yolo']);
        self::assertSame(ErrorCode::InvalidParams->value, $this->acp->response($bad)['error']['code']);
    }

    public function testTheDiffReader(): void
    {
        self::assertSame([], ToolResult::diffTextsOf(''));
        self::assertSame([], ToolResult::diffTextsOf("not a diff\n"));

        $two = ToolResult::diffTextsOf("--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n@@ -9,2 +9,1 @@\n keep\n--- a/looks-like-a-header\n");
        self::assertSame(
            [
                ['path' => 'x', 'oldText' => 'a', 'newText' => 'b'],
                ['path' => 'x', 'oldText' => "keep\n-- a/looks-like-a-header", 'newText' => 'keep'],
            ],
            $two,
            'the hunk counts, not the line prefixes, say where a hunk ends',
        );
        self::assertSame($two, (new ToolResult('Edit', 'ok', diff: "--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n@@ -9,2 +9,1 @@\n keep\n--- a/looks-like-a-header\n"))->diffTexts());
    }
}
