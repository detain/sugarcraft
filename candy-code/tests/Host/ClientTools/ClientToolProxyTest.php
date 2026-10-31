<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\ClientTools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Host\ClientTools\ClientToolProxy;
use SugarCraft\Code\Host\ClientTools\ClientToolSpec;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\ParallelSafe;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\ToolResult;

/**
 * C-TOOL-6.h: the model-facing side of a client-hosted tool.
 *
 * What the proxy shows the model (prefix, description and schema pass
 * through), how a call travels (through the bound caller closure with the
 * engine-carried id stripped from the client's arguments), and what happens
 * where no channel exists (an immediate error result — never a block).
 */
final class ClientToolProxyTest extends TestCase
{
    public function testNameCarriesTheClientPrefix(): void
    {
        $proxy = new ClientToolProxy(self::proxySpec('browser_context'));

        self::assertSame('client__browser_context', $proxy->name());
        self::assertSame('client__', ClientToolProxy::NAME_PREFIX);
    }

    public function testDescriptionAndSchemaPassTheAnnouncementThroughUnchanged(): void
    {
        $spec = self::proxySpec('ctx');
        $proxy = new ClientToolProxy($spec);

        self::assertSame($spec->description, $proxy->description());
        self::assertSame($spec->inputSchema, $proxy->inputSchema());
    }

    public function testRefusesWithoutACallerChannel(): void
    {
        // The unforked-blocking-turn shape: no socket exists to carry the
        // call, so the proxy answers at once with an error rather than
        // waiting for a reply that structurally cannot arrive.
        $proxy = new ClientToolProxy(self::proxySpec('ctx'));

        $result = $proxy->execute(['id' => 'call_1', 'q' => 'hello']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('client__ctx', $result->content());
        self::assertStringContainsString('no client channel', $result->content());
    }

    public function testTheBoundCallerAnswersTheCallWithContentAndFlag(): void
    {
        // Corpus reachability evidence (BuiltInToolCorpus::DYNAMIC_TOOL_CLASSES
        // names this method): a registration-bound caller really carries a
        // call to the other side and its answer back.
        $seen = [];
        $answer = new ToolResult(toolCallId: 'call_9', content: 'the tab title', isError: false);
        $proxy = (new ClientToolProxy(self::proxySpec('ctx', timeoutSeconds: 42)))
            ->withClientCaller(static function (string $toolCallId, string $name, array $arguments, int $deadlineSeconds) use (&$seen, $answer): ToolResult {
                $seen = ['toolCallId' => $toolCallId, 'name' => $name, 'arguments' => $arguments, 'deadline' => $deadlineSeconds];

                return $answer;
            });

        $result = $proxy->execute(['id' => 'call_9', 'q' => 'read the tab', 'n' => 3]);

        self::assertSame($answer, $result);
        self::assertSame('call_9', $seen['toolCallId']);
        self::assertSame('client__ctx', $seen['name']);
        // The engine-carried id is stripped: the client sees only the shape
        // its own announced schema described.
        self::assertSame(['q' => 'read the tab', 'n' => 3], $seen['arguments']);
        // The deadline rides the declared per-operation timeout.
        self::assertSame(42, $seen['deadline']);
    }

    public function testAMissingIdReachesTheCallerAsAnEmptyToolCallId(): void
    {
        $captured = null;
        $proxy = (new ClientToolProxy(self::proxySpec('ctx')))
            ->withClientCaller(static function (string $toolCallId) use (&$captured): ToolResult {
                $captured = $toolCallId;

                return new ToolResult(toolCallId: $toolCallId, content: 'ok');
            });

        $proxy->execute(['q' => 'no id here']);

        self::assertSame('', $captured);
    }

    public function testErrorAnswersRideBackWithTheFlagSet(): void
    {
        $proxy = (new ClientToolProxy(self::proxySpec('ctx')))
            ->withClientCaller(static fn (): ToolResult => new ToolResult(
                toolCallId: 'call_x',
                content: 'the user declined to run this',
                isError: true,
            ));

        $result = $proxy->execute(['id' => 'call_x']);

        self::assertTrue($result->isError());
        self::assertSame('the user declined to run this', $result->content());
    }

    public function testWithEngineIsANoOpAndTheWithholdingInterfaceIsCarried(): void
    {
        // Mirrors AskUserToolTest::testItIsWithheldFromSubAgentsAsAnEngineBoundTool.
        // Membership in DelegatesToEngine IS the withholding mechanism —
        // EngineExecutor::run() filters the sub-agent toolset on exactly that
        // interface — and, like the ask tool, the proxy implements it for the
        // side effect only: a sub-agent must not drive the user's browser.
        // ParallelSafe is deliberately absent: every client call is a barrier.
        $proxy = new ClientToolProxy(self::proxySpec('ctx'));

        self::assertInstanceOf(DelegatesToEngine::class, $proxy);
        self::assertNotInstanceOf(ParallelSafe::class, $proxy);
        self::assertInstanceOf(TakesToolCallId::class, $proxy);
        self::assertSame($proxy, $proxy->withEngine(self::stubEngine()));
    }

    public function testWithClientCallerBindsACopyAndLeavesTheOriginalRefusing(): void
    {
        $original = new ClientToolProxy(self::proxySpec('ctx'));
        $bound = $original->withClientCaller(
            static fn (): ToolResult => new ToolResult(toolCallId: '', content: 'answered'),
        );

        self::assertNotSame($original, $bound);
        self::assertTrue($original->execute(['id' => 'call_1'])->isError(), 'the unbuilt original must still refuse');
        self::assertFalse($bound->execute(['id' => 'call_1'])->isError());
        self::assertSame('answered', $bound->execute(['id' => 'call_1'])->content());
    }

    /**
     * An engine the proxy never reads — withEngine() is present for its
     * exclusion side effect, so the pin only needs the call to type-check.
     */
    private static function stubEngine(): EngineBackend
    {
        return EngineBackend::new(new EchoProvider(), 'echo');
    }

    /** One validated descriptor at a chosen timeout. */
    private static function proxySpec(string $name, int $timeoutSeconds = ClientToolSpec::DEFAULT_TIMEOUT_SECONDS): ClientToolSpec
    {
        return new ClientToolSpec(
            name: $name,
            description: 'proxy probe descriptor for ' . $name,
            inputSchema: ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            timeoutSeconds: $timeoutSeconds,
        );
    }
}
