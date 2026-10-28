<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\AnswersServerRequests;
use SugarCraft\Code\MCP\McpElicitation;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpServerRequests;
use SugarCraft\Code\MCP\StdioMcpServer;

/**
 * C-MCP-3: the product's answer desk for server-originated requests.
 *
 * The desk is a pure dispatch layer — one method table over
 * (method, params, isNotification) — so every row is pinned without a
 * pipe. The transport legs (what actually gets written back, in what wire
 * shape) are pinned on the library side by
 * SugarCraft\McpClient\Tests\StdioMcpServerServerRequestTest; the seam
 * between them is pinned here as shape: {@see AnswersServerRequests} is
 * implemented by the stdio adapter and nothing else, and the adapter's
 * per-call entry point survives its own constructor smoke.
 */
final class McpServerRequestsTest extends TestCase
{
    private const ROOT = '/tmp/opencode/c-cmp-mcp3a-root';

    public function testPingIsAcknowledgedWithTheEmptyObject(): void
    {
        $answer = (new McpServerRequests(self::ROOT))->handle('ping', null, false);

        self::assertIsArray($answer);
        self::assertArrayHasKey('result', $answer);
        self::assertInstanceOf(\stdClass::class, $answer['result'], 'the ping answer is the JSON object empty map');
        self::assertArrayNotHasKey('error', $answer);
    }

    public function testRootsListAdvertisesTheSpawnDirectoryAsOneFileUriRoot(): void
    {
        $answer = (new McpServerRequests('/work/project/'))->handle('roots/list', null, false);

        self::assertIsArray($answer);
        self::assertSame([
            'roots' => [[
                'uri' => 'file:///work/project',
                'name' => 'project',
            ]],
        ], $answer['result'], 'exactly one root, the three-slash file URI, trailing separator trimmed');
    }

    public function testEmptyProjectRootFallsBackToTheProcessWorkingDirectory(): void
    {
        $cwd = getcwd();
        self::assertIsString($cwd);

        $answer = (new McpServerRequests(''))->handle('roots/list', null, false);

        self::assertIsArray($answer);
        $roots = $answer['result']['roots'];
        self::assertCount(1, $roots);
        self::assertSame('file://' . rtrim($cwd, '/'), $roots[0]['uri'], 'the fallback root is where we actually stand, named not invented');
    }

    public function testElicitationWithoutAnApproverCancelsInsteadOfGuessing(): void
    {
        $params = [
            'message' => 'Proceed?',
            'requestedSchema' => [
                'type' => 'object',
                'properties' => ['go' => ['type' => 'boolean']],
                'required' => ['go'],
            ],
        ];

        $answer = (new McpServerRequests(self::ROOT))->handle('elicitation/create', $params, false);

        self::assertIsArray($answer);
        self::assertSame(['action' => 'cancel'], $answer['result'], 'nobody to ask must cancel — never auto-accept');
    }

    public function testElicitationRoutesThroughTheInjectedApproverToAnAccept(): void
    {
        $params = [
            'message' => 'Pick a colour',
            'requestedSchema' => [
                'type' => 'object',
                'properties' => ['colour' => ['type' => 'string', 'enum' => ['red', 'green']]],
                'required' => ['colour'],
            ],
        ];

        $desk = new McpServerRequests(
            self::ROOT,
            approver: static fn (array $questions): array => ['colour' => 'green'],
        );

        $answer = $desk->handle('elicitation/create', $params, false);

        self::assertIsArray($answer);
        self::assertSame(['action' => 'accept', 'content' => ['colour' => 'green']], $answer['result']);
    }

    public function testElicitationDeclineSentinelRoundTripsAsDecline(): void
    {
        $params = [
            'requestedSchema' => [
                'type' => 'object',
                'properties' => ['n' => ['type' => 'number']],
            ],
        ];

        $desk = new McpServerRequests(
            self::ROOT,
            approver: static fn (array $questions): string => McpElicitation::DECLINE,
        );

        $answer = $desk->handle('elicitation/create', $params, false);

        self::assertIsArray($answer);
        self::assertSame(['action' => 'decline'], $answer['result']);
    }

    public function testMalformedElicitationSchemaIsRefusedAsInvalidParams(): void
    {
        $answer = (new McpServerRequests(self::ROOT))->handle(
            'elicitation/create',
            ['requestedSchema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'array']]]],
            false,
        );

        self::assertIsArray($answer);
        self::assertArrayHasKey('error', $answer, 'an unnestable schema is refused, never rendered wrong');
        self::assertSame(McpServerRequests::INVALID_PARAMS, $answer['error']['code']);
        self::assertStringStartsWith('Unusable elicitation schema:', $answer['error']['message']);
    }

    public function testApproverAnswerViolatingItsTypeBecomesAnInternalError(): void
    {
        $params = [
            'requestedSchema' => [
                'type' => 'object',
                'properties' => ['count' => ['type' => 'integer']],
                'required' => ['count'],
            ],
        ];

        $desk = new McpServerRequests(
            self::ROOT,
            approver: static fn (array $questions): array => ['count' => 'twelve'],
        );

        $answer = $desk->handle('elicitation/create', $params, false);

        self::assertIsArray($answer);
        self::assertSame(McpServerRequests::INTERNAL_ERROR, $answer['error']['code']);
        self::assertStringStartsWith('Unacceptable elicitation answer:', $answer['error']['message'], 'a violating answer is refused loudly — this client will not lie onto the wire');
    }

    public function testSamplingAndUnknownMethodsAreRefusedMethodNotFound(): void
    {
        $desk = new McpServerRequests(self::ROOT);

        foreach (['sampling/createMessage', 'logging/setLevel', 'roots/list_changed', 'whatever/else'] as $method) {
            $answer = $desk->handle($method, [], false);

            self::assertIsArray($answer);
            self::assertSame(McpServerRequests::METHOD_NOT_FOUND, $answer['error']['code'], $method . ' stays refused: the posture is default-deny');
            self::assertSame('Method not found', $answer['error']['message']);
        }
    }

    public function testProgressNotificationReachesTheSinkAndNothingIsWrittenBack(): void
    {
        $seen = [];

        $desk = new McpServerRequests(
            self::ROOT,
            onProgress: static function (array $params) use (&$seen): void {
                $seen[] = $params;
            },
        );

        self::assertNull($desk->handle('notifications/progress', ['progressToken' => 't1', 'progress' => 1], true));
        self::assertSame([['progressToken' => 't1', 'progress' => 1]], $seen);

        // A different notification method must not be poured into the sink.
        self::assertNull($desk->handle('notifications/cancelled', ['requestId' => 'x'], true));
        self::assertCount(1, $seen, 'the sink is progress-only');
    }

    public function testNotificationsWithNoSinkAreSilentlyAbsorbed(): void
    {
        self::assertNull((new McpServerRequests(self::ROOT))->handle('notifications/progress', ['progress' => 2], true));
    }

    public function testHandlerClosureMirrorsTheDispatchTable(): void
    {
        $desk = new McpServerRequests(self::ROOT);
        $handler = $desk->handler();

        // assertEquals, not assertSame: each ping answer mint its own
        // empty stdClass — the identity that must match is the SHAPE.
        self::assertEquals($desk->handle('ping', null, false), $handler('ping', null, false));
        self::assertSame($desk->handle('nope/nada', null, false), $handler('nope/nada', null, false));
    }

    /**
     * The transport-side seam this desk plugs into: only the stdio adapter
     * takes the capability, and its constructor accepts the answerer shape
     * the client wires at buildServer().
     */
    public function testTheStdioAdapterCarriesTheCapabilitySeam(): void
    {
        $adapter = new StdioMcpServer(name: 'probe', command: '/bin/true', args: [], env: []);

        self::assertInstanceOf(AnswersServerRequests::class, $adapter);
        self::assertInstanceOf(
            McpServer::class,
            $adapter,
            'the seam is additive — McpServer itself stayed what it always was',
        );

        $reflected = new \ReflectionMethod($adapter, 'callToolAnsweringRequests');
        $params = $reflected->getParameters();
        self::assertSame(['toolName', 'args', 'onWait', 'serverRequests'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $params,
        ));
        self::assertTrue($params[3]->allowsNull());
        self::assertInstanceOf(\ReflectionNamedType::class, $params[3]->getType());
        self::assertSame(McpServerRequests::class, $params[3]->getType()->getName());
    }
}
