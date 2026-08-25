<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\McpMessage;

/**
 * {@see McpMessage::toJson()} IS THE WIRE. {@see McpMessage::toArray()} IS AN
 * INSPECTION VIEW. THE TWO HAVE NEVER BEEN THE SAME THING.
 *
 * E479 recorded that `toArray()` "NOW emits a key that is not JSON-RPC", the
 * `resultSet` sentinel round 55 added, and asked whether the method is a wire
 * serialiser or a debug view. The premise of the "now" is FALSE, and the tree
 * says so rather than an argument: `git log -L` on the method shows
 * `isNotification` — equally not a JSON-RPC key — present in the very commit
 * that created the file (`790e9464f`), long before the sentinel existed
 * (`f2f9f6985`). And that is not the only way the shape is not the wire:
 * `toArray()` emits `id`, `method`, `params`, `result` AND `error`
 * unconditionally, so a plain request comes out carrying a null `result` beside
 * a null `error` — a pair JSON-RPC 2.0 does not permit in one message at all.
 *
 * So `resultSet` did not change this method's character. It was an inspection
 * view from its first line, it still is, and this file says so in the one place
 * a reader will look and pins it so the answer stays given.
 *
 * ⚠️ NOTHING HERE HARD-CODES WHICH KEYS ARE WIRE KEYS. The JSON-RPC key set is
 * DERIVED from what `toJson()` actually emits across a corpus of message shapes,
 * so the rows below survive a legal addition to the protocol surface and red on
 * an illegal one. A list written out here would have to be maintained by hand
 * and would be exactly as trustworthy as the sentence E479 got wrong.
 *
 * THE ONE THING NOT TO DO, and E479 says it too: do not "fix" `toArray()` by
 * copying `toJson()`'s shape. {@see \SugarCraft\Crush\MCP\StdioMcpServer::start()}
 * reads `parseTools($listResponse->toArray())`, which wants `['result']['tools']`
 * — a serialiser that dropped an absent `result` would take the tool list with
 * it. That is pinned here as well.
 */
final class McpMessageWireShapeTest extends TestCase
{
    /**
     * One message of every shape the factories can build, plus the two the
     * parser can build that the factories cannot.
     *
     * @return array<string, McpMessage>
     */
    private function corpus(): array
    {
        $nullResult = McpMessage::parse('{"jsonrpc":"2.0","id":"1","result":null}');
        self::assertInstanceOf(McpMessage::class, $nullResult, 'a legal null-result reply no longer parses');

        $scalarResult = McpMessage::parse('{"jsonrpc":"2.0","id":"2","result":7}');
        self::assertInstanceOf(McpMessage::class, $scalarResult, 'a legal scalar result no longer parses');

        return [
            'request' => McpMessage::request('1', 'tools/call', ['name' => 'x']),
            'request-no-params' => McpMessage::request('2', 'tools/list', null),
            'notification' => McpMessage::notification('initialized', null),
            'success' => McpMessage::success('3', ['tools' => []]),
            'success-null' => $nullResult,
            'success-scalar' => $scalarResult,
            'error' => McpMessage::error('4', -32601, 'Method not found'),
        ];
    }

    /**
     * Every key `toJson()` puts on the wire, across the whole corpus.
     *
     * @return list<string>
     */
    private function wireKeys(): array
    {
        $keys = [];
        foreach ($this->corpus() as $label => $message) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($message->toJson(), true);
            $this->assertIsArray($decoded, "toJson() did not produce a JSON object for the {$label} shape");
            $keys = array_merge($keys, array_keys($decoded));
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * THE POSITIVE COMPONENT, and it is here because every other row in this
     * file asserts that something is ABSENT from the wire.
     *
     * `assertNotContains('resultSet', $wireKeys)` also passes in a tree where
     * `wireKeys()` returns nothing at all — a corpus that stopped building, a
     * `toJson()` that started returning `'{}'`. This row requires the derived set
     * to be a real JSON-RPC surface before any absence below is worth reading.
     */
    public function testTheDerivedWireKeySetIsARealOne(): void
    {
        $wire = $this->wireKeys();

        $this->assertContains('jsonrpc', $wire, 'toJson() is not emitting the protocol version, so nothing derived from it means anything');
        $this->assertContains('method', $wire, 'no shape in the corpus produced a method');
        $this->assertContains('result', $wire, 'no shape in the corpus produced a result');
        $this->assertContains('error', $wire, 'no shape in the corpus produced an error');
        $this->assertContains('id', $wire, 'no shape in the corpus produced an id');
        $this->assertContains(
            'params',
            $wire,
            'no shape in the corpus produced params. This assertion was missing, and its absence '
            . 'was a hole rather than an omission: `params` is the only wire key whose loss is '
            . 'silent everywhere else in this file — the absence rows below get SMALLER when a '
            . 'key vanishes, and the superset row stays true — so a toJson() that stopped '
            . 'emitting params would send every request with its arguments stripped and leave '
            . 'this file green',
        );

        // AND ON ONE SHAPE, so "the key appears somewhere in the corpus" cannot
        // be satisfied by a different message than the one that needs it.
        $this->assertSame(
            ['name' => 'x'],
            (json_decode(McpMessage::request('1', 'tools/call', ['name' => 'x'])->toJson(), true) ?? [])['params'] ?? null,
            'a request built WITH params did not put them on the wire',
        );
    }

    /**
     * THE WIRE STAYS CLEAN. Whatever `toArray()` carries, `toJson()` carries only
     * keys JSON-RPC 2.0 defines — which is the half of E479 that would have been
     * a real defect had it been true of `toJson()`.
     */
    public function testNothingThatIsNotJsonRpcReachesTheWire(): void
    {
        $wire = $this->wireKeys();

        // THE CONTROL, IN THIS ROW RATHER THAN THE SIBLING ONE. `array_diff()`
        // against an empty derived set is `[]`, so the assertion below is
        // satisfied by a `wireKeys()` that has stopped finding anything at all.
        // {@see testTheDerivedWireKeySetIsARealOne()} covers that for the FILE;
        // it does not cover it for this ROW, and a reader deleting that method
        // would take this row's only positive component with it.
        $this->assertContains('jsonrpc', $wire, 'the derived wire set is not a real one, so the absence below means nothing');
        $this->assertGreaterThan(3, count($wire), 'the derived wire set collapsed; nothing absent from it is evidence');

        $this->assertSame(
            [],
            array_values(array_diff($wire, ['jsonrpc', 'id', 'method', 'params', 'result', 'error'])),
            'toJson() put a key on the wire that JSON-RPC 2.0 does not define; toJson() is what '
            . 'frames outgoing messages, so anything extra here is sent to a real peer',
        );
    }

    /**
     * AND `toArray()` IS A STRICTLY WIDER, DIFFERENTLY-SHAPED THING — which is
     * what makes it an inspection view rather than a serialiser with a bug.
     *
     * Both directions are asserted. The extra keys make it not-the-wire; that
     * every wire key is still present makes it a SUPERSET rather than merely a
     * different thing, which is the property {@see \SugarCraft\Crush\MCP\StdioMcpServer}
     * relies on when it reads `['result']` back out.
     */
    public function testToArrayIsAWiderViewThanTheWireAndNotTheWire(): void
    {
        $extra = [];
        foreach ($this->corpus() as $label => $message) {
            /** @var array<string, mixed> $decoded */
            $decoded = (array) json_decode($message->toJson(), true);
            $missing = array_diff(array_keys($decoded), array_keys($message->toArray()));

            $this->assertSame(
                [],
                array_values($missing),
                "the {$label} shape put a key on the wire that toArray() does not carry, so the "
                . 'inspection view is no longer a superset and a reader of it would miss part of '
                . 'the message',
            );

            $extra = array_merge($extra, array_diff(array_keys($message->toArray()), array_keys($decoded)));
        }
        $extra = array_values(array_unique($extra));
        sort($extra);

        $this->assertNotSame(
            [],
            $extra,
            'toArray() and toJson() now agree key-for-key on every shape. If that was deliberate, '
            . 'this file is the wrong description of toArray() and its doc-block needs rewriting '
            . 'rather than this assertion relaxing — and check parseTools() still finds result',
        );
        $this->assertContains(
            'isNotification',
            $extra,
            'isNotification is the key that has been in toArray() since the file was created and '
            . 'is the evidence that this method was never a wire serialiser',
        );
    }

    /**
     * THE OLDER HALF OF THE SAME POINT, stated on one shape so it cannot be read
     * as an accident of the corpus: a plain REQUEST comes out of `toArray()`
     * carrying a null `result` beside a null `error`.
     *
     * JSON-RPC 2.0 permits neither on a request and forbids the pair on any
     * message, so this alone settles what `toArray()` is — and it predates the
     * `resultSet` sentinel by the whole life of the file.
     */
    public function testAPlainRequestComesOutOfToArrayCarryingAResultAndAnError(): void
    {
        $array = McpMessage::request('1', 'tools/list', null)->toArray();

        $this->assertArrayHasKey('result', $array);
        $this->assertArrayHasKey('error', $array);
        $this->assertNull($array['result']);
        $this->assertNull($array['error']);

        // Decoded rather than compared byte-for-byte: `json_encode()` escapes the
        // slash in `tools/list`, and a row that asserted the raw string would be
        // about that escaping rather than about which keys are present.
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => '1', 'method' => 'tools/list'],
            json_decode(McpMessage::request('1', 'tools/list', null)->toJson(), true),
            'the wire form grew a key; a request carrying result or error is not a request',
        );
    }

    /**
     * AND THE ONE `src/` CONSUMER STILL GETS WHAT IT READS.
     *
     * `StdioMcpServer::start()` ends with `parseTools($listResponse->toArray())`,
     * which reaches for `['result']['tools']`. Any tidy-up of `toArray()` that
     * makes it conditional the way `toJson()` is takes the tool list with it, and
     * a server with no tools looks exactly like a server that answered.
     */
    public function testTheOneConsumerOfToArrayStillFindsItsToolList(): void
    {
        $listResponse = McpMessage::success('7', ['tools' => [['name' => 'grep', 'description' => 'd']]]);
        $array = $listResponse->toArray();

        $this->assertArrayHasKey('result', $array);
        $this->assertIsArray($array['result']);
        $this->assertSame([['name' => 'grep', 'description' => 'd']], $array['result']['tools']);
    }

    /**
     * AND THE SENTINEL SURVIVES THE TRIP INTO THE ARRAY, which is the one thing
     * the inspection view is FOR that the object gets for free.
     *
     * ⚠️ THIS ROW EXISTS BECAUSE A MUTATION SURVIVED. Deleting
     * `'resultSet' => $this->resultSet` from `toArray()` left every McpMessage,
     * MCP and ClaudeCodeMcpClient suite in this tree green — 394 tests, 1276
     * assertions, rc 0. Nothing pinned it anywhere. The row above deliberately
     * does not: it asserts the extra-key set contains `isNotification`, because
     * `isNotification` is the EVIDENCE that this method never was the wire, and
     * a row that also demanded `resultSet` would have muddled the two claims.
     * So the sentinel gets its own row and gets it in the shape that matters.
     *
     * BOTH POLARITIES, because a `resultSet` key that is always true is worth
     * nothing: `result => null` in this array carries exactly the ambiguity the
     * sentinel exists to resolve — a peer that sent `"result": null` and a peer
     * that sent no `result` at all are the SAME array without it — so the two
     * shapes have to disagree here or the key is decoration.
     */
    public function testTheSentinelSurvivesIntoTheInspectionViewInBothPolarities(): void
    {
        $sent = McpMessage::parse('{"jsonrpc":"2.0","id":"1","result":null}');
        $absent = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":-1,"message":"no"}}');

        $this->assertInstanceOf(McpMessage::class, $sent);
        $this->assertInstanceOf(McpMessage::class, $absent);

        $sentArray = $sent->toArray();
        $absentArray = $absent->toArray();

        $this->assertArrayHasKey(
            'resultSet',
            $sentArray,
            'toArray() dropped the sentinel, so a consumer reading the array cannot tell a peer '
            . 'that sent "result": null from one that sent no result key at all — which is the '
            . 'ambiguity the sentinel was added to resolve',
        );
        $this->assertTrue($sentArray['resultSet'], 'a present null result reported itself absent');
        $this->assertNull($sentArray['result']);

        $this->assertFalse(
            $absentArray['resultSet'],
            'an absent result reported itself present, so the sentinel is always-true and the '
            . 'row above is satisfied by a constant',
        );
        $this->assertNull($absentArray['result']);

        $this->assertSame(
            $sentArray['result'],
            $absentArray['result'],
            'the two shapes differ in `result` as well, so this row is not in fact about the '
            . 'sentinel — check the fixtures',
        );
    }

    /**
     * E478 — THE SECOND CONSUMER OF THE WIDENING, PINNED RATHER THAN ASSERTED
     * ABOUT IN PROSE.
     *
     * `McpMessage::parse()` has TWO callers, not one:
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer::readResponse()} and
     * {@see ClaudeCodeMcpClient::readMessages()}. Every artefact of round 55
     * discussed `resultSet` as though the first were the only one, so this
     * method's output grew a shape nobody had looked at: a line such as
     * `{"jsonrpc":"2.0","id":"1","result":null}` that `parse()` previously
     * DROPPED now arrives as an element.
     *
     * It is benign today — the class never reads `->result` anywhere — but "it
     * is benign because nothing reads it" is a claim with a shelf life, and the
     * next person to give this class a result-reading path should find the shape
     * already described rather than re-derive it from the sibling.
     *
     * The DISCRIMINATOR row is the second one: `{"jsonrpc":"2.0"}` is still
     * dropped. Without it a `parse()` that accepted everything would pass.
     */
    public function testANullResultReplyNowReachesReadMessagesAndAnEmptyEnvelopeStillDoesNot(): void
    {
        $dir = sys_get_temp_dir() . '/sc_mcpwire_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $script = $dir . '/emitter.php';
        file_put_contents(
            $script,
            '<?php'
            . ' fwrite(STDOUT, \'{"jsonrpc":"2.0","id":"1","result":null}\' . "\n");'
            . ' fwrite(STDOUT, \'{"jsonrpc":"2.0"}\' . "\n");'
            . ' fwrite(STDOUT, \'{"jsonrpc":"2.0","id":"2","result":7}\' . "\n");'
            . ' fflush(STDOUT); $e = microtime(true) + 5; while (microtime(true) < $e) { usleep(20000); }',
        );

        $client = new ClaudeCodeMcpClient(PHP_BINARY, [$script]);

        try {
            $client->connect();

            $seen = [];
            for ($i = 0; $i < 60 && \count($seen) < 2; $i++) {
                foreach ($client->readMessages() as $message) {
                    $seen[(string) $message->id] = $message;
                }
                usleep(20000);
            }

            $this->assertArrayHasKey(
                '1',
                $seen,
                'a legal "result": null reply did not reach readMessages(), so the widening did '
                . 'not in fact reach this consumer and the entry describing it is wrong',
            );
            $this->assertTrue($seen['1']->resultSet, 'the sentinel did not survive the trip');
            $this->assertNull($seen['1']->result);

            $this->assertArrayHasKey('2', $seen, 'the control: a scalar result must also arrive');
            $this->assertSame(7, $seen['2']->result);

            $this->assertCount(
                2,
                $seen,
                'an envelope with no method, no error and no result key reached the caller. It is '
                . 'not a request, not a response and not a notification, and letting it through '
                . 'hands a matcher an object with nothing to match on',
            );
        } finally {
            $client->disconnect();
            @unlink($script);
            @rmdir($dir);
        }
    }
}
