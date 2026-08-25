<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\StdioMcpServer;
use SugarCraft\Crush\McpMessage;

/**
 * A JSON-RPC `result` IS ANY JSON VALUE, and this file is the fixture set that
 * says so for every type it can legally take.
 *
 * {@see McpMessage}'s constructor typed `$result` as `?array`, while
 * {@see McpMessage::parse()} handed it `json_decode($raw, true)['result']`
 * unchecked. MEASURED on this host (PHP 8.3.6) by feeding
 * `{"jsonrpc":"2.0","id":"1","result":<v>}` to `parse()` once per value:
 *
 *     v = true  false  "s"  5  1.5  ->  TypeError: argument #4 ($result)
 *     v = []    {"a":1}             ->  parsed
 *     v = null                      ->  null (rejected — see below)
 *
 * That is not a swallowed warning. `@` does not suppress a `TypeError`, and the
 * throw escaped further than the one message: {@see \SugarCraft\Crush\MCP\McpClient}
 * wraps `start()` in `catch (\RuntimeException)` precisely so that "a single
 * unreachable/misbehaving server must not abort loading the rest", and a
 * `TypeError` is not a `RuntimeException`. One conforming server answering
 * `initialize` with `"result": true` therefore took down the whole MCP
 * subsystem for the session.
 *
 * WHY THESE ARE NOT IN `tests/McpMessageTest.php`: that file covers the
 * envelope's happy path with object `result`s. This one exists to hold the
 * TYPE MATRIX together with the {@see StdioMcpServer::callTool()} consumer that
 * the widening moved the crash onto — the fix is only whole when both halves
 * are pinned in the same place.
 */
final class McpMessageResultTypeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_mcp_resulttype_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
        file_put_contents($this->tempDir . '/scalar.php', self::SCALAR_RESULT_SERVER);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    // =========================================================================
    // The type matrix
    // =========================================================================

    /**
     * ONE FIXTURE PER JSON TYPE `result` CAN HOLD. Each row is the literal that
     * goes into the wire text and the value `->result` must come back as.
     *
     * `assertSame()` on the value, not merely "did not throw": a `parse()` that
     * silently coerced every scalar to `null` — or to `[]` — would satisfy "no
     * exception" while destroying the payload, and the payload is the whole
     * point of the field.
     *
     * ZERO IS A ROW BECAUSE THIS ALPHABET WAS ONCE WRITTEN TO THE SHAPES
     * ALREADY KNOWN. Its first cut held three non-zero numbers and no zero, and
     * a falsy-coalescing bug in the {@see StdioMcpServer::callTool()} consumer
     * that erased exactly `0` therefore passed every row. A number alphabet
     * without a zero cannot see a falsiness defect; see
     * {@see testAZeroResultReachesTheModelAsZeroAndNotAsEmptyText()}.
     *
     * `{}` AND `[]` ARE ONE ROW IN THE SECOND CONSUMER, NOT TWO, and the row
     * names promise slightly more than that. `json_decode('{}', true)` and
     * `json_decode('[]', true)` are both PHP `[]`, so the parse consumer really
     * does distinguish the two WIRE forms — it is handed the literal — while the
     * round-trip consumer is handed only the decoded value and emits `[]` for
     * both. The `{}` wire form is therefore never round-tripped, and it cannot
     * be without a `stdClass`, which `parse()` could not give back anyway
     * (`json_decode(..., true)` never produces one). Recorded rather than
     * papered over: the gap is in what the row NAME implies, not in the
     * behaviour.
     *
     * FLOAT ZERO IS DELIBERATELY NOT A ROW HERE, and the reason is JSON's own
     * number model rather than anything this package does: `json_encode(0.0)`
     * is `"0"` on PHP 8.3.6 with the default `serialize_precision=-1`, so the
     * round-trip consumer below would legitimately get `int(0)` back and an
     * `assertSame()` against `0.0` would fail on a correct encoder. Float zero
     * is covered where it can actually occur — off the wire, through
     * `json_decode()` — in the dedicated test named above.
     *
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function legalResultTypes(): iterable
    {
        yield 'boolean true' => ['true', true];
        yield 'boolean false' => ['false', false];
        yield 'integer' => ['5', 5];
        yield 'negative integer' => ['-17', -17];
        yield 'zero' => ['0', 0];
        yield 'float' => ['1.5', 1.5];
        yield 'string' => ['"pong"', 'pong'];
        yield 'empty string' => ['""', ''];
        yield 'empty array' => ['[]', []];
        yield 'list' => ['[1,2,3]', [1, 2, 3]];
        yield 'object' => ['{"a":1}', ['a' => 1]];
        yield 'empty object' => ['{}', []];
    }

    /**
     * @dataProvider legalResultTypes
     */
    public function testParseAcceptsEveryJsonTypeAResultMayLegallyHold(string $literal, mixed $expected): void
    {
        $message = McpMessage::parse('{"jsonrpc":"2.0","id":"1","result":' . $literal . '}');

        $this->assertNotNull(
            $message,
            'parse() rejected a conforming JSON-RPC response whose result was ' . $literal,
        );
        $this->assertSame(
            $expected,
            $message->result,
            'result ' . $literal . ' did not survive parse() intact',
        );
        $this->assertSame('1', $message->id);
        $this->assertTrue($message->isResponse());
        $this->assertFalse($message->isError());
    }

    /**
     * `"result": null` IS REJECTED, DELIBERATELY, and this pins the decision so
     * the next reader does not "fix" it into the widening above.
     *
     * With `method`, `error` and `result` all null there is no discriminator
     * left in the decoded array: the message is indistinguishable from
     * `{"jsonrpc":"2.0"}` and from a reply with no `result` key at all, and this
     * class carries no "was the key present" sentinel. Telling them apart needs
     * a paired `bool $resultSet` — the convention this repo already uses for
     * nullable state — threaded through `toJson()` and `StdioMcpServer`'s two
     * `result === null` tests, which is a follow-up, not a line.
     *
     * THE SECOND ASSERTION IS THE CONTROL. `assertNull()` alone is satisfied by
     * a `parse()` that has been deleted, or that rejects everything — and
     * `false` is the neighbouring falsy value most likely to be swept up by a
     * sloppier guard. Requiring `false` through the SAME call proves the
     * rejection is a decision about `null` and not the instrument being dead.
     */
    public function testANullResultIsRejectedWhileTheAdjacentFalseIsNot(): void
    {
        $this->assertNull(
            McpMessage::parse('{"jsonrpc":"2.0","id":"1","result":null}'),
            'a result of null has no discriminator left and is rejected on purpose',
        );

        $stillParses = McpMessage::parse('{"jsonrpc":"2.0","id":"1","result":false}');
        $this->assertNotNull(
            $stillParses,
            'parse() rejected result:false too, so the null rejection above is not a decision '
            . 'about null — the parser is refusing everything and this file proves nothing',
        );
        $this->assertFalse($stillParses->result);
    }

    /**
     * The factory takes the same domain as the parser. `success()` was untyped
     * (`$result` with no declaration) in front of a `?array` constructor, so it
     * advertised "anything" and threw on most of it.
     *
     * @dataProvider legalResultTypes
     */
    public function testSuccessRoundTripsEveryLegalResultTypeThroughJson(string $literal, mixed $expected): void
    {
        $reparsed = McpMessage::parse(McpMessage::success('9', $expected)->toJson());

        $this->assertNotNull($reparsed, 'success(' . $literal . ') did not survive toJson()+parse()');
        $this->assertSame($expected, $reparsed->result);
    }

    /**
     * `toArray()` carries the widened value out too — the shape
     * {@see StdioMcpServer::parseTools()} reads.
     */
    public function testToArrayCarriesANonArrayResultThrough(): void
    {
        $array = McpMessage::success('3', true)->toArray();

        $this->assertTrue($array['result']);
    }

    // =========================================================================
    // The consumer the widening moved the crash onto
    // =========================================================================

    /**
     * WIDENING `$result` TO `mixed` RELOCATES THE CRASH RATHER THAN CLOSING IT,
     * UNLESS `callTool()` IS FIXED TOO — that method is declared `: array` and
     * returned `$response->result` directly, so `"result": true` simply became a
     * `TypeError` on the return type instead of on the constructor. This drives
     * a REAL server child, end to end, so the two halves cannot drift apart.
     *
     * The wrap shape is `{content: [{type: text, …}]}` because that is what
     * {@see \SugarCraft\Crush\Tools\McpToolBridge::renderContent()} renders
     * verbatim: a misbehaving server's scalar reaches the model as its own text
     * rather than as an exception that costs the turn.
     *
     * Object results are asserted in the same test on purpose — a `callTool()`
     * that wrapped EVERYTHING would pass the scalar rows while breaking every
     * conforming server there is.
     */
    public function testCallToolWrapsAScalarResultAndLeavesAnObjectResultAlone(): void
    {
        $server = new StdioMcpServer(
            name: 'scalar',
            command: PHP_BINARY,
            args: [$this->tempDir . '/scalar.php'],
            env: [],
            startTimeoutSeconds: 5.0,
        );

        $server->start();

        try {
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => 'true']]],
                $server->callTool('boolean', []),
                'a boolean result must reach the model as readable text, not as a TypeError',
            );
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => '42']]],
                $server->callTool('number', []),
            );
            // A string result passes through UNQUOTED — json_encode would hand
            // the model `"plain text"` with the quotes as part of the answer.
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => 'plain text']]],
                $server->callTool('string', []),
            );
            // THE CONTROL: the conforming shape is returned untouched.
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => 'pong']], 'isError' => false],
                $server->callTool('object', []),
                'a spec-shaped object result must be returned as-is; wrapping everything '
                . 'would pass the scalar rows above and break every real server',
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * ZERO IS THE ONE SCALAR A FALSY TEST ERASES, and this pins both spellings
     * of it end to end.
     *
     * The wrap above first read
     * `json_encode($response->result) ?: ''`. `json_encode(0)` is the string
     * `"0"`, which is FALSY in PHP, so `?:` replaced a legal `"result": 0` with
     * an empty string and the tool result reached the model carrying nothing.
     * `0.0` went the same way, because `json_encode(0.0)` is also `"0"`. Every
     * other scalar survived, which is precisely why the first alphabet — three
     * non-zero numbers, no zero — could not see it.
     *
     * BOTH SPELLINGS, and they are genuinely different paths rather than the
     * same one twice: `json_decode()` yields `int(0)` for the literal `0` and
     * `float(0)` for the literal `0.0`, and only the second exercises
     * `json_encode()`'s float branch. The fixture server emits the float case
     * as raw text for the reason given in its own comment.
     *
     * THE NON-EMPTY CONTROL is the `number` row: a wrap mutated to return `''`
     * unconditionally would satisfy an assertion that only ever looked at zero.
     */
    public function testAZeroResultReachesTheModelAsZeroAndNotAsEmptyText(): void
    {
        $server = new StdioMcpServer(
            name: 'scalar',
            command: PHP_BINARY,
            args: [$this->tempDir . '/scalar.php'],
            env: [],
            startTimeoutSeconds: 5.0,
        );

        $server->start();

        try {
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => '0']]],
                $server->callTool('zero', []),
                'an integer 0 result must reach the model as "0"; an empty text here means a '
                . 'falsy test (`json_encode($r) ?: \'\'`) has eaten the payload',
            );
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => '0']]],
                $server->callTool('zerofloat', []),
                'a float 0.0 result must reach the model as "0" for the same reason',
            );
            // THE CONTROL: a wrap that returned '' for everything would pass
            // both rows above.
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => '42']]],
                $server->callTool('number', []),
                'the non-zero control must still carry its own digits',
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * The handshake survives it too. `start()` reads `initialize`'s reply
     * through the same `parse()`, and its failure is not caught as a
     * `RuntimeException` anywhere above it — see this class's doc-block.
     */
    public function testTheHandshakeSurvivesAServerWhoseInitializeResultIsScalar(): void
    {
        file_put_contents($this->tempDir . '/scalar-init.php', self::SCALAR_INITIALIZE_SERVER);

        $server = new StdioMcpServer(
            name: 'scalar-init',
            command: PHP_BINARY,
            args: [$this->tempDir . '/scalar-init.php'],
            env: [],
            startTimeoutSeconds: 5.0,
        );

        $server->start();

        try {
            // It came up, and `tools/list` still parsed: a scalar `initialize`
            // result is odd but not fatal, which is the whole claim.
            $this->assertCount(1, $server->listTools());
            $this->assertSame('ping', $server->listTools()[0]->name);
        } finally {
            $server->stop();
        }
    }

    // =========================================================================
    // Fixtures and helpers
    // =========================================================================

    /**
     * Handshakes normally, then answers `tools/call` with whatever type the
     * requested tool name asks for.
     */
    private const SCALAR_RESULT_SERVER = <<<'PHP'
        <?php
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $method = (string) ($msg['method'] ?? '');
            if ($method === 'initialize') {
                $result = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()];
            } elseif ($method === 'tools/list') {
                $result = ['tools' => [[
                    'name' => 'ping',
                    'description' => 'Answer with pong.',
                    'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                ]]];
            } else {
                $name = (string) ($msg['params']['name'] ?? '');
                if ($name === 'zerofloat') {
                    // RAW TEXT, not json_encode(): the encoder renders 0.0 as
                    // "0", so a float zero cannot be put on the wire through
                    // it at all. json_decode() is the only side of the pair
                    // that distinguishes 0.0 from 0, so the literal is spelled
                    // by hand to make the parent receive a genuine float.
                    echo '{"jsonrpc":"2.0","id":', json_encode((string) $msg['id']), ',"result":0.0}', "\n";
                    flush();
                    continue;
                }
                $result = match ($name) {
                    'boolean' => true,
                    'number' => 42,
                    'zero' => 0,
                    'string' => 'plain text',
                    default => ['content' => [['type' => 'text', 'text' => 'pong']], 'isError' => false],
                };
            }
            echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
            flush();
        }
        PHP;

    /** Answers `initialize` with a bare `true`, then handshakes normally. */
    private const SCALAR_INITIALIZE_SERVER = <<<'PHP'
        <?php
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $result = ((string) ($msg['method'] ?? '')) === 'initialize'
                ? true
                : ['tools' => [[
                    'name' => 'ping',
                    'description' => 'Answer with pong.',
                    'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                ]]];
            echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
            flush();
        }
        PHP;

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
