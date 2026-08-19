<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpServer;
use SugarCraft\Crush\MCP\McpTool;
use SugarCraft\Crush\Tools\McpToolBridge;

/**
 * {@see McpToolBridge} — the adapter that turns one MCP {@see McpTool} descriptor
 * into a {@see \SugarCraft\Crush\Tools\Tool} the model can call.
 *
 * NO REAL MCP SERVER IS INVOLVED and none may be: {@see McpServer} is an
 * interface, so the fake below is a mock injected into a real {@see McpClient}
 * through the same reflection route `tests/MCP/McpClientTest.php` uses. A suite
 * that needed `npx`, a node runtime or a network peer would be green only on the
 * machines that happened to have them.
 *
 * The client is built `unrestricted: true` because that is how
 * {@see \SugarCraft\Crush\Cli\Bootstrap::mcpClient()} builds the main agent's —
 * see that method for why the fail-closed default does not apply to an agent with
 * no preset.
 *
 * WHAT IS DELIBERATELY NOT HERE: reachability. That "an adapter exists" is not
 * "the model can call one" is the trap this bundle was briefed on, and it is
 * answered in {@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest} by
 * driving a real call through {@see \SugarCraft\Crush\Runtime}.
 */
final class McpToolBridgeTest extends TestCase
{
    // =========================================================================
    // Naming — the `mcp__<server>__<tool>` convention three other classes
    // already branch on
    // =========================================================================

    public function testTheWireNameIsServerAndToolUnderTheMcpPrefix(): void
    {
        $bridge = new McpToolBridge($this->clientWith(), $this->descriptor(server: 'github', name: 'create_issue'));

        $this->assertSame('mcp__github__create_issue', $bridge->name());
    }

    /**
     * A server key is free text in `.mcp.json`, and provider tool names are not.
     * The substitution is what stops one badly-named server rejecting the WHOLE
     * `chat/completions` request.
     */
    public function testCharactersAProviderWouldRejectAreSubstituted(): void
    {
        $bridge = new McpToolBridge(
            $this->clientWith(),
            $this->descriptor(server: 'my server.v2', name: 'do/thing'),
        );

        $this->assertSame('mcp__my_server_v2__do_thing', $bridge->name());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $bridge->name());
    }

    /**
     * A HYPHEN IS NOT SUBSTITUTED, and this is the assertion the character class
     * needs: hyphens are legal in every provider's tool-name grammar and
     * ubiquitous in real MCP server keys (`sequential-thinking`, `brave-search`),
     * so dropping `-` from the kept set would rewrite those names to underscores
     * silently — nothing about `mcp__sequential_thinking__foo` looks wrong. The
     * substitution test above passes either way, because none of its inputs
     * contain one.
     */
    public function testHyphensSurviveInBothTheServerKeyAndTheToolName(): void
    {
        $bridge = new McpToolBridge(
            $this->clientWith(),
            $this->descriptor(server: 'sequential-thinking', name: 'create-thought'),
        );

        $this->assertSame('mcp__sequential-thinking__create-thought', $bridge->name());
    }

    /**
     * And the SERVER-side name is NOT sanitized: the substitution is a wire-name
     * concern, and a tool really called `do/thing` has to be addressed by that
     * name over the protocol or the call cannot land.
     */
    /**
     * THE COLLISION THAT NEEDS NO SUBSTITUTION, measured rather than asserted in
     * prose: `__` is the separator AND a legal character in both segments, so two
     * different tools on two different servers have one wire name with nothing
     * rewritten. {@see McpToolBridge::sanitize()}'s note used to attribute
     * collisions solely to a substituted character, which made this the one source
     * it did not mention.
     *
     * Pinned rather than fixed — escaping the separator would rewrite every name
     * the model and every user-written permission rule already know, which is a
     * migration and is on the hardening backlog (E42). What this test does is stop
     * the docblock's claim and the code from drifting apart again.
     */
    public function testTheSeparatorIsAlsoALegalCharacterSoTwoDistinctToolsCanShareOneName(): void
    {
        $left = new McpToolBridge($this->clientWith(), $this->descriptor(server: 'a__b', name: 'c'));
        $right = new McpToolBridge($this->clientWith(), $this->descriptor(server: 'a', name: 'b__c'));

        $this->assertSame('mcp__a__b__c', $left->name());
        $this->assertSame($left->name(), $right->name());
        // ...and no character was substituted in either: this is not the
        // sanitisation collision the doc-block used to describe.
        $this->assertSame('a__b', $left->descriptor()->serverName);
        $this->assertSame('b__c', $right->descriptor()->name);
    }

    public function testTheCallUsesTheUnsanitizedServerSideToolName(): void
    {
        $server = $this->fakeServer();
        $server->expects($this->once())
            ->method('callTool')
            ->with('do/thing', ['a' => 1])
            ->willReturn(['content' => [['type' => 'text', 'text' => 'ok']]]);

        $bridge = new McpToolBridge(
            $this->clientWith($server, $this->descriptor(server: 'srv', name: 'do/thing')),
            $this->descriptor(server: 'srv', name: 'do/thing'),
        );

        $this->assertSame('ok', $bridge->execute(['a' => 1])->content());
    }

    public function testTheDescriptorIsReadableBack(): void
    {
        $descriptor = $this->descriptor();
        $bridge = new McpToolBridge($this->clientWith(), $descriptor);

        $this->assertSame($descriptor, $bridge->descriptor());
    }

    // =========================================================================
    // Description
    // =========================================================================

    public function testTheDescriptionNamesTheServerInFrontOfTheServersOwnText(): void
    {
        $bridge = new McpToolBridge(
            $this->clientWith(),
            $this->descriptor(description: 'Search the wiki.'),
        );

        $this->assertSame('[MCP srv] Search the wiki.', $bridge->description());
    }

    /**
     * A server that supplied no description still gets a non-empty one: every
     * consumer of the `Tool` contract assumes that, and an empty description is
     * what the model is given to choose the tool by.
     */
    public function testAnEmptyServerDescriptionFallsBackRatherThanBeingPassedThrough(): void
    {
        $bridge = new McpToolBridge($this->clientWith(), $this->descriptor(description: '   '));

        $this->assertSame('MCP tool "ping" on server "srv".', $bridge->description());
        $this->assertNotSame('', $bridge->description());
    }

    // =========================================================================
    // Schema normalisation
    // =========================================================================

    /**
     * A no-argument MCP tool declares nothing, and `'properties' => []` encodes
     * as the JSON ARRAY `[]` where JSON Schema requires an object — which SGLang
     * rejects for the whole request, not just the offending tool.
     */
    public function testAnEmptySchemaBecomesAValidNoArgumentObjectSchema(): void
    {
        $bridge = new McpToolBridge($this->clientWith(), $this->descriptor(schema: []));

        $schema = $bridge->inputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertSame([], $schema['required']);
        $this->assertSame('{}', json_encode($schema['properties']));
    }

    public function testAMissingRequiredListIsSuppliedAndPropertiesAreLeftAlone(): void
    {
        $schema = (new McpToolBridge($this->clientWith(), $this->descriptor(schema: [
            'type' => 'object',
            'properties' => ['note' => ['type' => 'string']],
        ])))->inputSchema();

        $this->assertSame(['note' => ['type' => 'string']], $schema['properties']);
        $this->assertSame([], $schema['required']);
    }

    /**
     * Keys this class does not know about are the SERVER's contract with its own
     * tool; dropping them would silently loosen it.
     */
    public function testUnknownSchemaKeysSurvive(): void
    {
        $schema = (new McpToolBridge($this->clientWith(), $this->descriptor(schema: [
            'type' => 'string',
            'additionalProperties' => false,
            '$defs' => ['x' => ['type' => 'integer']],
            'required' => ['note'],
        ])))->inputSchema();

        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['x' => ['type' => 'integer']], $schema['$defs']);
        $this->assertSame(['note'], $schema['required']);
        // ...but `type` is forced: a tool-call schema's root must be an object,
        // and the provider layer would otherwise be handed a `string` root.
        $this->assertSame('object', $schema['type']);
    }

    /**
     * THE SCOPE OF THIS METHOD IS THE ROOT, and that is asserted rather than left
     * to the doc-block, because the doc-block used to claim more than the code did
     * ("a bridge is a valid schema on its own", true only of a flat schema). A
     * NESTED no-argument object is a routine MCP shape and this method leaves it
     * exactly as the server sent it; the recursive correction lives at the
     * `json_encode()` boundary in
     * {@see \SugarCraft\Crush\Providers\Concerns\ToolSchema}, which is where the
     * encoding defect is, and is asserted in
     * {@see \SugarCraft\Crush\Tests\Providers\ToolSchemaEncodingTest}.
     */
    public function testANestedEmptyPropertiesIsLeftForTheProviderLayerNotCorrectedHere(): void
    {
        $schema = (new McpToolBridge($this->clientWith(), $this->descriptor(schema: [
            'type' => 'object',
            'properties' => ['opts' => ['type' => 'object', 'properties' => [], 'required' => []]],
            'required' => [],
        ])))->inputSchema();

        $this->assertSame([], $schema['properties']['opts']['properties']);
        $this->assertStringContainsString('"properties":[]', (string) json_encode($schema['properties']));
    }

    /**
     * A `required` OF THE WRONG TYPE USED TO BE DISCARDED. `!is_array()` meant
     * `"required": "note"` — one required argument written flat, which is the
     * mistake a hand-edited config makes — became `[]`, and the model was then
     * free to omit an argument the server genuinely requires. Two paragraphs of
     * the same doc-block say dropping a key "would silently loosen it".
     *
     * Every row of {@see McpToolBridge}'s stated rule, including the two that
     * LOSE a constraint, because a rule with untested rows is prose.
     *
     * @dataProvider requiredShapes
     */
    public function testEveryShapeOfRequiredIsCoercedToAListOfPropertyNames(
        mixed $sent,
        array $expected,
    ): void {
        $schema = (new McpToolBridge($this->clientWith(), $this->descriptor(schema: [
            'type' => 'object',
            'properties' => ['note' => ['type' => 'string']],
            'required' => $sent,
        ])))->inputSchema();

        $this->assertSame($expected, $schema['required']);
        // A LIST on the wire, not an object: a coercion that left index gaps
        // would encode as `{"0":…,"2":…}`, which is invalid JSON Schema for
        // `required` and is the same encoding defect `properties` has.
        $this->assertTrue(array_is_list($schema['required']));
        $this->assertStringStartsWith('[', (string) json_encode($schema['required']));
    }

    /** @return array<string, array{0: mixed, 1: list<string>}> */
    public static function requiredShapes(): array
    {
        return [
            'a bare string is one required argument' => ['note', ['note']],
            'a list of strings passes through' => [['a', 'b'], ['a', 'b']],
            'a number becomes its decimal spelling, because a JSON key is a string'
                => [['a', 7], ['a', '7']],
            'a float too' => [[1.5], ['1.5']],
            'a bool, a null and a nested value have no property-name reading'
                => [['a', true, null, ['b']], ['a']],
            'and dropping from the middle leaves no gap' => [[true, 'b', false], ['b']],
            'an int is not a list of one' => [7, []],
            'null is no constraint' => [null, []],
            'a bool is no constraint' => [true, []],
            'an empty list stays empty' => [[], []],
            'an object is read for its values by the same rule' => [['x' => 'y'], ['y']],
        ];
    }

    // =========================================================================
    // execute()
    // =========================================================================

    public function testTextContentPartsAreJoinedInOrder(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['content' => [
            ['type' => 'text', 'text' => 'first'],
            ['type' => 'text', 'text' => 'second'],
        ]]);

        $result = $this->bridgeOver($server)->execute([]);

        $this->assertSame("first\nsecond", $result->content());
        $this->assertFalse($result->isError());
    }

    /**
     * A non-text part is ANNOUNCED, not dropped: a tool whose whole answer was
     * one image would otherwise return the empty string, which reads to the model
     * as a successful call that produced nothing.
     */
    public function testANonTextPartIsAnnouncedByTypeRatherThanDropped(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['content' => [
            ['type' => 'image', 'data' => 'AAAA'],
            ['type' => 'text', 'text' => 'caption'],
        ]]);

        $result = $this->bridgeOver($server)->execute([]);

        $this->assertSame("[image]\ncaption", $result->content());
    }

    /**
     * `content: []` IS THE EXACT HAZARD THE NON-TEXT ANNOUNCE ABOVE CITES.
     * {@see McpToolBridge::renderContent()}'s doc-block justifies announcing an
     * image because a tool whose whole answer was one would "otherwise return the
     * empty string, which reads as success with no output" — and the empty list
     * produced precisely that, `isError` false, untested.
     */
    public function testAnEmptyContentListIsAnnouncedRatherThanReadingAsSilentSuccess(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['content' => []]);

        $result = $this->bridgeOver($server)->execute([]);

        $this->assertSame('[no content]', $result->content());
        $this->assertFalse($result->isError());
    }

    /**
     * ...and the OTHER way to render nothing, distinguished because the code can
     * actually tell them apart: parts that were present and every one of which
     * rendered to the empty string.
     */
    public function testPartsThatAllRenderEmptyAreAnnouncedAsEmptyRatherThanAsAbsent(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['content' => [['type' => 'text', 'text' => '']]]);

        $result = $this->bridgeOver($server)->execute([]);

        $this->assertSame('[empty content]', $result->content());
        $this->assertFalse($result->isError());
    }

    public function testTheSpecIsErrorFlagBecomesAnErrorResult(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn([
            'isError' => true,
            'content' => [['type' => 'text', 'text' => 'the repo is not clean']],
        ]);

        $result = $this->bridgeOver($server)->execute([]);

        $this->assertTrue($result->isError());
        $this->assertSame('the repo is not clean', $result->content());
    }

    /**
     * `isError` IS NOT ALWAYS A `bool` ON THE WIRE, and the strict `=== true`
     * this replaced read a server-reported FAILURE as the tool's ANSWER. Measured
     * on it: `isError: "true"` and `isError: 1` both came out `false` and the
     * failure text was handed to the model as the result.
     *
     * PHP truthiness is not the fix either — `(bool) "false"` is `true`, which
     * inverts the common spelling of the common case — so every shape is
     * interpreted explicitly and every shape is a row here.
     *
     * @dataProvider isErrorShapes
     */
    public function testEveryIsErrorSpellingIsInterpretedExplicitly(
        mixed $flag,
        bool $expected,
        bool $announced,
    ): void {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn([
            'isError' => $flag,
            'content' => [['type' => 'text', 'text' => 'the repo is not clean']],
        ]);

        $result = $this->bridgeOver($server)->execute([]);

        $this->assertSame($expected, $result->isError(), (string) json_encode($flag));

        // An UNREADABLE flag is a protocol violation, and announcing it is what
        // makes the misbehaving server nameable — a bare "treated as an error"
        // would leave the user with no idea which server or which value.
        $announced
            ? $this->assertStringContainsString('[unreadable isError:', $result->content())
            : $this->assertStringNotContainsString('unreadable', $result->content());
        // Whatever the server DID send survives the announcement.
        $this->assertStringContainsString('the repo is not clean', $result->content());
    }

    /** @return array<string, array{0: mixed, 1: bool, 2: bool}> */
    public static function isErrorShapes(): array
    {
        return [
            'true' => [true, true, false],
            'one' => [1, true, false],
            'the string one' => ['1', true, false],
            'the string true' => ['true', true, false],
            'the string TRUE, folded' => ['TRUE', true, false],
            'the string true with spaces, trimmed' => ["  true\n", true, false],
            'false' => [false, false, false],
            'zero' => [0, false, false],
            'the string zero' => ['0', false, false],
            'the string false' => ['false', false, false],
            'the empty string' => ['', false, false],
            'null' => [null, false, false],
            'a number that is neither' => [2, true, true],
            'a word that is neither' => ['maybe', true, true],
            'a list' => [[], true, true],
        ];
    }

    /**
     * ABSENT is not the same input as `null` — `array_key_exists()` tells them
     * apart and only the present-but-unreadable case may announce.
     */
    public function testAnAbsentIsErrorIsNotAnErrorAndAnnouncesNothing(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['content' => [['type' => 'text', 'text' => 'ok']]]);

        $result = $this->bridgeOver($server)->execute([]);

        $this->assertFalse($result->isError());
        $this->assertSame('ok', $result->content());
    }

    /**
     * The precedence that must not regress: `['error' => …]` errors even when an
     * `isError: false` sits beside it.
     */
    public function testAnExplicitErrorKeyWinsOverAFalseIsErrorFlag(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['isError' => false, 'error' => 'Tool call failed']);

        $this->assertTrue($this->bridgeOver($server)->execute([])->isError());
    }

    /**
     * The OTHER failure shape, and it has a different producer:
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer::callTool()} substitutes
     * `['error' => 'Tool call failed']` when no response arrives at all. Treated
     * as success, that string reaches the model as the tool's ANSWER.
     */
    public function testTheStdioNoResponseShapeBecomesAnErrorResult(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['error' => 'Tool call failed']);

        $result = $this->bridgeOver($server)->execute([]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Tool call failed', $result->content());
    }

    /**
     * A result in no shape this class knows is JSON-encoded rather than
     * summarised: showing the model the raw envelope beats inventing a reading
     * of it.
     */
    public function testAnUnrecognisedResultShapeIsHandedOverAsJson(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['structuredContent' => ['rows' => 2]]);

        $result = $this->bridgeOver($server)->execute([]);

        $this->assertSame('{"structuredContent":{"rows":2}}', $result->content());
        $this->assertFalse($result->isError());
    }

    /**
     * THE ONE THAT WOULD OTHERWISE COST THE TURN.
     * {@see McpClient::callTool()} throws for a server it does not hold, and a
     * transport can throw anything — so this must be an error RESULT naming the
     * tool, never an exception out of `execute()`.
     */
    public function testAThrowFromTheClientBecomesAnErrorResultNamingTheTool(): void
    {
        // A client with no servers at all: callTool() cannot find `srv` and
        // throws "Unknown MCP server: srv".
        $bridge = new McpToolBridge(new McpClient('/nonexistent/.mcp.json', unrestricted: true), $this->descriptor());

        $result = $bridge->execute([]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('mcp__srv__ping', $result->content());
        $this->assertStringContainsString('Unknown MCP server: srv', $result->content());
    }

    // =========================================================================
    // Routing — WHICH server the call lands on
    // =========================================================================

    /**
     * TWO SERVERS, ONE TOOL NAME, and the calls must not cross.
     *
     * `search` on two servers is utterly ordinary — a wiki and a ticket tracker,
     * very possibly differently credentialed — and both bridges get distinct,
     * valid wire names, so the model can and will address both. This bridge used
     * to call {@see McpClient::callToolByName()}, which matches on the tool NAME
     * alone and returns the FIRST server advertising it, so every `beta` call was
     * answered by `alpha`. Measured before the fix:
     *
     *     mcp__alpha__search  server=alpha  -> ANSWERED-BY:ALPHA
     *     mcp__beta__search   server=beta   -> ANSWERED-BY:ALPHA
     *
     * No name collision is involved; this is not the sanitisation cost documented
     * on `sanitize()`.
     */
    public function testTwoServersAdvertisingTheSameToolNameEachAnswerTheirOwnBridge(): void
    {
        $alpha = $this->fakeServer();
        $alpha->method('callTool')->willReturn(['content' => [['type' => 'text', 'text' => 'ANSWERED-BY:ALPHA']]]);
        $beta = $this->fakeServer();
        $beta->method('callTool')->willReturn(['content' => [['type' => 'text', 'text' => 'ANSWERED-BY:BETA']]]);

        $alphaTool = $this->descriptor(server: 'alpha', name: 'search');
        $betaTool = $this->descriptor(server: 'beta', name: 'search');
        $client = $this->clientWithServers(['alpha' => [$alpha, $alphaTool], 'beta' => [$beta, $betaTool]]);

        $alphaBridge = new McpToolBridge($client, $alphaTool);
        $betaBridge = new McpToolBridge($client, $betaTool);

        $this->assertSame('mcp__alpha__search', $alphaBridge->name());
        $this->assertSame('mcp__beta__search', $betaBridge->name());
        $this->assertSame('ANSWERED-BY:ALPHA', $alphaBridge->execute([])->content());
        $this->assertSame('ANSWERED-BY:BETA', $betaBridge->execute([])->content());
    }

    /**
     * ...and the server the bridge names is the one asked, even when the tool
     * name is unique — i.e. the routing is by SERVER, not "by server only when
     * disambiguation is needed". Asserted on the mocks' own expectations, so a
     * call landing on the wrong one is a failure rather than a coincidence.
     */
    public function testTheCallIsAddressedToTheDescriptorsOwnServer(): void
    {
        $wanted = $this->fakeServer();
        $wanted->expects($this->once())
            ->method('callTool')
            ->with('only_here', ['q' => 'x'])
            ->willReturn(['content' => [['type' => 'text', 'text' => 'ok']]]);
        $other = $this->fakeServer();
        $other->expects($this->never())->method('callTool');

        $wantedTool = $this->descriptor(server: 'wanted', name: 'only_here');
        $client = $this->clientWithServers([
            // `other` is FIRST in the map, so a name-only lookup that happened to
            // match would reach it before `wanted`.
            'other' => [$other, $this->descriptor(server: 'other', name: 'something_else')],
            'wanted' => [$wanted, $wantedTool],
        ]);

        $this->assertSame('ok', (new McpToolBridge($client, $wantedTool))->execute(['q' => 'x'])->content());
    }

    public function testATransportThrowBecomesAnErrorResultRatherThanEscaping(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willThrowException(new \LogicException('socket went away'));

        $result = $this->bridgeOver($server)->execute([]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('socket went away', $result->content());
    }

    /**
     * `toolCallId` follows the same convention every built-in uses (`$args['id']`
     * when present), and a non-string there must not become a TypeError inside
     * the result object.
     */
    public function testTheToolCallIdIsTakenFromArgsAndCoerced(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['content' => [['type' => 'text', 'text' => 'ok']]]);

        $this->assertSame('call_7', $this->bridgeOver($server)->execute(['id' => 'call_7'])->toolCallId());
        $this->assertSame('', $this->bridgeOver($server)->execute(['id' => 42])->toolCallId());
        $this->assertSame('', $this->bridgeOver($server)->execute([])->toolCallId());
    }

    /**
     * NOT {@see \SugarCraft\Crush\Tools\ParallelSafe}: a server-side tool's
     * effects are unknowable here, so it must be a barrier —
     * {@see \SugarCraft\Crush\Runtime::runsConcurrently()} treats anything that
     * does not implement the interface as one.
     */
    public function testABridgeIsNotParallelSafe(): void
    {
        $this->assertNotInstanceOf(
            \SugarCraft\Crush\Tools\ParallelSafe::class,
            new McpToolBridge($this->clientWith(), $this->descriptor()),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function descriptor(
        string $server = 'srv',
        string $name = 'ping',
        string $description = 'Answer with pong.',
        array $schema = ['type' => 'object', 'properties' => ['note' => ['type' => 'string']], 'required' => []],
    ): McpTool {
        return new McpTool(name: $name, description: $description, inputSchema: $schema, serverName: $server);
    }

    /** @return McpServer&\PHPUnit\Framework\MockObject\MockObject */
    private function fakeServer(): McpServer
    {
        return $this->createMock(McpServer::class);
    }

    private function bridgeOver(McpServer $server): McpToolBridge
    {
        $descriptor = $this->descriptor();

        return new McpToolBridge($this->clientWith($server, $descriptor), $descriptor);
    }

    /**
     * A real {@see McpClient} with $server injected under the descriptor's server
     * name, so `callToolByName()` can find it — the same reflection route
     * `tests/MCP/McpClientTest.php` uses, because `$servers` is populated only by
     * `startServers()` and nothing here may start a process.
     */
    private function clientWith(?McpServer $server = null, ?McpTool $descriptor = null): McpClient
    {
        $client = new McpClient('/nonexistent/.mcp.json', unrestricted: true);

        if ($server === null) {
            return $client;
        }

        $descriptor ??= $this->descriptor();
        $server->method('listTools')->willReturn([$descriptor]);

        $servers = new \ReflectionProperty($client, 'servers');
        $servers->setAccessible(true);
        $servers->setValue($client, [$descriptor->serverName => $server]);

        return $client;
    }

    /**
     * The same, for MORE THAN ONE server, so a routing assertion can say which of
     * them answered. Insertion order is preserved and is load-bearing in
     * {@see testTheCallIsAddressedToTheDescriptorsOwnServer()}.
     *
     * @param array<string, array{0: McpServer, 1: McpTool}> $servers name => [server, its one tool]
     */
    private function clientWithServers(array $servers): McpClient
    {
        $client = new McpClient('/nonexistent/.mcp.json', unrestricted: true);

        $injected = [];
        foreach ($servers as $name => [$server, $descriptor]) {
            $server->method('listTools')->willReturn([$descriptor]);
            $injected[$name] = $server;
        }

        $property = new \ReflectionProperty($client, 'servers');
        $property->setAccessible(true);
        $property->setValue($client, $injected);

        return $client;
    }
}
