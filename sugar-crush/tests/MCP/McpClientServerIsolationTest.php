<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpClient;

/**
 * ONE BAD ENTRY IN `.mcp.json` DISABLED EVERY OTHER SERVER IN THE FILE, BY THREE
 * ROUTES, AND THE CODE CARRIED A COMMENT PROMISING IT COULD NOT.
 *
 * {@see McpClient::startServer()} wrapped `$server->start()` in
 * `catch (\RuntimeException)` under "a single unreachable/misbehaving server
 * must not abort loading the rest". Three things walked past that:
 *
 *   1. A `TypeError` out of `McpTool::fromArray()`, raised by a well-formed
 *      JSON-RPC reply of `{"tools":[{"name":5}]}`. Not a `RuntimeException`.
 *   2. `"type": "sse"` — the `default` arm's `throw` sat OUTSIDE the try, under
 *      a comment saying so as though it were the desired behaviour. `sse` is a
 *      transport the MCP specification defines and this port has not
 *      implemented, so it is what a real config carries, not a typo.
 *   3. Anything else non-`RuntimeException` a constructor or a handshake raises.
 *
 * MEASURED at `1dea13c4f` on this host (PHP 8.3.6, Linux 6.8) before the fix,
 * driving `startServers()` over the two-server configs these rows build, the
 * offender listed FIRST:
 *
 *     route 1 -> ESCAPED: TypeError ... Argument #1 ($name) must be of type
 *                string, int given          | tools visible: 0
 *     route 2 -> ESCAPED: RuntimeException: Unknown MCP server type: sse
 *                                           | tools visible: 0
 *
 * ⚠️ KEY ORDER IS PART OF THE FIXTURE, NOT AN INCIDENTAL. `startServers()` walks
 * the config map in order, so every server BEFORE the offender had already
 * started and stayed started. The same broken file therefore lost everything,
 * something or nothing depending purely on where the bad key sat — which is why
 * every row here puts the offender first, and why the LAST row puts it second
 * and requires the same answer.
 *
 * TRANSPORT: `http`, with a `MockHandler`-backed Guzzle client. That is
 * deliberate — these rows are about {@see McpClient}'s guard and about
 * {@see \SugarCraft\Crush\MCP\HttpMcpServer::parseTools()}, and a real child
 * process would put the stdio framing between the assertion and its subject.
 * The stdio half of the same filter is driven against a real child in
 * {@see StdioMcpServerToolListRobustnessTest}.
 */
final class McpClientServerIsolationTest extends TestCase
{
    /** @var list<string> */
    private array $configs = [];

    protected function tearDown(): void
    {
        foreach ($this->configs as $path) {
            @unlink($path);
        }
        $this->configs = [];

        parent::tearDown();
    }

    /**
     * ROUTE 1: a mistyped `name` in one server's `tools/list` reply. The
     * well-formed neighbour must still be reachable.
     */
    public function testAMistypedToolFromOneHttpServerDoesNotAbortTheOthers(): void
    {
        $client = $this->clientFor(
            ['bad' => $this->httpEntry(), 'good' => $this->httpEntry()],
            [$this->handshake(), $this->toolsList('[{"name":5,"description":"mistyped"}]'),
             $this->handshake(), $this->toolsList('[{"name":"ok","description":"fine","inputSchema":{}}]')],
        );

        // No report: a mistyped ENTRY is filtered inside parseTools(), so the
        // server it came from starts cleanly with one fewer tool. Only a config
        // this class cannot BUILD is reported — see McpClient::startServer().
        $client->startServers();

        $this->assertSame(
            ['ok'],
            array_map(static fn ($t) => $t->name, $client->listTools()),
            'the well-formed server\'s tool is missing, so the mistyped entry from the OTHER '
            . 'server aborted the whole startServers() loop — the defect this file exists for',
        );
    }

    /**
     * The mistyped entry is SKIPPED rather than taking its own server down with
     * it: without this row a `parseTools()` that returned `[]` for any list
     * containing a bad entry would satisfy everything above.
     */
    public function testTheMistypedEntryIsSkippedAndItsWellFormedNeighbourIsNot(): void
    {
        $client = $this->clientFor(
            ['mixed' => $this->httpEntry()],
            [$this->handshake(), $this->toolsList(
                '[{"name":5},{"name":"kept","description":"fine","inputSchema":{}},{"name":"nulls","description":null}]'
            )],
        );

        // The mistyped entry is filtered inside parseTools(), so the server
        // starts cleanly — a launch failure here would mean the filter had been
        // replaced by a refusal.
        $client->startServers();

        $this->assertSame(
            ['kept', 'nulls'],
            array_map(static fn ($t) => $t->name, $client->listTools()),
            'the mistyped entry must be dropped, the well-formed one kept, and an explicit '
            . 'null — which `??` turns into the typed default — kept as well: `isset()` and '
            . 'not `array_key_exists()`, see McpTool::toolDefinitionIsWellTyped()',
        );
    }

    /**
     * ROUTE 2: an unknown transport. `sse` is in the MCP specification and is
     * not implemented here, so this is the ordinary shape of the failure.
     */
    public function testAnUnknownServerTypeDoesNotAbortTheOthers(): void
    {
        $client = $this->clientFor(
            ['bad' => ['type' => 'sse', 'url' => 'http://bad.invalid/rpc'], 'good' => $this->httpEntry()],
            [$this->handshake(), $this->toolsList('[{"name":"ok","description":"fine","inputSchema":{}}]')],
        );

        $this->assertStartFailed($client);

        $this->assertSame(
            ['ok'],
            array_map(static fn ($t) => $t->name, $client->listTools()),
            'an unrecognised `type` still aborts the loop — the `default` arm\'s throw has to '
            . 'sit INSIDE startServer()\'s guard, not beside it',
        );
    }

    /** The unknown-type server is dropped, not silently substituted with something. */
    public function testAnUnknownServerTypeIsNotReachable(): void
    {
        $client = $this->clientFor(
            ['bad' => ['type' => 'sse', 'url' => 'http://bad.invalid/rpc']],
            [],
        );

        $this->assertStartFailed($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown MCP server: bad');
        $client->callTool('bad', 'anything', []);
    }

    /**
     * ROUTE 3: a handshake that fails with something that is not a
     * `RuntimeException` at all. `HttpMcpServer::start()` converts `\Exception`s
     * itself, so the shape that reaches {@see McpClient} is an `\Error` — here a
     * `tools/list` body that is valid JSON but whose `tools` member is a list of
     * scalars, which `parseTools()` skips, plus a SECOND server proving the loop
     * survived. Kept as a distinct row from route 1 because it exercises the
     * `is_array($def)` guard rather than the type filter.
     */
    public function testAScalarToolEntryIsSkippedAndTheLoopSurvives(): void
    {
        $client = $this->clientFor(
            ['bad' => $this->httpEntry(), 'good' => $this->httpEntry()],
            [$this->handshake(), $this->toolsList('["write","read"]'),
             $this->handshake(), $this->toolsList('[{"name":"ok","description":"fine","inputSchema":{}}]')],
        );

        // NO failure here, and that is the row's second half: a scalar where a
        // tool object belongs is SKIPPED by parseTools(), so the server itself
        // starts fine. It is not a launch failure at all.
        $client->startServers();

        $this->assertSame(
            ['ok'],
            array_map(static fn ($t) => $t->name, $client->listTools()),
            'a scalar where a tool object belongs must be skipped, not fatal',
        );
    }

    /**
     * ROUTE 3, PROPERLY: a `start()` that raises something which is not a
     * `RuntimeException` AT ALL.
     *
     * ⚠️ THIS ROW EXISTS BECAUSE A MUTATION SURVIVED. Narrowing
     * `startServer()`'s runtime catch from `\Throwable` back to
     * `\RuntimeException` was GREEN across every MCP suite — 100 rows, 290
     * assertions — so the widening that closes route 3 was pinned by nothing.
     * The rows above could not reach it: with `McpTool::tryFromArray()` in
     * place a mistyped tool entry is filtered rather than thrown, and a scalar
     * entry is skipped by `is_array()`, so neither makes `start()` raise
     * anything.
     *
     * The fixture is a `MockHandler` seeded with an `\Error`. That is not
     * contrived: `HttpMcpServer::start()` wraps its whole handshake in
     * `catch (\Exception)`, and an `\Error` is not an `\Exception`, so `\Error`
     * is precisely the class that reaches {@see McpClient} from that server
     * type. Measured at this tree — `start()` on such a server escapes with
     * `Error: synthetic non-Exception`.
     */
    public function testAStartThatRaisesANonExceptionThrowableDoesNotAbortTheOthers(): void
    {
        $client = $this->clientFor(
            ['bad' => $this->httpEntry(), 'good' => $this->httpEntry()],
            [$this->handshake(), new \Error('a server type raised something that is not an Exception'),
             $this->handshake(), $this->toolsList('[{"name":"ok","description":"fine","inputSchema":{}}]')],
        );

        // Silent, like any other runtime start failure: the config was fine, the
        // server was not. See McpClient::startServer() for why those two are not
        // the same event.
        $client->startServers();

        $this->assertSame(
            ['ok'],
            array_map(static fn ($t) => $t->name, $client->listTools()),
            'a start() raising a non-Exception Throwable still aborts the loop, so route 3 is '
            . 'open: startServer()\'s runtime catch has to be \Throwable, not \RuntimeException',
        );
    }

    /**
     * AND THE OFFENDER IS DROPPED, not registered half-started. Without this the
     * row above is satisfied by a client that keeps a server whose handshake
     * never completed.
     */
    public function testTheServerWhoseStartRaisedIsNotRegistered(): void
    {
        $client = $this->clientFor(
            ['bad' => $this->httpEntry(), 'good' => $this->httpEntry()],
            [$this->handshake(), new \Error('boom'),
             $this->handshake(), $this->toolsList('[{"name":"ok","description":"fine","inputSchema":{}}]')],
        );

        $client->startServers();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown MCP server: bad');
        $client->callTool('bad', 'anything', []);
    }

    /**
     * ROUTE 3 ON THE CONFIG SIDE: a `.mcp.json` value of the wrong JSON TYPE
     * makes a CONSTRUCTOR raise, and a constructor raises `TypeError`.
     *
     * ⚠️ THIS ROW EXISTS BECAUSE A SURVIVING MUTATION WAS NEARLY EXCUSED AS
     * EQUIVALENT. Narrowing `startServer()`'s CONFIG catch to
     * `\RuntimeException` survives — the `match`'s `default` arm throws exactly
     * that, so the narrowing looks harmless. It is not. `buildServer()` also
     * CONSTRUCTS, and every constructor it reaches declares typed parameters
     * that a hand-written `.mcp.json` can violate. MEASURED at this tree, five
     * shapes, each with a well-formed `git` server listed second:
     *
     *     {"args": "not-an-array"}       {"env": "nope"}      {"command": 7}
     *     {"headers": "nope"} (http)     {"path": 7} (git)
     *
     * All five are reported and all five leave the good server's 29 tools
     * reachable. Under the narrowed catch every one of them aborts the loop.
     *
     * AND THESE ARE NOT EXOTIC. `"args": "-y @modelcontextprotocol/server-git"`
     * written as a string instead of a list is the single likeliest mistake a
     * human makes editing this file, and it used to disable every OTHER server
     * in it depending on key order.
     *
     * @dataProvider mistypedConfigValues
     * @param array<string, mixed> $entry
     */
    public function testAMistypedConfigValueIsReportedWithoutAbortingTheOthers(array $entry): void
    {
        $client = $this->clientFor(
            ['bad' => $entry, 'good' => ['type' => 'git']],
            [],
        );

        $this->assertStartFailed($client);

        $this->assertNotSame(
            [],
            $client->listTools(),
            'the well-formed git server is unreachable, so a wrong JSON type in ANOTHER '
            . 'entry aborted the loop. startServer()\'s config catch has to be \Throwable: '
            . 'buildServer() constructs, and a constructor answers a bad type with a '
            . 'TypeError, which is not a RuntimeException.',
        );
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function mistypedConfigValues(): array
    {
        return [
            'stdio args as a string' => [['type' => 'stdio', 'command' => 'echo', 'args' => 'not-an-array']],
            'stdio env as a string' => [['type' => 'stdio', 'command' => 'echo', 'env' => 'nope']],
            'stdio command as an int' => [['type' => 'stdio', 'command' => 7]],
            'http headers as a string' => [['type' => 'http', 'url' => 'http://mock.invalid/rpc', 'headers' => 'nope']],
            'git path as an int' => [['type' => 'git', 'path' => 7]],
        ];
    }

    /**
     * THE ORDER CONTROL. The offender is listed SECOND here, so the server that
     * must survive is one the old code ALSO started — the row would pass against
     * the defect. It is here to make the ordering claim in the file's doc-block
     * checkable rather than prose, and it is explicitly NOT evidence on its own.
     */
    public function testTheSurvivorIsFoundWhicheverSideOfTheOffenderItSitsOn(): void
    {
        $client = $this->clientFor(
            ['good' => $this->httpEntry(), 'bad' => $this->httpEntry()],
            [$this->handshake(), $this->toolsList('[{"name":"ok","description":"fine","inputSchema":{}}]'),
             $this->handshake(), $this->toolsList('[{"name":5}]')],
        );

        $client->startServers();

        $this->assertSame(
            ['ok'],
            array_map(static fn ($t) => $t->name, $client->listTools()),
            'a server listed BEFORE the offender was started even by the broken code, so this '
            . 'failing means something worse than the original defect',
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * Drive `startServers()` and require it to REPORT — every row here builds a
     * config with an entry that cannot start, and the report is what reaches the
     * operator's `error_log()` and the transcript through
     * {@see \SugarCraft\Crush\Cli\Bootstrap::mcpClient()}.
     *
     * ⚠️ THE ASSERTION THAT MATTERS IS THE ONE AFTER THIS CALL, NOT THIS CALL.
     * Reporting was never the defect; the defect was that reporting ABORTED the
     * loop, so the servers that could have started did not. A row that only
     * checked for the exception would pass against the original code.
     *
     * Only a config this class cannot BUILD is reported here. A server whose
     * `start()` fails is skipped in silence, and a mistyped tool inside an
     * otherwise fine `tools/list` is not a start failure at all.
     */
    private function assertStartFailed(McpClient $client): void
    {
        try {
            $client->startServers();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                'could not be built',
                $e->getMessage(),
                'startServers() threw something other than its own aggregate report',
            );

            return;
        }

        $this->fail(
            'startServers() reported nothing for a config containing an entry that cannot '
            . 'start. Bootstrap::mcpClient() drives its error_log() and transcript diagnostic '
            . 'off this throw — swallowing the failure trades one defect for another.',
        );
    }

    /** @return array<string, string> */
    private function httpEntry(): array
    {
        return ['type' => 'http', 'url' => 'http://mock.invalid/rpc'];
    }

    private function handshake(): Response
    {
        return new Response(200, [], (string) json_encode(['jsonrpc' => '2.0', 'id' => 0, 'result' => []]));
    }

    /**
     * A `tools/list` reply carrying whatever literal the caller supplies, so a
     * row can produce replies no factory in this tree would build.
     */
    private function toolsList(string $toolsJson): Response
    {
        return new Response(200, [], '{"jsonrpc":"2.0","id":1,"result":{"tools":' . $toolsJson . '}}');
    }

    /**
     * @param array<string, array<string, mixed>> $servers
     * @param list<Response|\Throwable> $responses
     */
    private function clientFor(array $servers, array $responses): McpClient
    {
        $path = sys_get_temp_dir() . '/r57b_mcp_isolation_' . getmypid() . '_' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, (string) json_encode(['mcpServers' => $servers]));
        $this->configs[] = $path;

        // `unrestricted: true` because these rows are about startServers(), not
        // about McpRouter: with no agent preset attached listTools() fails CLOSED
        // and would answer `[]` for every row here, defect or no defect.
        return new McpClient(
            $path,
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
            null,
            true,
        );
    }
}
