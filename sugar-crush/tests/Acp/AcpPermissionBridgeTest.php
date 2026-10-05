<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Acp;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Acp\AcpPermissionBridge;
use SugarCraft\Crush\Acp\AcpUpdateMapper;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tests\Acp\Support\AcpHarness;

/**
 * Roadmap 5.9-1: a turn's permission question goes to the editor as a
 * `session/request_permission` request, and the editor's answer — whenever it
 * comes, with other traffic read meanwhile — settles the question the turn's
 * child is waiting on. Anything but a selected allow is a reject.
 */
final class AcpPermissionBridgeTest extends TestCase
{
    private AcpHarness $acp;

    private string $sessionId;

    protected function setUp(): void
    {
        $this->acp = new AcpHarness();
        $this->sessionId = $this->acp->open();
        $this->acp->request('session/prompt', ['sessionId' => $this->sessionId, 'prompt' => [['type' => 'text', 'text' => 'clean up']]]);
    }

    protected function tearDown(): void
    {
        $this->acp->dispose();
    }

    public function testAQuestionIsPutToTheEditorAndItsAnswerReachesTheTurn(): void
    {
        $this->acp->backend->ask('c1', 'Bash', ['command' => 'rm -rf build', 'description' => 'Remove the build dir']);
        $this->acp->server->tick();

        $requests = $this->acp->requests(AcpPermissionBridge::METHOD);
        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertIsInt($request['id'], 'the agent mints integer ids, and an editor echoes them');
        self::assertSame($this->sessionId, $request['params']['sessionId']);
        self::assertSame([
            'toolCallId' => 'c1',
            'title' => 'Remove the build dir',
            'kind' => 'execute',
            'status' => AcpUpdateMapper::STATUS_PENDING,
            'rawInput' => ['command' => 'rm -rf build', 'description' => 'Remove the build dir'],
        ], $request['params']['toolCall']);
        self::assertSame(
            [['once', 'allow_once'], ['always', 'allow_always'], ['reject', 'reject_once']],
            array_map(static fn (array $o): array => [$o['optionId'], $o['kind']], $request['params']['options']),
        );
        self::assertSame('needs approval', $request['params']['_meta']['sugarcrush']['reason']);

        // Other traffic is read while the question is open.
        $this->acp->request('authenticate', []);

        $this->acp->respond($request['id'], ['outcome' => ['outcome' => 'selected', 'optionId' => 'once']]);
        self::assertCount(1, $this->acp->backend->settled);
        self::assertSame(PermissionReply::Once, $this->acp->backend->settled[0]->reply);
    }

    public function testAlwaysIsRememberedForTheSession(): void
    {
        $this->acp->backend->ask('c1', 'Edit', ['file_path' => 'a.php']);
        $this->acp->server->tick();
        $request = $this->acp->requests(AcpPermissionBridge::METHOD)[0];

        $this->acp->respond($request['id'], ['outcome' => ['outcome' => 'selected', 'optionId' => 'always']]);

        self::assertSame(PermissionReply::Always, $this->acp->backend->settled[0]->reply);
        $host = $this->acp->server->hub()?->get($this->sessionId);
        self::assertNotNull($host);
        self::assertFalse($host->grants()->isEmpty(), 'an always is the session\'s grant for later turns, as in the TUI');
    }

    public function testAQuestionThatOffersNoAlwaysOffersOnlyOnceAndReject(): void
    {
        $this->acp->backend->ask('c1', 'Bash', ['command' => 'deploy'], offersAlways: false);
        $this->acp->server->tick();

        $options = $this->acp->requests(AcpPermissionBridge::METHOD)[0]['params']['options'];
        self::assertSame(['once', 'reject'], array_column($options, 'optionId'));
    }

    public function testACancelledOrUnreadableAnswerRejects(): void
    {
        $this->acp->backend->ask('c1', 'Bash', ['command' => 'one']);
        $this->acp->backend->ask('c2', 'Bash', ['command' => 'two']);
        $this->acp->backend->ask('c3', 'Bash', ['command' => 'three']);
        $this->acp->server->tick();
        [$first, $second, $third] = $this->acp->requests(AcpPermissionBridge::METHOD);

        $this->acp->respond($first['id'], ['outcome' => ['outcome' => 'cancelled']]);
        $this->acp->server->receive((string) json_encode(['jsonrpc' => '2.0', 'id' => $second['id'], 'error' => ['code' => -32603, 'message' => 'boom']]));
        $this->acp->respond($third['id'], ['outcome' => ['outcome' => 'selected', 'optionId' => 'allow-everything']]);

        self::assertSame(
            [PermissionReply::Reject, PermissionReply::Reject, PermissionReply::Reject],
            array_map(static fn ($resolution) => $resolution->reply, $this->acp->backend->settled),
        );
        self::assertSame('the editor cancelled the prompt', $this->acp->backend->settled[0]->note);
    }

    public function testAnAnswerToNothingWeAskedIsIgnored(): void
    {
        $this->acp->respond(999, ['outcome' => ['outcome' => 'selected', 'optionId' => 'once']]);
        $this->acp->respond('stray', null);

        self::assertSame([], $this->acp->backend->settled);
        $this->acp->backend->settle(Message::assistant('done'));
        $this->acp->server->tick();
        self::assertCount(1, array_filter($this->acp->messages(), static fn (array $m): bool => isset($m['result']['stopReason'])));
    }

    public function testTheReplyReader(): void
    {
        self::assertSame(PermissionReply::Once, AcpPermissionBridge::reply(['outcome' => ['outcome' => 'selected', 'optionId' => 'once']]));
        self::assertSame(PermissionReply::Reject, AcpPermissionBridge::reply(null));
        self::assertSame(PermissionReply::Reject, AcpPermissionBridge::reply(['outcome' => 'selected']));
        self::assertSame(PermissionReply::Reject, AcpPermissionBridge::reply(['outcome' => ['outcome' => 'selected', 'optionId' => 7]]));
        self::assertTrue(AcpPermissionBridge::isCancelled(['outcome' => ['outcome' => 'cancelled']]));
        self::assertFalse(AcpPermissionBridge::isCancelled(['outcome' => ['outcome' => 'selected', 'optionId' => 'once']]));
    }
}
