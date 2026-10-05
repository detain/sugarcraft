<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Acp;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Acp\AcpServer;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Tests\Acp\Support\AcpHarness;

/**
 * Roadmap 5.9-1: the Agent Client Protocol handshake — `initialize`,
 * `authenticate`, `session/new` — and the JSON-RPC envelope around every
 * answer, ids echoed exactly as the editor sent them (decision D12).
 */
final class AcpHandshakeTest extends TestCase
{
    private AcpHarness $acp;

    protected function setUp(): void
    {
        $this->acp = new AcpHarness();
    }

    protected function tearDown(): void
    {
        $this->acp->dispose();
    }

    public function testInitializeAdvertisesTheAgentAndEchoesTheIntegerId(): void
    {
        $id = $this->acp->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => ['fs' => ['readTextFile' => true]]]);

        self::assertSame(1, $id);
        self::assertStringContainsString('"id":1,', $this->acp->lines[0], 'an integer id must go back as an integer, never "1"');
        $result = $this->acp->response($id)['result'] ?? null;
        self::assertIsArray($result);
        self::assertSame(AcpServer::PROTOCOL_VERSION, $result['protocolVersion']);
        self::assertTrue($result['agentCapabilities']['promptCapabilities']['embeddedContext']);
        self::assertFalse($result['agentCapabilities']['promptCapabilities']['image']);
        self::assertSame([], $result['authMethods']);
        self::assertSame(['name' => 'sugarcrush', 'title' => 'SugarCrush', 'version' => '9.9.9-test'], $result['agentInfo']);
    }

    public function testAStringIdIsEchoedAsAString(): void
    {
        $this->acp->request('initialize', ['protocolVersion' => 1], 'init-1');

        self::assertSame('init-1', $this->acp->messages()[0]['id']);
    }

    public function testAuthenticateIsAcceptedWithAnEmptyObject(): void
    {
        $this->acp->request('initialize', ['protocolVersion' => 1]);
        $id = $this->acp->request('authenticate', ['methodId' => 'none']);

        self::assertStringEndsWith('"result":{}}', $this->acp->lines[1], 'an empty result is an object on the wire, not []');
        self::assertSame([], $this->acp->response($id)['result']);
    }

    public function testNothingButInitializeIsAnsweredBeforeInitialize(): void
    {
        $id = $this->acp->request('session/new', ['cwd' => $this->acp->root]);

        self::assertSame(ErrorCode::NotInitialized->value, $this->acp->response($id)['error']['code']);
        self::assertSame([], $this->acp->server->sessionIds());
    }

    public function testAnUnknownMethodIsMethodNotFoundWithItsId(): void
    {
        $this->acp->request('initialize', ['protocolVersion' => 1]);
        $id = $this->acp->request('session/fly', []);

        $error = $this->acp->response($id)['error'] ?? null;
        self::assertSame(ErrorCode::MethodNotFound->value, $error['code']);
        self::assertStringContainsString('session/fly', $error['message']);
    }

    public function testUnreadableLinesAreAnsweredWithANullId(): void
    {
        $this->acp->server->receive('{not json');
        $this->acp->server->receive('{"hello":"world"}');
        $this->acp->server->receive('{"jsonrpc":"2.0","id":1.5,"method":"initialize"}');
        $this->acp->server->receive('   ');

        $messages = $this->acp->messages();
        self::assertCount(3, $messages, 'a blank line is no message and gets no answer');
        self::assertSame(ErrorCode::ParseError->value, $messages[0]['error']['code']);
        self::assertSame(ErrorCode::InvalidRequest->value, $messages[1]['error']['code']);
        self::assertSame(ErrorCode::InvalidRequest->value, $messages[2]['error']['code'], 'a float id is no JSON-RPC id');
        foreach ($messages as $message) {
            self::assertArrayHasKey('id', $message);
            self::assertNull($message['id']);
        }
    }

    public function testSessionNewOpensAStoredSessionInTheProjectRoot(): void
    {
        $sessionId = $this->acp->open();

        self::assertNotSame('', $sessionId);
        self::assertSame([$sessionId], $this->acp->server->sessionIds());
        self::assertNotNull($this->acp->store->getSession($sessionId), 'the session is in the store, so --resume can reopen it');
        self::assertSame($this->acp->root, $this->acp->server->hub()?->workspace()->root);
        self::assertTrue($this->acp->server->hub()->isOpen($sessionId));
    }

    public function testSessionNewRefusesARelativeCwdAndASecondRoot(): void
    {
        $this->acp->request('initialize', ['protocolVersion' => 1]);
        $relative = $this->acp->request('session/new', ['cwd' => 'relative/dir']);
        self::assertSame(ErrorCode::InvalidParams->value, $this->acp->response($relative)['error']['code']);

        $this->acp->request('session/new', ['cwd' => $this->acp->root]);
        $other = $this->acp->root . '/elsewhere';
        mkdir($other);
        $second = $this->acp->request('session/new', ['cwd' => $other]);

        $error = $this->acp->response($second)['error'] ?? null;
        self::assertSame(ErrorCode::InvalidParams->value, $error['code']);
        self::assertStringContainsString('start another sugarcrush acp', $error['message']);
        self::assertCount(1, $this->acp->server->sessionIds());
    }

    public function testStopClosesEverySessionAndReleasesItsLock(): void
    {
        $sessionId = $this->acp->open();
        self::assertNull($this->acp->store->lockSession($sessionId), 'an open session holds its lock');

        $this->acp->server->stop();

        self::assertSame([], $this->acp->server->sessionIds());
        $lock = $this->acp->store->lockSession($sessionId);
        self::assertNotNull($lock, 'stopping released the lock');
        $lock->release();
    }
}
